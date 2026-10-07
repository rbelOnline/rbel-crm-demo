<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\FlushesAnalyticsCache;
use App\Services\GoalProgressService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('policies')]
#[Fillable([
    'policy_number', 'policy_owner_id', 'policy_insured_id', 'product_id', 'ape',
    'issued_date', 'mode_of_payment', 'sum_assured', 'status', 'policy_delivery_date',
    'is_orphan', 'remarks',
])]
class Policy extends Model
{
    use Auditable, FlushesAnalyticsCache, HasFactory;

    public const STATUSES = ['pending', 'cooling_off', 'active', 'postponed', 'lapsed', 'surrendered', 'matured', 'terminated'];

    /** Never issued, so not a sale: excluded from sales, mix and age analytics and advisor goals. */
    public const NOT_SOLD_STATUS = 'postponed';

    /** Cooling off (the free-look period after issue) is in force: the policy is issued and covers the client. */
    public const IN_FORCE_STATUSES = ['pending', 'cooling_off', 'active'];

    /** Ended badly: an owner with one of these and nothing in force is churned (inactive). */
    public const CHURN_STATUSES = ['terminated', 'lapsed', 'surrendered'];

    public const PAYMENT_MODES = ['annual', 'semi_annual', 'quarterly', 'monthly', 'single'];

    protected function casts(): array
    {
        return [
            'ape' => 'decimal:2',
            'sum_assured' => 'decimal:2',
            'issued_date' => 'date:Y-m-d',
            'policy_delivery_date' => 'date:Y-m-d',
            'is_orphan' => 'boolean',
            'status_changed_at' => 'datetime',
            'coverage_uploaded_at' => 'datetime',
            'coverage_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Policy $policy) {
            if ($policy->isDirty('status')) {
                $policy->status_changed_at = now();
            }
        });

        // Goal progress is derived from policy APE.
        static::saved(fn () => app(GoalProgressService::class)->refreshOpenGoals());
        static::deleted(fn () => app(GoalProgressService::class)->refreshOpenGoals());
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    /** The client who owns the policy (a client flagged is_policy_owner). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'policy_owner_id');
    }

    /** The client whose life/coverage is insured. May differ from the owner. */
    public function insured(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'policy_insured_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The funds the policy is invested in (Fund Types module); none, one or several. */
    public function fundTypes(): BelongsToMany
    {
        return $this->belongsToMany(FundType::class)->withTimestamps()->orderBy('fund_types.name');
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function hasCoverageDocument(): bool
    {
        return $this->insurance_coverage_path !== null;
    }

    public function isSelfInsured(): bool
    {
        return (int) $this->policy_owner_id === (int) $this->policy_insured_id;
    }
}
