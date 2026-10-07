<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate tables for Policy Owners, Policy Insureds and Leads.
 *
 * Personal details (name, birthdate, contact, address, …) stay in ONE table,
 * `persons`, so they are never repeated when the same person is both an owner and
 * an insured, or moves from lead to client. Each role table is keyed by the
 * person's id, so policies.policy_owner_id / policy_insured_id keep their values
 * and now reference the role tables instead of persons directly. The rows are
 * kept in sync by App\Services\PersonRoles.
 */
return new class extends Migration
{
    private const ROLES = ['policy_owners', 'policy_insureds', 'leads'];

    public function up(): void
    {
        foreach (self::ROLES as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->foreignId('person_id')->primary()->constrained('persons')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        $now = now()->toDateTimeString();
        DB::statement("INSERT INTO policy_owners (person_id, created_at, updated_at)
            SELECT policy_owner_id, MIN(created_at), '{$now}' FROM policies GROUP BY policy_owner_id");
        DB::statement("INSERT INTO policy_insureds (person_id, created_at, updated_at)
            SELECT policy_insured_id, MIN(created_at), '{$now}' FROM policies GROUP BY policy_insured_id");
        // Leads: clients with no policy yet, as neither owner nor insured.
        DB::statement("INSERT INTO leads (person_id, created_at, updated_at)
            SELECT p.id, p.created_at, '{$now}' FROM persons p
            WHERE p.is_client = 1
              AND NOT EXISTS (SELECT 1 FROM policies x WHERE x.policy_owner_id = p.id OR x.policy_insured_id = p.id)");

        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['policy_owner_id']);
            $table->dropForeign(['policy_insured_id']);
            $table->foreign('policy_owner_id')->references('person_id')->on('policy_owners')->restrictOnDelete();
            $table->foreign('policy_insured_id')->references('person_id')->on('policy_insureds')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['policy_owner_id']);
            $table->dropForeign(['policy_insured_id']);
            $table->foreign('policy_owner_id')->references('id')->on('persons')->restrictOnDelete();
            $table->foreign('policy_insured_id')->references('id')->on('persons')->restrictOnDelete();
        });

        foreach (array_reverse(self::ROLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
