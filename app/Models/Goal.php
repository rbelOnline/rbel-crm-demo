<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\GoalProgressService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'title', 'description', 'target_amount', 'current_amount', 'start_date', 'target_date', 'status'])]
class Goal extends Model
{
    use Auditable, HasFactory;

    public const STATUSES = ['not_started', 'in_progress', 'achieved', 'cancelled'];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'target_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // current_amount is always derived (see GoalProgressService). Closed goals keep
        // their final value unless they are being opened/closed in this save.
        static::saving(function (Goal $goal) {
            // A goal covers start_date ("Date from") – target_date ("Date to"); it starts today unless given.
            $goal->start_date ??= today();

            if (! $goal->exists || $goal->isDirty('status') || in_array($goal->status, GoalProgressService::OPEN_STATUSES, true)) {
                $goal->current_amount = app(GoalProgressService::class)->amountForGoal($goal);
            }
        });
    }

    /** Progress toward the target, capped at 100%. */
    protected function progressPercentage(): Attribute
    {
        return Attribute::get(function () {
            $target = (float) $this->target_amount;

            return $target > 0 ? round(min(100, (float) $this->current_amount / $target * 100), 1) : 0.0;
        });
    }


    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
