<?php

namespace App\Http\Resources;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Beneficiary */
class BeneficiaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->policy_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'birthdate' => $this->birthdate?->toDateString(),
            'age' => $this->birthdate?->age,
            'gender' => $this->gender,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'policy' => new PolicyResource($this->whenLoaded('policy')),
            'relationship' => $this->relationship,
            'beneficiary_type' => $this->beneficiary_type,
            'designation' => $this->designation,
            'allocation_percentage' => $this->allocation_percentage !== null ? (float) $this->allocation_percentage : null,
        ];
    }
}
