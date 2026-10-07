<?php

namespace App\Http\Resources;

use App\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Goal */
class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'target_amount' => (float) $this->target_amount,
            'current_amount' => (float) $this->current_amount,
            'remaining_amount' => max(0, (float) $this->target_amount - (float) $this->current_amount),
            'progress_percentage' => $this->progress_percentage,
            'start_date' => $this->start_date?->toDateString(),
            'target_date' => $this->target_date?->toDateString(),
            'days_remaining' => $this->target_date ? (int) now()->startOfDay()->diffInDays($this->target_date, false) : null,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
