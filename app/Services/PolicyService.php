<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\Policy;
use App\Models\Reminder;
use App\Support\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Multi-step policy writes. Each public method runs in a single database
 * transaction, so a policy is never persisted without its beneficiaries
 * (or with half of them) if any step fails.
 */
class PolicyService
{
    private const POLICY_FIELDS = [
        'policy_number', 'policy_owner_id', 'policy_insured_id', 'product_id', 'ape',
        'issued_date', 'mode_of_payment', 'sum_assured', 'status', 'policy_delivery_date',
        'is_orphan', 'remarks',
    ];

    /** Client details (besides the name) that can be entered on the Clients (policy) form. */
    public const CONTACT_FIELDS = ['birthdate', 'gender', 'email', 'mobile_number', 'address', 'occupation'];

    /** A role's name: creates a new client, or (when sent with a linked client) updates theirs. */
    public const NAME_FIELDS = ['first_name', 'middle_name', 'last_name'];

    public function __construct(private CoverageDocumentService $coverage, private LeadConverter $leads) {}

    public function create(array $data): Policy
    {
        return DB::transaction(function () use ($data) {
            $policy = Policy::create(Arr::only($this->resolveParties($data), self::POLICY_FIELDS));

            $this->syncBeneficiaries($policy, $data['beneficiaries'] ?? []);
            $this->syncFundTypes($policy, $data['fund_type_ids'] ?? []);

            return $policy;
        });
    }

    public function update(Policy $policy, array $data): Policy
    {
        return DB::transaction(function () use ($policy, $data) {
            $policy->update(Arr::only($this->resolveParties($data), self::POLICY_FIELDS));

            // Beneficiaries are only replaced when the payload includes them.
            if (array_key_exists('beneficiaries', $data)) {
                $this->syncBeneficiaries($policy, $data['beneficiaries'] ?? []);
            }

            // Fund types likewise.
            if (array_key_exists('fund_type_ids', $data)) {
                $this->syncFundTypes($policy, $data['fund_type_ids'] ?? []);
            }

            return $policy;
        });
    }

    /** Delete a client record (policy) with its beneficiaries, documents and coverage file. */
    public function delete(Policy $policy): void
    {
        DB::transaction(function () use ($policy) {
            // Delete through Eloquent so each removal is audited.
            $policy->beneficiaries()->get()->each->delete();
            $policy->documents()->get()->each(function (ClientDocument $document) {
                $document->delete();
                app(DocumentService::class)->deleteClientFiles($document);
            });
            $this->coverage->delete($policy);
            $policy->delete();
        });
    }

    /**
     * Delete a client and everything related to them: every policy they own or are
     * insured under (as delete() above), and their appointments, reminders and
     * email logs. Deleted through Eloquent so each removal is audited; the foreign keys
     * also cascade, as a safety net.
     */
    public function deleteClient(Client $client): void
    {
        DB::transaction(function () use ($client) {
            Policy::where('policy_owner_id', $client->id)->orWhere('policy_insured_id', $client->id)
                ->get()->each(fn (Policy $policy) => $this->delete($policy));

            $client->appointments()->get()->each->delete();
            Reminder::where('client_id', $client->id)->get()->each->delete();
            EmailLog::where('client_id', $client->id)->delete();

            $client->delete();
        });
    }

    /**
     * Make the policy's fund types exactly $ids. Link-table changes are not model
     * events, so a change is written to the audit log here.
     */
    public function syncFundTypes(Policy $policy, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $changes = $policy->fundTypes()->sync($ids);

        if ($changes['attached'] || $changes['detached']) {
            AuditLogger::record('updated', 'policies', $policy->id,
                ['fund_type_ids' => array_values(array_diff(array_merge($ids, $changes['detached']), $changes['attached']))],
                ['fund_type_ids' => $ids],
                "Changed fund types of policy {$policy->policy_number}");
        }
    }

    /** Upsert one beneficiary (with their details) on a policy. */
    public function saveBeneficiary(Policy $policy, array $item, ?Beneficiary $existing = null): Beneficiary
    {
        $attributes = Arr::only($item, Beneficiary::DETAIL_FIELDS) + [
            'relationship' => $item['relationship'],
            'beneficiary_type' => $item['beneficiary_type'] ?? 'primary',
            // Not on the form any more: keep what a beneficiary already has.
            'designation' => $item['designation'] ?? $existing?->designation ?? 'revocable',
            'allocation_percentage' => $item['allocation_percentage'] ?? null,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return $policy->beneficiaries()->create($attributes);
    }

    /**
     * Make the policy's beneficiaries match $items exactly. An item with an id
     * updates that beneficiary; one without adds a new one; the rest are removed.
     */
    public function syncBeneficiaries(Policy $policy, array $items): void
    {
        $existing = $policy->beneficiaries()->get()->keyBy('id');
        $kept = [];

        foreach ($items as $item) {
            $current = isset($item['id']) ? $existing->get((int) $item['id']) : null;
            $kept[] = $this->saveBeneficiary($policy, $item, $current)->id;
        }

        $existing->except($kept)->each->delete();
    }

    /**
     * Turn the Policy Owner / Policy Insured parts of the payload into client ids.
     * A typed name always creates a new client; an id links an existing one, and
     * any contact details sent with it update that client. A lead picked as owner
     * is first converted into a client (and the lead removed). The two roles stay
     * separate fields — "same as owner" only copies the owner's id.
     */
    private function resolveParties(array $data): array
    {
        if (! empty($data['policy_owner_lead_id'])) {
            $lead = Lead::findOrFail($data['policy_owner_lead_id']);
            $data['policy_owner_id'] = $this->leads->convert($lead, [
                'address' => $data['policy_owner']['address'] ?? null,
                'is_policy_owner' => true,
            ])->id;
        }

        $data['policy_owner_id'] = $this->resolveParty($data['policy_owner_id'] ?? null, $data['policy_owner'] ?? [], true);

        if (! empty($data['insured_same_as_owner'])) {
            $data['policy_insured_id'] = $data['policy_owner_id'];
        } else {
            $data['policy_insured_id'] = $this->resolveParty($data['policy_insured_id'] ?? null, $data['policy_insured'] ?? [], false);
        }

        return $data;
    }

    private function resolveParty(int|string|null $clientId, array $details, bool $asOwner): ?int
    {
        $clientId = $clientId ? (int) $clientId : null;
        $contact = Arr::only($details, self::CONTACT_FIELDS);
        $names = array_map(fn ($v) => is_string($v) ? (trim(preg_replace('/\s+/', ' ', $v)) ?: null) : $v, Arr::only($details, self::NAME_FIELDS));

        if ($clientId) {
            // Names edited on the form update the linked client too (a sent middle name may be cleared).
            $changes = $contact + array_filter($names, fn ($v, $k) => $v !== null || $k === 'middle_name', ARRAY_FILTER_USE_BOTH);
            // Update through Eloquent so the change is audited on the client.
            if ($changes !== []) {
                Client::findOrFail($clientId)->update($changes);
            }

            return $clientId;
        }

        if (empty($names['first_name'])) {
            return null;
        }

        return Client::create([
            'first_name' => $names['first_name'],
            'middle_name' => $names['middle_name'] ?? null,
            'last_name' => $names['last_name'],
            'is_policy_owner' => $asOwner,
        ] + $contact)->id;
    }
}
