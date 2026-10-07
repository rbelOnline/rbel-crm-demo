<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Goals' current_amount is now derived from policy APE instead of typed in.
 * Recompute existing open goals once; afterwards policy saves keep them current
 * (App\Services\GoalProgressService). The SQL is inlined against the schema as it
 * was at this point (goals.person_id), so later schema changes don't break it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE goals g
            SET g.current_amount = CASE
                WHEN g.person_id IS NOT NULL THEN (
                    SELECT COALESCE(SUM(p.ape), 0) FROM policies p
                    WHERE p.policy_owner_id = g.person_id AND p.status IN ('pending', 'active'))
                ELSE (
                    SELECT COALESCE(SUM(p.ape), 0) FROM policies p
                    WHERE p.status <> 'cancelled' AND p.issued_date BETWEEN DATE(g.created_at) AND g.target_date)
            END
            WHERE g.status IN ('not_started', 'in_progress')");
    }

    public function down(): void
    {
        // Data-only change; previous manual values are not recoverable.
    }
};
