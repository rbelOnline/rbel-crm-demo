<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Churn now comes only from policies that ended badly: Terminated, Lapsed or
 * Surrendered. vw_client_policy_overview.client_status (from the policies a client OWNS):
 *  - active:    at least one in-force policy (pending, cooling_off, active);
 *  - inactive:  churned — none in force, and at least one terminated / lapsed / surrendered;
 *  - completed: none in force or churned, but at least one matured;
 *  - prospect:  no policy, or only postponed ones (never issued).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->recreate(<<<'SQL'
    CASE
        WHEN COALESCE(o.in_force_owned, 0) > 0 THEN 'active'
        WHEN COALESCE(o.churned_owned, 0) > 0  THEN 'inactive'
        WHEN COALESCE(o.matured_owned, 0) > 0  THEN 'completed'
        ELSE 'prospect'
    END
SQL);
    }

    public function down(): void
    {
        $this->recreate(<<<'SQL'
    CASE
        WHEN COALESCE(o.policies_owned, 0) = 0 THEN 'prospect'
        WHEN o.in_force_owned > 0              THEN 'active'
        ELSE 'inactive'
    END
SQL);
    }

    private function recreate(string $clientStatus): void
    {
        DB::unprepared('DROP VIEW IF EXISTS vw_client_policy_overview');

        // 2. Per-client roll-up across both roles, with the churn status.
        DB::unprepared(str_replace('__CLIENT_STATUS__', $clientStatus, <<<'SQL'
CREATE VIEW vw_client_policy_overview AS
SELECT
    c.id                                            AS client_id,
    c.first_name,
    c.middle_name,
    c.last_name,
    c.email,
    c.mobile_number,
    c.gender,
    c.birthdate,
    TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE())     AS age,
    c.is_policy_owner,
    COALESCE(o.policies_owned, 0)                   AS policies_owned,
    COALESCE(o.in_force_owned, 0)                   AS in_force_owned,
    COALESCE(o.churned_owned, 0)                    AS churned_owned,
    COALESCE(o.total_ape_owned, 0)                  AS total_ape_owned,
    o.first_policy_date,
    o.last_policy_date,
    o.last_status_change_at,
    COALESCE(ins.policies_insured, 0)               AS policies_insured,
    COALESCE(ins.in_force_insured, 0)               AS in_force_insured,
    COALESCE(ins.total_sum_assured_insured, 0)      AS total_sum_assured_insured,
__CLIENT_STATUS__                                   AS client_status
FROM clients c
LEFT JOIN (
    SELECT policy_owner_id,
           COUNT(*)                                                 AS policies_owned,
           SUM(status IN ('active', 'pending', 'cooling_off'))      AS in_force_owned,
           SUM(status IN ('terminated', 'lapsed', 'surrendered'))   AS churned_owned,
           SUM(status = 'matured')                                  AS matured_owned,
           SUM(ape)                                                 AS total_ape_owned,
           MIN(issued_date)                                         AS first_policy_date,
           MAX(issued_date)                                         AS last_policy_date,
           MAX(status_changed_at)                                   AS last_status_change_at
    FROM policies
    GROUP BY policy_owner_id
) o ON o.policy_owner_id = c.id
LEFT JOIN (
    SELECT policy_insured_id,
           COUNT(*)                                                 AS policies_insured,
           SUM(status IN ('active', 'pending', 'cooling_off'))      AS in_force_insured,
           SUM(sum_assured)                                         AS total_sum_assured_insured
    FROM policies
    GROUP BY policy_insured_id
) ins ON ins.policy_insured_id = c.id
SQL));
    }
};
