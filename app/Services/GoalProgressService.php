<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Policy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A goal's current_amount is derived, never typed in: the APE of all policies (Clients
 * module records) issued from its start_date ("Date from") through its target_date
 * ("Date to"), excluding postponed ones.
 *
 * amountFor() and refreshOpenGoals() implement the same definition; keep them in step.
 */
class GoalProgressService
{
    public const OPEN_STATUSES = ['not_started', 'in_progress'];

    public function amountFor(CarbonInterface $start, CarbonInterface $end): float
    {
        return round((float) Policy::query()
            ->where('status', '<>', Policy::NOT_SOLD_STATUS)
            ->whereBetween('issued_date', [$start->toDateString(), $end->toDateString()])
            ->sum('ape'), 2);
    }

    public function amountForGoal(Goal $goal): float
    {
        return $this->amountFor($goal->start_date ?? today(), $goal->target_date);
    }

    /**
     * Recompute every open goal in one statement. A raw update on purpose:
     * derived values changing because a policy changed are not goal edits,
     * so they are not written to the audit log.
     */
    public function refreshOpenGoals(): void
    {
        $open = implode(',', array_map(fn ($s) => DB::getPdo()->quote($s), self::OPEN_STATUSES));

        DB::update("
            UPDATE goals g
            SET g.current_amount = (
                SELECT COALESCE(SUM(p.ape), 0) FROM policies p
                WHERE p.status <> ? AND p.issued_date BETWEEN g.start_date AND g.target_date)
            WHERE g.status IN ({$open})", [Policy::NOT_SOLD_STATUS]);
    }
}
