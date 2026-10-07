<?php

namespace App\Http\Requests;

use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Policy;
use App\Rules\PersonName;
use App\Services\PolicyService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a policy, optionally with its beneficiaries in the same
 * (transactional) request.
 */
class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('policy_number')) {
            $this->merge(['policy_number' => strtoupper(trim((string) $this->input('policy_number')))]);
        }
    }

    public function rules(): array
    {
        $policy = $this->route('policy');

        return array_merge([
            'policy_number' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9\-\/]*$/', Rule::unique('policies', 'policy_number')->ignore($policy?->id)],
            // Each role is either an existing client (id) or a typed name that creates a new client.
            // Only clients flagged is_policy_owner may own a policy; any client may be insured.
            'policy_owner_id' => ['nullable', 'integer', 'required_without_all:policy_owner,policy_owner_lead_id', Rule::exists('clients', 'id')->where('is_policy_owner', true)],
            // Or a lead, who is converted into a client (Policy Owner) when the policy is saved.
            'policy_owner_lead_id' => ['nullable', 'integer', 'exists:leads,id', 'prohibits:policy_owner_id'],
            'policy_owner' => ['nullable', 'array'],
            'policy_owner.first_name' => ['required_without_all:policy_owner_id,policy_owner_lead_id', 'nullable', 'string', 'max:80', new PersonName],
            'policy_owner.middle_name' => ['nullable', 'string', 'max:80', new PersonName],
            'policy_owner.last_name' => ['required_without_all:policy_owner_id,policy_owner_lead_id', 'nullable', 'string', 'max:80', new PersonName],
            'insured_same_as_owner' => ['sometimes', 'boolean'],
            'policy_insured_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $this->insuredNeeded() && ! $this->filled('policy_insured')), 'exists:clients,id'],
            'policy_insured' => ['nullable', 'array'],
            'policy_insured.first_name' => [Rule::requiredIf(fn () => $this->insuredNeeded() && ! $this->filled('policy_insured_id')), 'nullable', 'string', 'max:80', new PersonName],
            'policy_insured.middle_name' => ['nullable', 'string', 'max:80', new PersonName],
            'policy_insured.last_name' => [Rule::requiredIf(fn () => $this->insuredNeeded() && ! $this->filled('policy_insured_id')), 'nullable', 'string', 'max:80', new PersonName],
            // Only active plans can be chosen; a record may keep the (now inactive) plan it already has.
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $policy?->product_id ?? 0))],
            'ape' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999'],
            'sum_assured' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999999999'],
            // Any number of fund types. Only active ones can be added; a record keeps (now inactive) ones it already has.
            'fund_type_ids' => ['sometimes', 'array', 'max:20'],
            'fund_type_ids.*' => ['integer', 'distinct', Rule::exists('fund_types', 'id')->where(fn ($q) => $q->where('is_active', true)
                ->when($policy, fn ($q) => $q->orWhereIn('id', $policy->fundTypes()->pluck('fund_types.id'))))],
            'issued_date' => ['required', 'date', 'before_or_equal:today', 'after:1950-01-01'],
            'mode_of_payment' => ['required', Rule::in(Policy::PAYMENT_MODES)],
            'status' => ['required', Rule::in(Policy::STATUSES)],
            'policy_delivery_date' => ['nullable', 'date', 'after_or_equal:issued_date', 'before_or_equal:today'],
            'is_orphan' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'beneficiaries' => ['sometimes', 'array', 'max:10'],
        ], self::contactRules('policy_owner.'), self::contactRules('policy_insured.'), self::beneficiaryRules('beneficiaries.*.'));
    }

    /** Contact details sent with a role; they update the linked client or seed a new one. */
    private static function contactRules(string $prefix): array
    {
        $rules = ClientRequest::personRules($prefix) + ["{$prefix}address" => ['nullable', 'string', 'max:255']];

        return Arr::only($rules, array_map(fn ($f) => $prefix.$f, PolicyService::CONTACT_FIELDS));
    }

    /** The insured must be given unless it is flagged as the same person as the owner. */
    private function insuredNeeded(): bool
    {
        return ! $this->boolean('insured_same_as_owner');
    }

    /** A beneficiary with their own details; `id` (in the policy form) keeps an existing one. */
    public static function beneficiaryRules(string $prefix = ''): array
    {
        return array_merge([
            "{$prefix}id" => ['nullable', 'integer'],
            "{$prefix}relationship" => ['required', Rule::in(Beneficiary::RELATIONSHIPS)],
            "{$prefix}beneficiary_type" => ['sometimes', Rule::in(['primary', 'contingent'])],
            "{$prefix}designation" => ['sometimes', Rule::in(['revocable', 'irrevocable'])],
            "{$prefix}allocation_percentage" => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],
        ], Arr::only(ClientRequest::personRules($prefix), array_map(fn ($f) => $prefix.$f, Beneficiary::DETAIL_FIELDS)));
    }

    /** Details every Policy Owner and Policy Insured must have. */
    public const REQUIRED_PERSON_FIELDS = [
        'first_name' => 'first name',
        'last_name' => 'last name',
        'birthdate' => 'birthdate',
        'email' => 'email address',
        'mobile_number' => 'mobile number',
        'address' => 'address',
    ];

    /**
     * The owner (and the insured, unless it is the owner) must end up with all required details:
     * a value sent in the request, or for a linked person whose field isn't sent, the one on record.
     */
    private function requiredPersonDetails(Validator $validator): void
    {
        $roles = ['policy_owner' => 'Owner'] + ($this->insuredNeeded() ? ['policy_insured' => 'Insured'] : []);

        foreach ($roles as $role => $label) {
            $leadId = $role === 'policy_owner' ? $this->input('policy_owner_lead_id') : null;
            $id = $leadId ?: $this->input("{$role}_id");
            $sent = (array) $this->input($role, []);
            $record = $id ? $this->linkedRecord($role) : null;
            if ($id && ! $record) {
                continue; // reported by the "exists" rule
            }

            foreach (self::REQUIRED_PERSON_FIELDS as $field => $name) {
                // A lead has no address on record, so the form must send one.
                $value = array_key_exists($field, $sent) ? $sent[$field] : $record?->getAttributes()[$field] ?? null;
                $blank = $value === null || (is_string($value) && trim($value) === '');
                if ($blank && ! $validator->errors()->has("{$role}.{$field}")) {
                    $validator->errors()->add("{$role}.{$field}", "{$label} {$name} is required.");
                }
            }
        }
    }

    /** The client (or, for the owner, the lead) a role is linked to, if any. */
    private function linkedRecord(string $role): Client|Lead|null
    {
        if ($role === 'policy_owner' && $this->filled('policy_owner_lead_id')) {
            return Lead::find($this->input('policy_owner_lead_id'));
        }

        return $this->filled("{$role}_id") ? Client::find($this->input("{$role}_id")) : null;
    }

    /** The Policy Owner signs the contract, so they must be at least 18 on the issued date. */
    private function ownerIsAdult(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['policy_owner.birthdate', 'policy_owner_id', 'policy_owner_lead_id', 'issued_date'])) {
            return;
        }

        $sent = (array) $this->input('policy_owner', []);
        $birthdate = array_key_exists('birthdate', $sent)
            ? $sent['birthdate']
            : $this->linkedRecord('policy_owner')?->getRawOriginal('birthdate');

        if (blank($birthdate) || blank($this->input('issued_date'))) {
            return; // reported by the required-details check
        }

        if (Carbon::parse($birthdate)->addYears(self::MIN_OWNER_AGE)->gt(Carbon::parse($this->input('issued_date')))) {
            $validator->errors()->add('policy_owner.birthdate', 'The Policy Owner must be at least '.self::MIN_OWNER_AGE.' years old on the issued date.');
        }
    }

    public const MIN_OWNER_AGE = 18;

    public function after(): array
    {
        return [fn (Validator $validator) => $this->requiredPersonDetails($validator), fn (Validator $validator) => $this->ownerIsAdult($validator), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (self::allocationErrors($this->input('beneficiaries', [])) as $message) {
                $validator->errors()->add('beneficiaries', $message);
            }
        }];
    }

    /**
     * The percentages of ALL of a policy's beneficiaries (Primary and Secondary
     * together) may not exceed 100%, and must total exactly 100%.
     *
     * @return string[]
     */
    public static function allocationErrors(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $total = round(collect($items)->sum(fn ($b) => (float) ($b['allocation_percentage'] ?? 0)), 2);

        return match (true) {
            $total > 100 => ["Beneficiary percentages total {$total}%, which exceeds 100%."],
            $total != 100.0 => ["Beneficiary percentages must total 100% (currently {$total}%)."],
            default => [],
        };
    }

    /** Readable names in messages, e.g. "The owner first name cannot contain numbers." */
    public function attributes(): array
    {
        $names = [];
        foreach (['policy_owner' => 'owner', 'policy_insured' => 'insured'] as $role => $label) {
            foreach (['first_name' => 'first name', 'middle_name' => 'middle name', 'last_name' => 'last name', 'birthdate' => 'birthdate', 'gender' => 'gender', 'email' => 'email address', 'mobile_number' => 'mobile number', 'address' => 'address', 'occupation' => 'occupation'] as $field => $name) {
                $names["{$role}.{$field}"] = "{$label} {$name}";
            }
        }

        return $names + ['beneficiaries.*.first_name' => 'beneficiary first name', 'beneficiaries.*.last_name' => 'beneficiary last name'];
    }

    public function messages(): array
    {
        return [
            'policy_owner_id.exists' => 'Choose a client who is flagged as a Policy Owner.',
            'policy_number.regex' => 'Policy number may only contain letters, numbers, dashes and slashes.',
            'policy_number.unique' => 'This policy number is already in use.',
            'policy_delivery_date.after_or_equal' => 'Delivery date cannot be before the issued date.',
            'issued_date.before_or_equal' => 'Issued date cannot be in the future.',
            'product_id.exists' => 'Choose an active plan.',
            'fund_type_ids.*.exists' => 'Choose active fund types only.',
            'fund_type_ids.*.distinct' => 'Each fund type can be added once.',
        ];
    }
}
