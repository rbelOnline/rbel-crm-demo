<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A fund type option (Fund Types module), chosen per policy on the Clients form. */
#[Fillable(['name', 'suitability', 'is_active'])]
class FundType extends Model
{
    use Auditable, HasFactory;

    /** Investor risk profiles, lowest to highest risk. */
    public const SUITABILITIES = ['conservative', 'moderate', 'aggressive'];

    protected string $auditModule = 'fund_types';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(Policy::class)->withTimestamps();
    }
}
