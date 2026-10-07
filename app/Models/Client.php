<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\FlushesAnalyticsCache;
use App\Models\Concerns\TitleCasesAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person on a policy. is_policy_owner marks the clients who may OWN a policy;
 * any client may be the insured, so a self-insured policy points both of its
 * relationships at the same (owner-flagged) client.
 *
 * Deleting a client deletes their policies (owned or insured, with those policies'
 * beneficiaries, documents and reminders), appointments, reminders and email
 * logs — through the database's ON DELETE CASCADE; see ClientController::destroy().
 */
#[Fillable([
    'first_name', 'middle_name', 'last_name', 'occupation', 'birthdate', 'gender',
    'email', 'mobile_number', 'address', 'is_policy_owner',
])]
class Client extends Model
{
    use Auditable, FlushesAnalyticsCache, HasFactory, TitleCasesAttributes;

    /** Saved in Title Case (App\Support\TitleCase). */
    protected array $titleCase = ['first_name', 'middle_name', 'last_name', 'occupation', 'address'];

    /** Saved in lower case. */
    protected array $lowerCase = ['email'];

    protected string $auditModule = 'clients';

    protected function casts(): array
    {
        return [
            'birthdate' => 'date:Y-m-d',
            'is_policy_owner' => 'boolean',
        ];
    }

    /** Policies this client OWNS (pays for / controls). */
    public function ownedPolicies(): HasMany
    {
        return $this->hasMany(Policy::class, 'policy_owner_id');
    }

    /** Policies on which this client's life/health is INSURED. */
    public function insuredPolicies(): HasMany
    {
        return $this->hasMany(Policy::class, 'policy_insured_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    protected function age(): Attribute
    {
        return Attribute::get(fn () => $this->birthdate?->age);
    }

    /**
     * "First Middle Last", for server-rendered output only (Excel, email, documents).
     * The SPA builds names itself; no full name is stored or sent in API resources.
     */
    public function displayName(): string
    {
        return self::joinName($this->first_name, $this->middle_name, $this->last_name);
    }

    public static function joinName(?string ...$parts): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn ($p) => filled($p)))));
    }

    /**
     * Client status from the policies they OWN (same rule as vw_client_policy_overview):
     * active (something in force), inactive = churned (none in force, one terminated /
     * lapsed / surrendered), completed (none of those, one matured), else prospect.
     */
    public static function statusFor(int $inForce, int $churned, int $matured): string
    {
        return match (true) {
            $inForce > 0 => 'active',
            $churned > 0 => 'inactive',
            $matured > 0 => 'completed',
            default => 'prospect',
        };
    }

    /** withCount() entries that statusFor() needs. */
    public static function statusCounts(): array
    {
        return [
            'ownedPolicies as in_force_owned_count' => fn ($q) => $q->whereIn('status', Policy::IN_FORCE_STATUSES),
            'ownedPolicies as churned_owned_count' => fn ($q) => $q->whereIn('status', Policy::CHURN_STATUSES),
            'ownedPolicies as matured_owned_count' => fn ($q) => $q->where('status', 'matured'),
        ];
    }

    /** Clients who may own a policy. */
    #[Scope]
    protected function policyOwners(Builder $query): void
    {
        $query->where('is_policy_owner', true);
    }

    /** Clients born in a month (1–12). */
    #[Scope]
    protected function bornInMonth(Builder $query, int $month): void
    {
        $query->whereMonth('birthdate', $month);
    }

    /**
     * Match on name (contains), email (prefix) or mobile number (contains).
     * $table lets the same scope target an aliased join (owner / insured).
     */
    #[Scope]
    protected function search(Builder $query, ?string $term, string $table = 'clients'): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $q) => static::applySearch($q, $term, $table));
    }

    /** Shared with Lead and the owner / insured aliases in PolicySearch. */
    public static function applySearch($query, string $term, string $table): void
    {
        $escaped = addcslashes($term, '%_\\');

        $query->whereRaw("CONCAT_WS(' ', {$table}.first_name, {$table}.middle_name, {$table}.last_name) LIKE ?", ["%{$escaped}%"])
            ->orWhereRaw("CONCAT_WS(' ', {$table}.first_name, {$table}.last_name) LIKE ?", ["%{$escaped}%"])
            ->orWhere("{$table}.email", 'like', "{$escaped}%")
            ->orWhere("{$table}.mobile_number", 'like', "%{$escaped}%");
    }
}
