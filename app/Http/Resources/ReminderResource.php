<?php

namespace App\Http\Resources;

use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reminder */
class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'notes' => $this->notes,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => ! $this->completed_at && $this->due_date?->lt(today()),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'client_id' => $this->client_id,
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'policy_id' => $this->policy_id,
            'policy_number' => $this->whenLoaded('policy', fn () => $this->policy?->policy_number),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
