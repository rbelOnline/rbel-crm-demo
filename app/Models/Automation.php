<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A daily email automation (see App\Services\AutomationService). */
#[Fillable(['enabled', 'email_template_id', 'send_time', 'sender_user_id', 'last_run_at', 'last_run_summary'])]
class Automation extends Model
{
    use Auditable;

    public const BIRTHDAY = 'birthday_greeting';

    public const PREMIUM_DUE = 'premium_due';

    public const ANNIVERSARY = 'policy_anniversary';

    public const LABELS = [
        self::BIRTHDAY => 'Birthday greetings',
        self::PREMIUM_DUE => 'Payment reminders',
        self::ANNIVERSARY => 'Policy anniversaries',
    ];

    protected string $auditModule = 'automations';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'last_run_summary' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->key] ?? $this->key;
    }

    /** "08:00" */
    public function sendTime(): string
    {
        return substr((string) $this->send_time, 0, 5);
    }
}
