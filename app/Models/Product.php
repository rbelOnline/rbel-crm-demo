<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\FlushesAnalyticsCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An insurance plan sold to clients. Plan Name = `name`; Plan Type = VUL or TRAD. */
#[Fillable(['code', 'name', 'plan_type', 'category', 'is_active'])]
class Product extends Model
{
    use Auditable, FlushesAnalyticsCache, HasFactory;

    /** VUL = variable universal life (investment-linked); TRAD = traditional. */
    public const PLAN_TYPES = ['VUL', 'TRAD'];

    protected string $auditModule = 'products';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class);
    }
}
