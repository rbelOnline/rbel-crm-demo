<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'occupation' => $this->occupation,
            'birthdate' => $this->birthdate?->toDateString(),
            'age' => $this->birthdate?->age,
            'gender' => $this->gender,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'address' => $this->address,
            'is_policy_owner' => $this->is_policy_owner,
            'owned_policies_count' => $this->whenCounted('ownedPolicies'),
            'insured_policies_count' => $this->whenCounted('insuredPolicies'),
            'in_force_owned_count' => $this->whenCounted('inForceOwned'),
            'total_ape_owned' => $this->when(isset($this->owned_policies_sum_ape), fn () => (float) $this->owned_policies_sum_ape),
            'client_status' => $this->when(
                isset($this->in_force_owned_count, $this->churned_owned_count, $this->matured_owned_count),
                fn () => Client::statusFor($this->in_force_owned_count, $this->churned_owned_count, $this->matured_owned_count),
            ),
            'owned_policies' => PolicyResource::collection($this->whenLoaded('ownedPolicies')),
            'insured_policies' => PolicyResource::collection($this->whenLoaded('insuredPolicies')),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
