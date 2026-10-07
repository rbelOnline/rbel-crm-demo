<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Policy statuses: add Terminated and Postponed, remove Cancelled.
 *
 *  - Postponed (application postponed, never issued) is now the status that is
 *    NOT a sale, as Cancelled was: sales, product/mode mix, age analytics and
 *    advisor goals exclude it.
 *  - Terminated is a closed policy, like Surrendered and Matured.
 *  - Existing Cancelled policies become Terminated.
 *
 * The reporting objects that name the excluded status (vw_monthly_sales,
 * sp_monthly_sales, sp_age_distribution) are recreated with it.
 */
return new class extends Migration
{
    private const NEW_STATUSES = "'pending','active','postponed','lapsed','surrendered','matured','terminated'";

    private const OLD_STATUSES = "'pending','active','lapsed','surrendered','matured','cancelled'";

    public function up(): void
    {
        DB::statement("ALTER TABLE policies MODIFY status ENUM(".self::OLD_STATUSES.",'terminated','postponed') NOT NULL DEFAULT 'pending'");
        DB::table('policies')->where('status', 'cancelled')->update(['status' => 'terminated']);
        DB::statement("ALTER TABLE policies MODIFY status ENUM(".self::NEW_STATUSES.") NOT NULL DEFAULT 'pending'");

        $this->recreateReporting('postponed');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE policies MODIFY status ENUM(".self::NEW_STATUSES.",'cancelled') NOT NULL DEFAULT 'pending'");
        DB::table('policies')->whereIn('status', ['terminated', 'postponed'])->update(['status' => 'cancelled']);
        DB::statement("ALTER TABLE policies MODIFY status ENUM(".self::OLD_STATUSES.") NOT NULL DEFAULT 'pending'");

        $this->recreateReporting('cancelled');
    }

    /** vw_monthly_sales, sp_monthly_sales and sp_age_distribution, excluding $notSold from sales. */
    private function recreateReporting(string $notSold): void
    {
        DB::unprepared('DROP VIEW IF EXISTS vw_monthly_sales');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_monthly_sales');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_age_distribution');

        $run = fn (string $sql) => DB::unprepared(str_replace('__NOT_SOLD__', $notSold, $sql));

        // 3. Monthly sales with window functions (running YTD, rank, MoM).
        $run(<<<'SQL'
CREATE VIEW vw_monthly_sales AS
WITH monthly AS (
    SELECT YEAR(issued_date)  AS sales_year,
           MONTH(issued_date) AS sales_month,
           COUNT(*)           AS policy_count,
           SUM(ape)           AS total_ape,
           SUM(sum_assured)   AS total_sum_assured
    FROM policies
    WHERE status <> '__NOT_SOLD__'
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

        // Stored procedure: 12 gap-filled months of sales for a year, optionally
        // scoped to one client *in a specific role* (owner or insured).
        $run(<<<'SQL'
CREATE PROCEDURE sp_monthly_sales(IN p_year SMALLINT, IN p_role VARCHAR(10), IN p_client_id BIGINT UNSIGNED)
COMMENT 'Jan-Dec APE/policy count for p_year. p_role: owner|insured (only used when p_client_id is not NULL).'
BEGIN
    IF p_client_id IS NOT NULL AND (p_role IS NULL OR p_role NOT IN ('owner', 'insured')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_role must be owner or insured when p_client_id is given';
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
          AND p.status <> '__NOT_SOLD__'
          AND (p_client_id IS NULL
               OR (p_role = 'owner'   AND p.policy_owner_id   = p_client_id)
               OR (p_role = 'insured' AND p.policy_insured_id = p_client_id))
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

        // Stored procedure: distribution of *distinct clients* across the
        // configurable age_ranges, for ONE role. Age is computed as of
        // 31 Dec of p_year (or today, whichever is earlier).
        $run(<<<'SQL'
CREATE PROCEDURE sp_age_distribution(IN p_year SMALLINT, IN p_role VARCHAR(10))
COMMENT 'Age-range distribution of policy owners OR policy insureds (p_role: owner|insured).'
BEGIN
    DECLARE v_as_of DATE;

    IF p_role IS NULL OR p_role NOT IN ('owner', 'insured') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p_role must be owner or insured';
    END IF;

    SET v_as_of = IF(p_year IS NULL, CURDATE(), LEAST(CURDATE(), MAKEDATE(p_year, 1) + INTERVAL 1 YEAR - INTERVAL 1 DAY));

    WITH scoped AS (
        SELECT CASE WHEN p_role = 'owner' THEN p.policy_owner_id ELSE p.policy_insured_id END AS client_id,
               p.ape
        FROM policies p
        WHERE p.status <> '__NOT_SOLD__'
          AND (p_year IS NULL OR (p.issued_date >= MAKEDATE(p_year, 1) AND p.issued_date < MAKEDATE(p_year + 1, 1)))
    ),
    per_client AS (
        SELECT s.client_id,
               COUNT(*)   AS policy_count,
               SUM(s.ape) AS total_ape,
               TIMESTAMPDIFF(YEAR, c.birthdate, v_as_of) AS age
        FROM scoped s
        JOIN clients c ON c.id = s.client_id
        GROUP BY s.client_id, c.birthdate
    ),
    bucketed AS (
        SELECT ar.id AS range_id, ar.label, ar.sort_order,
               COUNT(pc.client_id)               AS person_count,
               COALESCE(SUM(pc.policy_count), 0) AS policy_count,
               COALESCE(SUM(pc.total_ape), 0)    AS total_ape
        FROM age_ranges ar
        LEFT JOIN per_client pc
               ON pc.age >= ar.min_age AND (ar.max_age IS NULL OR pc.age <= ar.max_age)
        GROUP BY ar.id, ar.label, ar.sort_order
        UNION ALL
        SELECT NULL, 'Unknown', 255, COUNT(*), COALESCE(SUM(policy_count), 0), COALESCE(SUM(total_ape), 0)
        FROM per_client WHERE age IS NULL
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
};
