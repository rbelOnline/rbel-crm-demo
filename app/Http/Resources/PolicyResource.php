<?php

namespace App\Http\Resources;

use App\Models\Policy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Policy */
class PolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_number' => $this->policy_number,
            'policy_owner_id' => $this->policy_owner_id,
            'policy_insured_id' => $this->policy_insured_id,
            'policy_owner' => new ClientSummaryResource($this->whenLoaded('owner')),
            'policy_insured' => new ClientSummaryResource($this->whenLoaded('insured')),
            'is_self_insured' => $this->isSelfInsured(),
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'code' => $this->product->code,
                'plan_type' => $this->product->plan_type,
                'category' => $this->product->category,
            ]),
            'ape' => (float) $this->ape,
            'sum_assured' => (float) $this->sum_assured,
            'fund_type_ids' => $this->whenLoaded('fundTypes', fn () => $this->fundTypes->pluck('id')->all()),
            'fund_types' => $this->whenLoaded('fundTypes', fn () => $this->fundTypes->map->only(['id', 'name', 'suitability'])->values()),
            'issued_date' => $this->issued_date?->toDateString(),
            'mode_of_payment' => $this->mode_of_payment,
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'policy_delivery_date' => $this->policy_delivery_date?->toDateString(),
            'is_orphan' => $this->is_orphan,
            'remarks' => $this->remarks,
            // The storage path itself is never exposed — only metadata.
            'coverage_document' => $this->hasCoverageDocument() ? [
                'name' => $this->coverage_original_name,
                'mime' => $this->coverage_mime,
                'size' => $this->coverage_size,
                'uploaded_at' => $this->coverage_uploaded_at?->toIso8601String(),
            ] : null,
            'beneficiaries_count' => $this->whenCounted('beneficiaries'),
            'beneficiaries' => BeneficiaryResource::collection($this->whenLoaded('beneficiaries')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
