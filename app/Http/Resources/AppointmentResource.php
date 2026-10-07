<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => new ClientSummaryResource($this->whenLoaded('client')),
            'title' => $this->title,
            'description' => $this->description,
            'appointment_date' => $this->appointment_date?->toDateString(),
            'appointment_time' => substr((string) $this->appointment_time, 0, 5),
            'location' => $this->location,
            'status' => $this->status,
            'label' => $this->label,
            'notes' => $this->notes,
            'advisor' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
