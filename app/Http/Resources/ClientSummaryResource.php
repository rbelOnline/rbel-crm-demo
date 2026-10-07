<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact client shape used wherever a client appears inside another record
 * (policy owner, policy insured, appointment client, lookup results).
 * The SPA joins the name parts for display.
 *
 * @mixin Client
 */
class ClientSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'age' => $this->birthdate?->age,
            'gender' => $this->gender,
            'is_policy_owner' => (bool) $this->is_policy_owner,
        ];
    }
}
