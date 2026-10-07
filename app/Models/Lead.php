<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TitleCasesAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A prospect with no policy yet. Leads are separate from clients: when a lead buys,
 * App\Services\LeadConverter copies them into `clients` and deletes the lead.
 */
#[Fillable([
    'first_name', 'middle_name', 'last_name', 'occupation', 'birthdate', 'gender',
    'email', 'mobile_number', 'notes',
])]
class Lead extends Model
{
    use Auditable, HasFactory, TitleCasesAttributes;

    /** Saved in Title Case (App\Support\TitleCase). */
    protected array $titleCase = ['first_name', 'middle_name', 'last_name', 'occupation'];

    /** Saved in lower case. */
    protected array $lowerCase = ['email'];

    protected string $auditModule = 'leads';

    protected function casts(): array
    {
        return ['birthdate' => 'date:Y-m-d'];
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    public function displayName(): string
    {
        return Client::joinName($this->first_name, $this->middle_name, $this->last_name);
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where(fn (Builder $q) => Client::applySearch($q, $term, 'leads'));
        }
    }
}
