<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Lead;
use App\Support\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * A lead who buys becomes a client: their details are copied into `clients`
 * (notes stay with the lead and go with it) and the lead is deleted, in one transaction.
 */
class LeadConverter
{
    /** Lead details carried over to the client. */
    private const COPIED = ['first_name', 'middle_name', 'last_name', 'occupation', 'birthdate', 'gender', 'email', 'mobile_number'];

    /** @param  array{address?: ?string, is_policy_owner?: bool}  $extra */
    public function convert(Lead $lead, array $extra = []): Client
    {
        return DB::transaction(function () use ($lead, $extra) {
            $client = Client::create(Arr::only($lead->getAttributes(), self::COPIED) + [
                'address' => $extra['address'] ?? null,
                'is_policy_owner' => $extra['is_policy_owner'] ?? true,
            ]);

            $lead->delete();

            AuditLogger::record('converted', 'leads', $lead->id, newValues: ['client_id' => $client->id], description: "Converted lead {$lead->displayName()} to a client");

            return $client;
        });
    }
}
