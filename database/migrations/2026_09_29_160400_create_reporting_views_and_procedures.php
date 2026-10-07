<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reporting layer (MySQL 8+): views for reusable read models, and stored
 * procedures for the two analytics that need a parameterised, gap-filled
 * result set (12-month sales and age-range distribution).
 *
 * Business definitions used throughout (keep in sync with docs/ANALYTICS.md):
 *  - Sale            = a policy that is not 'cancelled', counted in the month of issued_date.
 *  - In-force        = status IN ('active', 'pending').
 *  - Active client   = owns >= 1 in-force policy.
 *  - Inactive/churned client = has owned >= 1 policy but owns 0 in-force policies.
 *  - Prospect        = a client record that owns no policy yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropAll();

        // 1. Policy overview: policy + owner + insured + product + beneficiaries.
        DB::unprepared(<<<'SQL'
CREATE VIEW vw_policy_overview AS
SELECT
    p.id                                              AS policy_id,
    p.policy_number,
    p.status,
    p.issued_date,
    YEAR(p.issued_date)                               AS issued_year,
    p.issued_month,
    p.ape,
    p.sum_assured,
    p.mode_of_payment,
    p.policy_delivery_date,
    p.is_orphan,
    pr.id                                             AS product_id,
    pr.name                                           AS product_name,
    pr.category                                       AS product_category,
    o.id                                              AS owner_id,
    o.full_name                                       AS owner_name,
    o.gender                                          AS owner_gender,
    TIMESTAMPDIFF(YEAR, o.birthdate, CURDATE())       AS owner_age,
    i.id                                              AS insured_id,
    i.full_name                                       AS insured_name,
    i.gender                                          AS insured_gender,
    TIMESTAMPDIFF(YEAR, i.birthdate, CURDATE())       AS insured_age,
    TIMESTAMPDIFF(YEAR, i.birthdate, p.issued_date)   AS insured_age_at_issue,
    (p.policy_owner_id = p.policy_insured_id)         AS is_self_insured,
    COALESCE(b.beneficiary_count, 0)                  AS beneficiary_count,
    b.beneficiary_names
FROM policies p
JOIN products pr ON pr.id = p.product_id
JOIN persons  o  ON o.id  = p.policy_owner_id
JOIN persons  i  ON i.id  = p.policy_insured_id
LEFT JOIN (
    SELECT pb.policy_id,
           COUNT(*) AS beneficiary_count,
           GROUP_CONCAT(bp.full_name ORDER BY pb.beneficiary_type, bp.last_name SEPARATOR ', ') AS beneficiary_names
    FROM policy_beneficiaries pb
    JOIN persons bp ON bp.id = pb.person_id
    GROUP BY pb.policy_id
) b ON b.policy_id = p.id
SQL);

        // 2. Per-person roll-up across all three roles, with the churn status.
        DB::unprepared(<<<'SQL'
CREATE VIEW vw_client_policy_overview AS
SELECT
    per.id                                          AS person_id,
    per.full_name,
    per.email,
    per.mobile_number,
    per.gender,
    per.birthdate,
    TIMESTAMPDIFF(YEAR, per.birthdate, CURDATE())   AS age,
    per.is_client,
    COALESCE(o.policies_owned, 0)                   AS policies_owned,
    COALESCE(o.in_force_owned, 0)                   AS in_force_owned,
    COALESCE(o.total_ape_owned, 0)                  AS total_ape_owned,
    o.first_policy_date,
    o.last_policy_date,
    o.last_status_change_at,
    COALESCE(ins.policies_insured, 0)               AS policies_insured,
    COALESCE(ins.in_force_insured, 0)               AS in_force_insured,
    COALESCE(ins.total_sum_assured_insured, 0)      AS total_sum_assured_insured,
    COALESCE(bn.beneficiary_designations, 0)        AS beneficiary_designations,
    CASE
        WHEN COALESCE(o.policies_owned, 0) = 0 THEN 'prospect'
        WHEN o.in_force_owned > 0              THEN 'active'
        ELSE 'inactive'
    END                                             AS client_status
FROM persons per
LEFT JOIN (
    SELECT policy_owner_id,
           COUNT(*)                                     AS policies_owned,
           SUM(status IN ('active', 'pending'))         AS in_force_owned,
           SUM(ape)                                     AS total_ape_owned,
           MIN(issued_date)                             AS first_policy_date,
           MAX(issued_date)                             AS last_policy_date,
           MAX(status_changed_at)                       AS last_status_change_at
    FROM policies
    GROUP BY policy_owner_id
) o ON o.policy_owner_id = per.id
LEFT JOIN (
    SELECT policy_insured_id,
           COUNT(*)                                     AS policies_insured,
           SUM(status IN ('active', 'pending'))         AS in_force_insured,
           SUM(sum_assured)                             AS total_sum_assured_insured
    FROM policies
    GROUP BY policy_insured_id
) ins ON ins.policy_insured_id = per.id
LEFT JOIN (
    SELECT person_id, COUNT(*) AS beneficiary_designations
    FROM policy_beneficiaries
    GROUP BY person_id
) bn ON bn.person_id = per.id
SQL);

        // 3. Monthly sales with window functions (running YTD, rank, MoM).
        DB::unprepared(<<<'SQL'
CREATE VIEW vw_monthly_sales AS
WITH monthly AS (
    SELECT YEAR(issued_date)  AS sales_year,
           MONTH(issued_date) AS sales_month,
           COUNT(*)           AS policy_count,
           SUM(ape)           AS total_ape,
           SUM(sum_assured)   AS total_sum_assured
    FROM policies
    WHERE status <> 'cancelled'
    GROUP BY YEAR(issued_date), MONTH(issued_date)
)
SELECT sales_year,
       sales_month,
       policy_count,
       total_ape,
       total_sum_assured,
       SUM(total_ape) OVER (PARTITION BY sales_year ORDER BY sales_month)   AS running_ape_ytd,
       RANK()         OVER (PARTITION BY sales_year ORDER BY total_ape DESC) AS ape_rank_in_year,
       LAG(total_ape) OVER (PARTITION BY sales_year ORDER BY sales_month)   AS prev_month_ape
FROM monthly
SQL);

        // 4. Delivery monitoring: issued/in-force policies not yet delivered.
        DB::unprepared(<<<'SQL'
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
       o.full_name                            AS owner_name,
       p.policy_insured_id,
       i.full_name                            AS insured_name
FROM policies p
JOIN products pr ON pr.id = p.product_id
JOIN persons  o  ON o.id  = p.policy_owner_id
JOIN persons  i  ON i.id  = p.policy_insured_id
WHERE p.policy_delivery_date IS NULL
  AND p.status IN ('active', 'pending')
SQL);

        // 5. Age analytics source: one row per (policy, role). The person_role
        //    column makes it impossible to mix owner and insured ages by accident.
        DB::unprepared(<<<'SQL'
CREATE VIEW vw_client_age_analytics AS
SELECT p.id AS policy_id, YEAR(p.issued_date) AS issued_year, p.status, p.ape,
       'owner' AS person_role, per.id AS person_id, per.gender, per.birthdate,
       TIMESTAMPDIFF(YEAR, per.birthdate, CURDATE()) AS age
FROM policies p
JOIN persons per ON per.id = p.policy_owner_id
UNION ALL
SELECT p.id, YEAR(p.issued_date), p.status, p.ape,
       'insured', per.id, per.gender, per.birthdate,
       TIMESTAMPDIFF(YEAR, per.birthdate, CURDATE())
FROM policies p
JOIN persons per ON per.id = p.policy_insured_id
SQL);

        // 6. Next premium due date per active, non-single-pay policy.
        //    DATE_ADD(... MONTH) clamps month-ends (Jan 31 -> Feb 28).
        DB::unprepared(<<<'SQL'
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
               p.policy_owner_id, o.full_name AS owner_name, pr.name AS product_name,
               m.step_months,
               TIMESTAMPDIFF(MONTH, p.issued_date, CURDATE()) DIV m.step_months AS periods
        FROM policies p
        JOIN (
            SELECT 'monthly' AS mode, 1 AS step_months UNION ALL
            SELECT 'quarterly', 3 UNION ALL
            SELECT 'semi_annual', 6 UNION ALL
            SELECT 'annual', 12
        ) m ON m.mode = p.mode_of_payment
        JOIN persons  o  ON o.id  = p.policy_owner_id
        JOIN products pr ON pr.id = p.product_id
        WHERE p.status = 'active'
    ) s
) d
SQL);

        // Stored procedure: 12 gap-filled months of sales for a year, optionally
        // scoped to one person *in a specific role* (owner or insured).
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_monthly_sales(IN p_year SMALLINT, IN p_role VARCHAR(10), IN p_person_id BIGINT UNSIGNED)
COMMENT 'Jan-Dec APE/policy count for p_year. p_role: owner|insured (only used when p_person_id is not NULL).'
BEGIN
    IF p_person_id IS NOT NULL AND (p_role IS NULL OR p_role NOT IN ('owner', 'insured')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_role must be owner or insured when p_person_id is given';
    END IF;

    WITH RECURSIVE months AS (
        SELECT 1 AS m
        UNION ALL
        SELECT m + 1 FROM months WHERE m < 12
    ),
    scoped AS (
        SELECT MONTH(p.issued_date) AS m, p.ape
        FROM policies p
        WHERE p.issued_date >= MAKEDATE(p_year, 1)
          AND p.issued_date <  MAKEDATE(p_year + 1, 1)
          AND p.status <> 'cancelled'
          AND (p_person_id IS NULL
               OR (p_role = 'owner'   AND p.policy_owner_id   = p_person_id)
               OR (p_role = 'insured' AND p.policy_insured_id = p_person_id))
    ),
    agg AS (
        SELECT months.m,
               COUNT(scoped.ape)            AS policy_count,
               COALESCE(SUM(scoped.ape), 0) AS total_ape
        FROM months
        LEFT JOIN scoped ON scoped.m = months.m
        GROUP BY months.m
    )
    SELECT m                                                         AS month,
           policy_count,
           total_ape,
           SUM(total_ape) OVER (ORDER BY m)                          AS running_total,
           CASE WHEN total_ape > 0
                THEN DENSE_RANK() OVER (ORDER BY total_ape DESC) END AS sales_rank,
           LAG(total_ape) OVER (ORDER BY m)                          AS prev_month_ape
    FROM agg
    ORDER BY m;
END
SQL);

        // Stored procedure: distribution of *distinct persons* across the
        // configurable age_ranges, for ONE role. Age is computed as of
        // 31 Dec of p_year (or today, whichever is earlier).
        DB::unprepared(<<<'SQL'
CREATE PROCEDURE sp_age_distribution(IN p_year SMALLINT, IN p_role VARCHAR(10))
COMMENT 'Age-range distribution of policy owners OR policy insureds (p_role: owner|insured).'
BEGIN
    DECLARE v_as_of DATE;

    IF p_role IS NULL OR p_role NOT IN ('owner', 'insured') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_role must be owner or insured';
    END IF;

    SET v_as_of = IF(p_year IS NULL, CURDATE(), LEAST(CURDATE(), MAKEDATE(p_year, 1) + INTERVAL 1 YEAR - INTERVAL 1 DAY));

    WITH scoped AS (
        SELECT CASE WHEN p_role = 'owner' THEN p.policy_owner_id ELSE p.policy_insured_id END AS person_id,
               p.ape
        FROM policies p
        WHERE p.status <> 'cancelled'
          AND (p_year IS NULL OR (p.issued_date >= MAKEDATE(p_year, 1) AND p.issued_date < MAKEDATE(p_year + 1, 1)))
    ),
    per_person AS (
        SELECT s.person_id,
               COUNT(*)   AS policy_count,
               SUM(s.ape) AS total_ape,
               TIMESTAMPDIFF(YEAR, per.birthdate, v_as_of) AS age
        FROM scoped s
        JOIN persons per ON per.id = s.person_id
        GROUP BY s.person_id, per.birthdate
    ),
    bucketed AS (
        SELECT ar.id AS range_id, ar.label, ar.sort_order,
               COUNT(pp.person_id)            AS person_count,
               COALESCE(SUM(pp.policy_count), 0) AS policy_count,
               COALESCE(SUM(pp.total_ape), 0)    AS total_ape
        FROM age_ranges ar
        LEFT JOIN per_person pp
               ON pp.age >= ar.min_age AND (ar.max_age IS NULL OR pp.age <= ar.max_age)
        GROUP BY ar.id, ar.label, ar.sort_order
        UNION ALL
        SELECT NULL, 'Unknown', 255, COUNT(*), COALESCE(SUM(policy_count), 0), COALESCE(SUM(total_ape), 0)
        FROM per_person WHERE age IS NULL
        HAVING COUNT(*) > 0
    )
    SELECT range_id, label, person_count, policy_count, total_ape,
           ROUND(100 * person_count / NULLIF(SUM(person_count) OVER (), 0), 2) AS pct,
           v_as_of AS as_of_date
    FROM bucketed
    ORDER BY sort_order;
END
SQL);
    }

    public function down(): void
    {
        $this->dropAll();
    }

    private function dropAll(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_monthly_sales');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_age_distribution');
        DB::unprepared('DROP VIEW IF EXISTS vw_policy_overview, vw_client_policy_overview, vw_monthly_sales,
            vw_policy_delivery_monitoring, vw_client_age_analytics, vw_premiums_due');
    }
};
