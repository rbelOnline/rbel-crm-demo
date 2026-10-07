<?php

namespace App\Services;

use App\Models\Policy;
use App\Support\AnalyticsCache;
use App\Support\Generations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-side analytics. Heavy aggregation happens in MySQL (stored procedures,
 * CTEs, window functions, views); PHP only shapes the result for the API.
 *
 * Every person-based metric takes an explicit $role of 'owner' or 'insured'
 * and resolves it to policy_owner_id or policy_insured_id — the two are never
 * merged into a single "client" dimension.
 */
class AnalyticsService
{
    public const ROLES = ['owner', 'insured'];

    public const CHURN_DEFINITION = 'A client is inactive (churned) when they own no in-force policy (active, cooling off or pending) '
        .'and at least one of their policies was terminated, lapsed or surrendered. Clients whose policies only matured, '
        .'and prospects, are not churn. Churn rate = inactive ÷ (active + inactive) policy owners.';

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Jan–Dec sales via sp_monthly_sales, optionally scoped to one client in one role. */
    public function monthlySales(int $year, ?string $role = null, ?int $clientId = null): array
    {
        if ($clientId !== null) {
            $this->assertRole($role);
        }

        return AnalyticsCache::remember('monthly-sales', compact('year', 'role', 'clientId'), function () use ($year, $role, $clientId) {
            $rows = DB::select('CALL sp_monthly_sales(?, ?, ?)', [$year, $role, $clientId]);

            $months = array_map(fn ($r) => [
                'month' => (int) $r->month,
                'label' => self::MONTHS[$r->month - 1],
                'policy_count' => (int) $r->policy_count,
                'total_ape' => (float) $r->total_ape,
                'running_total' => (float) $r->running_total,
                'sales_rank' => $r->sales_rank !== null ? (int) $r->sales_rank : null,
                'prev_month_ape' => $r->prev_month_ape !== null ? (float) $r->prev_month_ape : null,
            ], $rows);

            $total = array_sum(array_column($months, 'total_ape'));
            $previous = (float) DB::table('vw_monthly_sales')->where('sales_year', $year - 1)->sum('total_ape');

            return [
                'year' => $year,
                'months' => $months,
                'total_ape' => $total,
                'policy_count' => array_sum(array_column($months, 'policy_count')),
                'previous_year_ape' => $previous,
                'yoy_change_pct' => $previous > 0 ? round(($total - $previous) / $previous * 100, 1) : null,
            ];
        });
    }

