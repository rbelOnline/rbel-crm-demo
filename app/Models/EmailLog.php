<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['email_template_id', 'user_id', 'client_id', 'policy_id', 'automation', 'dedupe_key', 'recipient', 'subject', 'status', 'error_message', 'sent_at'])]
class EmailLog extends Model
{
    public const STATUSES = ['pending', 'sent', 'failed'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** j***@example.com — enough to recognise, not enough to harvest. */
    public function maskedRecipient(): string
    {
        [$local, $domain] = array_pad(explode('@', $this->recipient, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', 3).($domain !== '' ? '@'.$domain : '');
    }
}
