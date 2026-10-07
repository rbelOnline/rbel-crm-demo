<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goals cover a date range: start_date ("Date from") to target_date ("Date to").
 * current_amount = APE of policies issued in that range, not postponed; for a goal
 * linked to a client, only policies that client owns (App\Services\GoalProgressService).
 * Existing goals start on the day they were created, the start they used before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('current_amount');
        });

        DB::statement('UPDATE goals SET start_date = DATE(created_at) WHERE start_date IS NULL');
        // Ranges whose old start would come after the target start on the target date instead.
        DB::statement('UPDATE goals SET start_date = target_date WHERE start_date > target_date');

        Schema::table('goals', function (Blueprint $table) {
            $table->date('start_date')->nullable(false)->change();
            $table->index(['start_date', 'target_date']);
        });

        // Recompute open goals with the range-based definition (inlined, as it is at this point).
        DB::update("
            UPDATE goals g
            SET g.current_amount = (
                SELECT COALESCE(SUM(p.ape), 0) FROM policies p
                WHERE p.status <> 'postponed'
                  AND p.issued_date BETWEEN g.start_date AND g.target_date
                  AND (g.client_id IS NULL OR p.policy_owner_id = g.client_id))
            WHERE g.status IN ('not_started', 'in_progress')");
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropIndex(['start_date', 'target_date']);
            $table->dropColumn('start_date');
        });
    }
};
