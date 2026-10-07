<?php

namespace App\Http\Resources;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmailLog */
class EmailLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Addresses are masked in logs; the full address lives on the client record.
            'recipient' => $this->maskedRecipient(),
            'client' => $this->whenLoaded('client', fn () => $this->client ? $this->client->only(['id', 'first_name', 'middle_name', 'last_name']) : null),
            'subject' => $this->subject,
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['id' => $this->template->id, 'name' => $this->template->name] : null),
            'sent_by' => $this->whenLoaded('user', fn () => $this->user?->name),
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
