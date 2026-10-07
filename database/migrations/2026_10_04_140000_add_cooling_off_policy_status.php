<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Policy status Cooling Off: the free-look period after a policy is issued, when
 * the client may still return it. The policy is issued and covers the client, so
 * it counts as in force (with pending and active): the owner is an active client,
 * it shows in pending delivery until delivered, counts toward client goals and has
 * premium due dates.
 *
 * The reporting views that list in-force statuses are recreated with it:
 * vw_client_policy_overview, vw_policy_delivery_monitoring and vw_premiums_due.
 */
return new class extends Migration
{
    private const WITH = "'pending','cooling_off','active','postponed','lapsed','surrendered','matured','terminated'";

    private const WITHOUT = "'pending','active','postponed','lapsed','surrendered','matured','terminated'";

    public function up(): void
    {
        DB::statement('ALTER TABLE policies MODIFY status ENUM('.self::WITH.") NOT NULL DEFAULT 'pending'");

        $this->recreateViews("'active', 'pending', 'cooling_off'", "'active', 'cooling_off'");
    }

    public function down(): void
    {
        DB::table('policies')->where('status', 'cooling_off')->update(['status' => 'active']);
        DB::statement('ALTER TABLE policies MODIFY status ENUM('.self::WITHOUT.") NOT NULL DEFAULT 'pending'");

        $this->recreateViews("'active', 'pending'", "'active'");
    }

    /** $inForce: statuses that count as in force; $premium: statuses with premiums falling due. */
    private function recreateViews(string $inForce, string $premium): void
    {
        DB::unprepared('DROP VIEW IF EXISTS vw_client_policy_overview, vw_policy_delivery_monitoring, vw_premiums_due');

        $run = fn (string $sql) => DB::unprepared(str_replace(['__IN_FORCE__', '__PREMIUM__'], [$inForce, $premium], $sql));

        // 2. Per-client roll-up across both roles, with the churn status.
        $run(<<<'SQL'
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
    COALESCE(o.total_ape_owned, 0)                  AS total_ape_owned,
    o.first_policy_date,
    o.last_policy_date,
    o.last_status_change_at,
    COALESCE(ins.policies_insured, 0)               AS policies_insured,
    COALESCE(ins.in_force_insured, 0)               AS in_force_insured,
    COALESCE(ins.total_sum_assured_insured, 0)      AS total_sum_assured_insured,
    CASE
        WHEN COALESCE(o.policies_owned, 0) = 0 THEN 'prospect'
        WHEN o.in_force_owned > 0              THEN 'active'
        ELSE 'inactive'
    END                                             AS client_status
FROM clients c
LEFT JOIN (
    SELECT policy_owner_id,
           COUNT(*)                                     AS policies_owned,
           SUM(status IN (__IN_FORCE__))         AS in_force_owned,
           SUM(ape)                                     AS total_ape_owned,
           MIN(issued_date)                             AS first_policy_date,
           MAX(issued_date)                             AS last_policy_date,
           MAX(status_changed_at)                       AS last_status_change_at
    FROM policies
    GROUP BY policy_owner_id
) o ON o.policy_owner_id = c.id
LEFT JOIN (
    SELECT policy_insured_id,
           COUNT(*)                                     AS policies_insured,
           SUM(status IN (__IN_FORCE__))         AS in_force_insured,
           SUM(sum_assured)                             AS total_sum_assured_insured
    FROM policies
    GROUP BY policy_insured_id
) ins ON ins.policy_insured_id = c.id
SQL);

        // 4. Delivery monitoring: issued/in-force policies not yet delivered.
        $run(<<<'SQL'
CREATE VIEW vw_policy_delivery_monitoring AS
SELECT p.id                                   AS policy_id,
       p.policy_number,
       p.status,
       p.issued_date,
       DATEDIFF(CURDATE(), p.issued_date)     AS days_pending,
       CASE
           WHEN DATEDIFF(CURDATE(), p.issued_date) <= 7  THEN '0-7 days'
           WHEN DATEDIFF(CURDATE(), p.issued_date) <= 14 THEN '8-14 days'
           WHEN DATEDIFF(CURDATE(), p.issued_date) <= 30 THEN '15-30 days'
           ELSE 'Over 30 days'
       END                                    AS aging_bucket,
       pr.name                                AS product_name,
       p.policy_owner_id,
       CONCAT_WS(' ', o.first_name, o.middle_name, o.last_name) AS owner_name,
       p.policy_insured_id,
       CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name) AS insured_name
FROM policies p
JOIN products pr ON pr.id = p.product_id
JOIN clients  o  ON o.id  = p.policy_owner_id
JOIN clients  i  ON i.id  = p.policy_insured_id
WHERE p.policy_delivery_date IS NULL
  AND p.status IN (__IN_FORCE__)
SQL);

        // 6. Next premium due date per active, non-single-pay policy.
        //    DATE_ADD(... MONTH) clamps month-ends (Jan 31 -> Feb 28).
        $run(<<<'SQL'
CREATE VIEW vw_premiums_due AS
SELECT d.policy_id, d.policy_number, d.mode_of_payment, d.issued_date, d.ape,
       ROUND(d.ape * d.step_months / 12, 2)               AS modal_premium,
       d.policy_owner_id, d.owner_name, d.product_name,
       DATE_ADD(d.issued_date, INTERVAL (d.periods + (d.candidate < CURDATE() OR d.periods = 0)) * d.step_months MONTH) AS next_due_date
FROM (
    SELECT s.*,
           DATE_ADD(s.issued_date, INTERVAL s.periods * s.step_months MONTH) AS candidate
    FROM (
        SELECT p.id AS policy_id, p.policy_number, p.mode_of_payment, p.issued_date, p.ape,
               p.policy_owner_id, CONCAT_WS(' ', o.first_name, o.middle_name, o.last_name) AS owner_name, pr.name AS product_name,
               m.step_months,
               TIMESTAMPDIFF(MONTH, p.issued_date, CURDATE()) DIV m.step_months AS periods
        FROM policies p
        JOIN (
            SELECT 'monthly' AS mode, 1 AS step_months UNION ALL
            SELECT 'quarterly', 3 UNION ALL
            SELECT 'semi_annual', 6 UNION ALL
            SELECT 'annual', 12
        ) m ON m.mode = p.mode_of_payment
        JOIN clients  o  ON o.id  = p.policy_owner_id
        JOIN products pr ON pr.id = p.product_id
        WHERE p.status IN (__PREMIUM__)
    ) s
) d
SQL);

    }
};