    /**
     * Distinct clients per generation (by birth year, App\Support\Generations), for
     * owners OR insureds. Sales only (postponed policies are excluded), optionally for
     * policies issued in $year.
     */
    public function ageDistribution(?int $year, string $role, ?int $yearTo = null): array
    {
        $this->assertRole($role);
        $column = $role === 'owner' ? 'policy_owner_id' : 'policy_insured_id';
        // One year, or the range $year (from) – $yearTo.
        $yearTo ??= $year;

        return AnalyticsCache::remember('generations', compact('year', 'yearTo', 'role'), function () use ($year, $yearTo, $role, $column) {
            [$yearSql, $bindings] = $this->rangeClause($year, $yearTo);
            $generation = Generations::caseSql('c.birthdate');

            $rows = collect(DB::select("
                WITH per_client AS (
                    SELECT p.{$column} AS client_id, COUNT(*) AS policy_count, SUM(p.ape) AS total_ape
                    FROM policies p
                    WHERE p.status <> ? {$yearSql}
                    GROUP BY p.{$column}
                )
                SELECT {$generation} AS generation,
                       COUNT(*) AS person_count, SUM(pc.policy_count) AS policy_count, SUM(pc.total_ape) AS total_ape
                FROM per_client pc
                JOIN clients c ON c.id = pc.client_id
                GROUP BY generation", [Policy::NOT_SOLD_STATUS, ...$bindings]))->keyBy('generation');

            $total = (int) $rows->sum('person_count');
            $ranges = [];
            foreach ([...array_keys(Generations::ALL), 'unknown'] as $key) {
                $row = $rows->get($key);
                if (! $row && ! in_array($key, Generations::ALWAYS_SHOWN, true)) {
                    continue;
                }
                $ranges[] = [
                    'key' => $key,
                    'label' => $key === 'unknown' ? 'Unknown' : Generations::ALL[$key][0],
                    'years' => $key === 'unknown' ? 'No birthdate' : Generations::years($key),
                    'person_count' => (int) ($row->person_count ?? 0),
                    'policy_count' => (int) ($row->policy_count ?? 0),
                    'total_ape' => (float) ($row->total_ape ?? 0),
                    'pct' => $total > 0 ? round(100 * (int) ($row->person_count ?? 0) / $total, 2) : 0.0,
                ];
            }

            return ['role' => $role, 'year' => $year, 'year_to' => $yearTo, 'ranges' => $ranges];
        });
    }

    public function genderDistribution(?int $year, string $role): array
    {
        $this->assertRole($role);
        $column = $role === 'owner' ? 'policy_owner_id' : 'policy_insured_id';

        return AnalyticsCache::remember('gender', compact('year', 'role'), function () use ($year, $column) {
            [$yearSql, $bindings] = $this->yearClause($year);

            return array_map(fn ($r) => [
                'gender' => $r->gender,
                'person_count' => (int) $r->person_count,
                'pct' => (float) $r->pct,
            ], DB::select("
                WITH people AS (
                    SELECT DISTINCT p.{$column} AS client_id
                    FROM policies p
                    WHERE p.status <> 'postponed' {$yearSql}
                )
                SELECT COALESCE(c.gender, 'unspecified') AS gender,
                       COUNT(*) AS person_count,
                       ROUND(100 * COUNT(*) / SUM(COUNT(*)) OVER (), 2) AS pct
                FROM people
                JOIN clients c ON c.id = people.client_id
                GROUP BY COALESCE(c.gender, 'unspecified')
                ORDER BY person_count DESC", $bindings));
        });
    }

    /** Status, product, payment-mode and delivery breakdowns. */
    public function policyAnalytics(?int $year): array
    {
        return AnalyticsCache::remember('policy-analytics', compact('year'), function () use ($year) {
            [$yearSql, $bindings] = $this->yearClause($year);

            $summary = DB::selectOne("
                SELECT COUNT(*)                                                   AS total,
                       SUM(status = 'active')                                     AS active,
                       SUM(status = 'pending')                                    AS pending,
                       SUM(status = 'lapsed')                                     AS lapsed,
                       SUM(status IN ('surrendered', 'matured', 'terminated'))    AS closed,
                       SUM(policy_delivery_date IS NULL AND status IN ('active', 'pending', 'cooling_off')) AS pending_delivery,
                       SUM(is_orphan)                                             AS orphan,
                       SUM(policy_owner_id = policy_insured_id)                   AS self_insured,
                       SUM(policy_owner_id <> policy_insured_id)                  AS owner_not_insured,
                       COALESCE(SUM(ape), 0)                                      AS total_ape,
                       COALESCE(SUM(sum_assured), 0)                              AS total_sum_assured
                FROM policies p
                WHERE 1 = 1 {$yearSql}", $bindings);

            $byStatus = DB::select("
                SELECT status, COUNT(*) AS policy_count, SUM(ape) AS total_ape
                FROM policies p WHERE 1 = 1 {$yearSql}
                GROUP BY status ORDER BY policy_count DESC", $bindings);

            $byProduct = DB::select("
                WITH per_product AS (
                    SELECT pr.name AS product, pr.plan_type, COUNT(*) AS policy_count, SUM(p.ape) AS total_ape
                    FROM policies p
                    JOIN products pr ON pr.id = p.product_id
                    WHERE p.status <> 'postponed' {$yearSql}
                    GROUP BY pr.id, pr.name, pr.plan_type
                )
                SELECT product, plan_type, policy_count, total_ape,
                       ROUND(100 * total_ape / NULLIF(SUM(total_ape) OVER (), 0), 2) AS ape_share_pct,
                       RANK() OVER (ORDER BY total_ape DESC) AS ape_rank
                FROM per_product
                ORDER BY total_ape DESC", $bindings);

            $byMode = DB::select("
                SELECT mode_of_payment, COUNT(*) AS policy_count, SUM(ape) AS total_ape
                FROM policies p WHERE p.status <> 'postponed' {$yearSql}
                GROUP BY mode_of_payment ORDER BY policy_count DESC", $bindings);

            $deliveryAging = DB::select('
                SELECT aging_bucket, COUNT(*) AS policy_count, MAX(days_pending) AS oldest_days
                FROM vw_policy_delivery_monitoring
                GROUP BY aging_bucket
                ORDER BY MIN(days_pending)');

            return [
                'summary' => array_map(fn ($v) => (float) $v, (array) $summary),
                'by_status' => $this->floatRows($byStatus, ['policy_count', 'total_ape']),
                'by_product' => $this->floatRows($byProduct, ['policy_count', 'total_ape', 'ape_share_pct', 'ape_rank']),
                'by_payment_mode' => $this->floatRows($byMode, ['policy_count', 'total_ape']),
                'delivery_aging' => $this->floatRows($deliveryAging, ['policy_count', 'oldest_days']),
            ];
        });
    }

    /** Year-over-year totals using LAG() over the monthly sales view. */
    public function annualSales(): array
    {
        return AnalyticsCache::remember('annual-sales', [], function () {
            $rows = DB::select('
                WITH yearly AS (
                    SELECT sales_year, SUM(policy_count) AS policy_count, SUM(total_ape) AS total_ape
                    FROM vw_monthly_sales
                    GROUP BY sales_year
                )
                SELECT sales_year, policy_count, total_ape,
                       LAG(total_ape) OVER (ORDER BY sales_year) AS prev_year_ape,
                       SUM(total_ape) OVER (ORDER BY sales_year) AS cumulative_ape
                FROM yearly
                ORDER BY sales_year');

            return array_map(fn ($r) => [
                'year' => (int) $r->sales_year,
                'policy_count' => (int) $r->policy_count,
                'total_ape' => (float) $r->total_ape,
                'cumulative_ape' => (float) $r->cumulative_ape,
                'yoy_change_pct' => $r->prev_year_ape > 0
                    ? round(($r->total_ape - $r->prev_year_ape) / $r->prev_year_ape * 100, 1)
                    : null,
            ], $rows);
        });
    }

    /**
     * Rank people by APE within ONE role. Owners are ranked by the premium
     * they pay; insureds by the premium written on their lives.
     */
    public function topClientsByApe(?int $year, string $role, int $limit = 10): array
    {
        $this->assertRole($role);
        $column = $role === 'owner' ? 'policy_owner_id' : 'policy_insured_id';

        return AnalyticsCache::remember('top-clients', compact('year', 'role', 'limit'), function () use ($year, $column, $limit) {
            [$yearSql, $bindings] = $this->yearClause($year);
            $bindings[] = $limit;

            $rows = DB::select("
                WITH per_client AS (
                    SELECT p.{$column} AS client_id, COUNT(*) AS policy_count, SUM(p.ape) AS total_ape
                    FROM policies p
                    WHERE p.status <> 'postponed' {$yearSql}
                    GROUP BY p.{$column}
                ),
                ranked AS (
                    SELECT client_id, policy_count, total_ape,
                           DENSE_RANK() OVER (ORDER BY total_ape DESC) AS ape_rank,
                           ROUND(100 * total_ape / SUM(total_ape) OVER (), 2) AS share_pct,
                           ROUND(100 * SUM(total_ape) OVER (ORDER BY total_ape DESC, client_id ROWS UNBOUNDED PRECEDING)
                                 / SUM(total_ape) OVER (), 2) AS cumulative_share_pct
                    FROM per_client
                )
                SELECT r.*, c.first_name, c.middle_name, c.last_name
                FROM ranked r
                JOIN clients c ON c.id = r.client_id
                ORDER BY r.ape_rank, c.last_name
                LIMIT ?", $bindings);

            return array_map(fn ($r) => [
                'client_id' => (int) $r->client_id,
                'first_name' => $r->first_name,
                'middle_name' => $r->middle_name,
                'last_name' => $r->last_name,
                'policy_count' => (int) $r->policy_count,
                'total_ape' => (float) $r->total_ape,
                'ape_rank' => (int) $r->ape_rank,
                'share_pct' => (float) $r->share_pct,
                'cumulative_share_pct' => (float) $r->cumulative_share_pct,
            ], $rows);
        });
    }

    /**
     * Retention / churn (see CHURN_DEFINITION), optionally only over policies issued
     * between $yearFrom and $yearTo. Each owner is classified from those policies with
     * the same rule as vw_client_policy_overview.client_status (Client::statusFor):
     * with no range the result matches the view.
     */
    public function retention(?int $yearFrom = null, ?int $yearTo = null): array
    {
        return AnalyticsCache::remember('retention', compact('yearFrom', 'yearTo'), function () use ($yearFrom, $yearTo) {
            [$rangeSql, $bindings] = $this->rangeClause($yearFrom, $yearTo);
            $list = fn (array $statuses) => implode(',', array_map(fn ($s) => DB::getPdo()->quote($s), $statuses));
            $inForce = $list(Policy::IN_FORCE_STATUSES);
            $churned = $list(Policy::CHURN_STATUSES);

            // One row per owner of a policy in the range, with its client status.
            $owners = "
                SELECT c.id AS client_id, c.first_name, c.middle_name, c.last_name,
                       o.policies_owned, o.last_policy_date, o.last_status_change_at,
                       CASE
                           WHEN o.in_force_owned > 0 THEN 'active'
                           WHEN o.churned_owned > 0  THEN 'inactive'
                           WHEN o.matured_owned > 0  THEN 'completed'
                           ELSE 'prospect'
                       END AS client_status
                FROM (
                    SELECT p.policy_owner_id,
                           COUNT(*)                         AS policies_owned,
                           SUM(p.status IN ({$inForce}))    AS in_force_owned,
                           SUM(p.status IN ({$churned}))    AS churned_owned,
                           SUM(p.status = 'matured')        AS matured_owned,
                           MAX(p.issued_date)               AS last_policy_date,
                           MAX(p.status_changed_at)         AS last_status_change_at
                    FROM policies p
                    WHERE 1 = 1 {$rangeSql}
                    GROUP BY p.policy_owner_id
                ) o
                JOIN clients c ON c.id = o.policy_owner_id";

            $counts = DB::selectOne("
                SELECT SUM(client_status = 'active')    AS active,
                       SUM(client_status = 'inactive')  AS inactive,
                       SUM(client_status = 'completed') AS completed
                FROM ({$owners}) s", $bindings);

            $active = (int) $counts->active;
            $inactive = (int) $counts->inactive;
            $completed = (int) $counts->completed;
            $base = $active + $inactive;

            $recent = DB::select("
                SELECT client_id, first_name, middle_name, last_name, policies_owned, last_policy_date, last_status_change_at
                FROM ({$owners}) s
                WHERE client_status = 'inactive'
                ORDER BY last_status_change_at DESC
                LIMIT 10", $bindings);

            return [
                'definition' => self::CHURN_DEFINITION,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'active_clients' => $active,
                'inactive_clients' => $inactive,
                'completed_clients' => $completed,
                // Everyone not classified above (no policy, only postponed, or only insured), plus leads.
                'prospects' => max(0, DB::table('clients')->count() - $active - $inactive - $completed) + DB::table('leads')->count(),
                'churn_rate_pct' => $base > 0 ? round($inactive / $base * 100, 1) : 0.0,
                'retention_rate_pct' => $base > 0 ? round($active / $base * 100, 1) : 0.0,
                'recently_inactive' => $recent,
            ];
        });
    }

    private function assertRole(?string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Role must be "owner" or "insured".');
        }
    }

    /** Sargable year filter on policies aliased as p. */
    private function yearClause(?int $year): array
    {
        return $this->rangeClause($year, $year);
    }

    /** Policies (alias p) issued from 1 Jan $from to 31 Dec $to; either end may be open. */
    private function rangeClause(?int $from, ?int $to): array
    {
        $sql = '';
        $bindings = [];
        if ($from) {
            $sql .= ' AND p.issued_date >= ?';
            $bindings[] = "{$from}-01-01";
        }
        if ($to) {
            $sql .= ' AND p.issued_date < ?';
            $bindings[] = ($to + 1).'-01-01';
        }

        return [$sql, $bindings];
    }

    private function floatRows(array $rows, array $numeric): array
    {
        return array_map(function ($row) use ($numeric) {
            $row = (array) $row;
            foreach ($numeric as $key) {
                $row[$key] = (float) ($row[$key] ?? 0);
            }

            return $row;
        }, $rows);
    }
}
