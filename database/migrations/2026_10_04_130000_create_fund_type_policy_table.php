<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A policy can hold several fund types (e.g. a VUL split across funds), so the
 * single policies.fund_type_id becomes the fund_type_policy link table. Existing
 * choices are carried over. Deleting a policy removes its links; a fund type in
 * use still cannot be deleted (RESTRICT; mark it inactive instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_type_policy', function (Blueprint $table) {
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('fund_type_id')->constrained('fund_types')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['policy_id', 'fund_type_id']);
            $table->index('fund_type_id');
        });

        $now = now()->toDateTimeString();
        DB::statement("INSERT INTO fund_type_policy (policy_id, fund_type_id, created_at, updated_at)
            SELECT id, fund_type_id, '{$now}', '{$now}' FROM policies WHERE fund_type_id IS NOT NULL");

        Schema::table('policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->foreignId('fund_type_id')->nullable()->after('sum_assured')->constrained('fund_types')->restrictOnDelete();
        });

        // Only one fund type fits back into the column: keep the first.
        DB::statement('UPDATE policies p JOIN (SELECT policy_id, MIN(fund_type_id) AS fund_type_id FROM fund_type_policy GROUP BY policy_id) f
            ON f.policy_id = p.id SET p.fund_type_id = f.fund_type_id');

        Schema::dropIfExists('fund_type_policy');
    }
};
