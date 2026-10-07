<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Policy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Server-side search / filter / sort / paginate for policies.
 *
 * Owner and insured are joined under separate aliases (`owner`, `insured`)
 * so every filter, search and sort names exactly one role. A filter on
 * policy_owner_id never matches rows where that client is only the insured,
 * and vice versa.
 */
class PolicySearch
{
    public const SORTABLE = [
        'policy_number' => 'policies.policy_number',
        'policy_owner' => 'owner.last_name',
        'policy_insured' => 'insured.last_name',
        'product' => 'products.name',
        'ape' => 'policies.ape',
        'issued_date' => 'policies.issued_date',
        'mode_of_payment' => 'policies.mode_of_payment',
        'sum_assured' => 'policies.sum_assured',
        'status' => 'policies.status',
        'policy_delivery_date' => 'policies.policy_delivery_date',
        'is_orphan' => 'policies.is_orphan',
        'created_at' => 'policies.created_at',
    ];

    public function query(array $filters): Builder
    {
        $query = Policy::query()
            ->select('policies.*')
            ->join('clients as owner', 'owner.id', '=', 'policies.policy_owner_id')
            ->join('clients as insured', 'insured.id', '=', 'policies.policy_insured_id')
            ->join('products', 'products.id', '=', 'policies.product_id');

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'issued_date', $filters['direction'] ?? 'asc');

        return $query;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 10), 1), 100);

        return $this->query($filters)
            ->with(['owner', 'insured', 'product'])
            ->withCount('beneficiaries')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function applyFilters(Builder $query, array $f): void
    {
        // Global search across the policy and BOTH people (each under its own alias).
        if (filled($f['search'] ?? null)) {
            $term = trim($f['search']);
            $escaped = addcslashes($term, '%_\\');

            $query->where(function (Builder $q) use ($term, $escaped) {
                $q->where('policies.policy_number', 'like', "{$escaped}%")
                    ->orWhere('products.name', 'like', "%{$escaped}%")
                    ->orWhere('policies.status', $term)
                    ->orWhere(fn ($o) => Client::applySearch($o, $term, 'owner'))
                    ->orWhere(fn ($i) => Client::applySearch($i, $term, 'insured'));
            });
        }

        // Role-specific text search.
        if (filled($f['owner_search'] ?? null)) {
            $query->where(fn ($q) => Client::applySearch($q, trim($f['owner_search']), 'owner'));
        }

        if (filled($f['insured_search'] ?? null)) {
            $query->where(fn ($q) => Client::applySearch($q, trim($f['insured_search']), 'insured'));
        }

        // Role-specific exact client filters.
        if (filled($f['policy_owner_id'] ?? null)) {
            $query->where('policies.policy_owner_id', (int) $f['policy_owner_id']);
        }

        if (filled($f['policy_insured_id'] ?? null)) {
            $query->where('policies.policy_insured_id', (int) $f['policy_insured_id']);
        }

        // Role-specific birth month on that role's alias.
        foreach (['owner', 'insured'] as $role) {
            if (filled($f["{$role}_birth_month"] ?? null)) {
                $query->whereMonth("{$role}.birthdate", (int) $f["{$role}_birth_month"]);
            }
        }

        // General birth month: owner OR insured born that month. A deliberate,
        // user-requested exception to the "one explicit role per person filter" rule
        // (Clients module); each role is still matched on its own alias.
        if (filled($f['birth_month'] ?? null)) {
            $month = (int) $f['birth_month'];
            $query->where(fn (Builder $q) => $q
                ->whereMonth('owner.birthdate', $month)
                ->orWhereMonth('insured.birthdate', $month));
        }

        if (filled($f['product_id'] ?? null)) {
            $query->whereIn('policies.product_id', (array) $f['product_id']);
        }

        if (filled($f['status'] ?? null)) {
            $query->whereIn('policies.status', (array) $f['status']);
        }

        if (filled($f['mode_of_payment'] ?? null)) {
            $query->whereIn('policies.mode_of_payment', (array) $f['mode_of_payment']);
        }

        if (isset($f['is_orphan']) && $f['is_orphan'] !== '' && $f['is_orphan'] !== null) {
            $query->where('policies.is_orphan', filter_var($f['is_orphan'], FILTER_VALIDATE_BOOLEAN));
        }

        if (filled($f['issued_from'] ?? null)) {
            $query->where('policies.issued_date', '>=', $f['issued_from']);
        }

        if (filled($f['issued_to'] ?? null)) {
            $query->where('policies.issued_date', '<=', $f['issued_to']);
        }

        // Year as a sargable range so the issued_date index is used.
        if (filled($f['year'] ?? null)) {
            $year = (int) $f['year'];
            $query->where('policies.issued_date', '>=', "{$year}-01-01")
                ->where('policies.issued_date', '<', ($year + 1).'-01-01');
        }

        if (($f['delivery'] ?? null) === 'pending') {
            $query->whereNull('policies.policy_delivery_date');
        } elseif (($f['delivery'] ?? null) === 'delivered') {
            $query->whereNotNull('policies.policy_delivery_date');
        }

        if (($f['relationship'] ?? null) === 'self') {
            $query->whereColumn('policies.policy_owner_id', 'policies.policy_insured_id');
        } elseif (($f['relationship'] ?? null) === 'different') {
            $query->whereColumn('policies.policy_owner_id', '!=', 'policies.policy_insured_id');
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort] ?? self::SORTABLE['issued_date'];
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction);

        // Secondary keys keep name sorts and pagination deterministic.
        if ($sort === 'policy_owner') {
            $query->orderBy('owner.first_name', $direction);
        } elseif ($sort === 'policy_insured') {
            $query->orderBy('insured.first_name', $direction);
        }

        $query->orderBy('policies.id', $direction);
    }
}
