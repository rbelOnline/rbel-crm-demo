<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Runs EXPLAIN (or EXPLAIN ANALYZE) on the hot queries so index usage can be
 * checked after schema or data changes. See docs/PERFORMANCE.md.
 */
#[Signature('crm:explain {--analyze : Use EXPLAIN ANALYZE (executes the queries)} {--only= : Run a single named query}')]
#[Description('Show MySQL execution plans for the CRM\'s most frequent queries')]
class ExplainQueries extends Command
{
    public function handle(): int
    {
        $clientId = (int) DB::table('policies')->value('policy_owner_id');
        $year = (int) now()->year;

        $queries = [
            'policies-by-owner' => [
                'Policy list filtered by Policy Owner (uses policies_policy_owner_id_issued_date_index)',
                'SELECT policies.* FROM policies
                 JOIN clients owner ON owner.id = policies.policy_owner_id
                 JOIN clients insured ON insured.id = policies.policy_insured_id
                 WHERE policies.policy_owner_id = ? ORDER BY policies.issued_date DESC LIMIT 15',
                [$clientId],
            ],
            'policies-by-insured' => [
                'Policy list filtered by Policy Insured (uses policies_policy_insured_id_issued_date_index)',
                'SELECT policies.* FROM policies
                 JOIN clients owner ON owner.id = policies.policy_owner_id
                 JOIN clients insured ON insured.id = policies.policy_insured_id
                 WHERE policies.policy_insured_id = ? ORDER BY policies.issued_date DESC LIMIT 15',
                [$clientId],
            ],
            'policies-by-year' => [
                'Year filter as a sargable issued_date range (not YEAR(issued_date) = ?)',
                'SELECT COUNT(*) FROM policies WHERE issued_date >= ? AND issued_date < ?',
                ["{$year}-01-01", ($year + 1).'-01-01'],
            ],
            'policy-number-prefix' => [
                'Policy number prefix search (unique index range scan)',
                'SELECT id FROM policies WHERE policy_number LIKE ? LIMIT 15',
                ['RB-2026%'],
            ],
            'birthdays-in-month' => [
                'Birthdays in a month via MONTH(birthdate) (full scan of clients)',
                'SELECT id FROM clients WHERE MONTH(birthdate) = 9 ORDER BY DAY(birthdate)',
                [],
            ],
            'anniversaries' => [
                'Anniversaries via the generated issued_month column',
                "SELECT id FROM policies WHERE issued_month = 9 AND status IN ('active','pending','cooling_off') AND issued_date < ?",
                ["{$year}-01-01"],
            ],
            'pending-delivery' => [
                'Pending delivery KPI',
                "SELECT COUNT(*) FROM policies WHERE policy_delivery_date IS NULL AND status IN ('active','pending','cooling_off')",
                [],
            ],
            'monthly-sales-view' => [
                'Monthly sales view (CTE + window functions)',
                'SELECT * FROM vw_monthly_sales WHERE sales_year = ?',
                [$year],
            ],
        ];

        $only = $this->option('only');
        $prefix = $this->option('analyze') ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ';

        foreach ($queries as $name => [$title, $sql, $bindings]) {
            if ($only && $only !== $name) {
                continue;
            }

            $this->newLine();
            $this->components->twoColumnDetail("<fg=cyan>{$name}</>", $title);

            $rows = DB::select($prefix.$sql, $bindings);

            if ($this->option('analyze')) {
                // EXPLAIN ANALYZE returns a single tree-formatted text column.
                $this->line((string) array_values((array) $rows[0])[0]);

                continue;
            }

            $this->table(
                ['table', 'type', 'key', 'rows', 'filtered', 'Extra'],
                array_map(fn ($r) => [$r->table, $r->type, $r->key ?? '—', $r->rows, $r->filtered, $r->Extra], $rows),
            );
        }

        return self::SUCCESS;
    }
}
