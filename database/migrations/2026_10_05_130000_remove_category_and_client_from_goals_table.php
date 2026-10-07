<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goals no longer have a category or a related client: every goal counts the APE of
 * all policies issued in its date range (App\Services\GoalProgressService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('category');
        });

        // Goals that were linked to a client now count every policy in their range.
        DB::update("
            UPDATE goals g
            SET g.current_amount = (
                SELECT COALESCE(SUM(p.ape), 0) FROM policies p
                WHERE p.status <> 'postponed' AND p.issued_date BETWEEN g.start_date AND g.target_date)
            WHERE g.status IN ('not_started', 'in_progress')");
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('category', ['sales', 'clients', 'savings', 'retirement', 'education', 'other'])->default('sales')->after('description');
        });
    }
};
