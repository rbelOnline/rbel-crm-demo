<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\FlushesAnalyticsCache;
use App\Models\Concerns\TitleCasesAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A beneficiary designation on a policy, with the beneficiary's own details. */
#[Fillable([
    'policy_id', 'first_name', 'middle_name', 'last_name', 'birthdate', 'gender', 'email', 'mobile_number',
    'relationship', 'beneficiary_type', 'designation', 'allocation_percentage',
])]
class Beneficiary extends Model
{
    use Auditable, FlushesAnalyticsCache, HasFactory, TitleCasesAttributes;

    /** Saved in Title Case (App\Support\TitleCase). */
    protected array $titleCase = ['first_name', 'middle_name', 'last_name'];

    /** Saved in lower case. */
    protected array $lowerCase = ['email'];

    public const RELATIONSHIPS = ['spouse', 'child', 'parent', 'sibling', 'grandchild', 'grandparent', 'relative', 'partner', 'estate', 'other'];

    /** Personal details stored on the designation. */
    public const DETAIL_FIELDS = ['first_name', 'middle_name', 'last_name', 'birthdate', 'gender', 'email', 'mobile_number'];

    protected string $auditModule = 'beneficiaries';

    protected function casts(): array
    {
        return [
            'birthdate' => 'date:Y-m-d',
            'allocation_percentage' => 'decimal:2',
        ];
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    public function displayName(): string
    {
        return Client::joinName($this->first_name, $this->middle_name, $this->last_name);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
