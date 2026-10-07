<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the shared `persons` table (and its role tables policy_owners,
 * policy_insureds, leads, plus `addresses`) with three self-contained tables:
 *
 *  - clients:       people on a policy. is_policy_owner marks who may OWN a policy;
 *                   any client may be the insured. A self-insured policy points both
 *                   policy_owner_id and policy_insured_id at the same client row.
 *  - leads:         prospects with no policy, with their own details and notes.
 *                   A lead is converted into a client (App\Services\LeadConverter).
 *  - beneficiaries: one row per beneficiary designation on a policy, with the
 *                   beneficiary's own details.
 *
 * Display names and birthday keys are no longer stored (no full_name / birth_md);
 * the SPA concatenates names, and queries use MONTH(birthdate).
 *
 * Deleting a client deletes everything that belongs to them: the policies they own
 * or are insured under (and through those, beneficiaries, documents and policy
 * reminders), plus their appointments, reminders, goals and email logs.
 *
 * Existing data is carried over: persons who own or are insured under a policy
 * become clients (same ids), lead persons become leads, beneficiary persons are
 * copied onto their designations.
 */
return new class extends Migration
{
    /** Tables whose person_id becomes client_id. */
    private const ACTIVITY_TABLES = ['appointments', 'goals', 'reminders', 'email_logs'];

    public function up(): void
    {
        $this->dropReporting();

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('occupation', 120)->nullable();
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email', 191)->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->string('address', 255)->nullable();
            // Owners and insureds are told apart by this flag; only flagged clients may own a policy.
            $table->boolean('is_policy_owner')->default(false);
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('first_name');
            $table->index('birthdate');
            $table->index('email');
            $table->index('mobile_number');
            $table->index(['is_policy_owner', 'last_name']);
        });

        DB::statement('INSERT INTO clients (id, first_name, middle_name, last_name, occupation, birthdate, gender, email, mobile_number, address, is_policy_owner, created_at, updated_at)
            SELECT p.id, p.first_name, p.middle_name, p.last_name, p.occupation, p.birthdate, p.gender, p.email, p.mobile_number, a.address,
                   EXISTS (SELECT 1 FROM policies x WHERE x.policy_owner_id = p.id), p.created_at, p.updated_at
            FROM persons p
            LEFT JOIN addresses a ON a.id = p.address_id
            WHERE EXISTS (SELECT 1 FROM policies x WHERE x.policy_owner_id = p.id OR x.policy_insured_id = p.id)');

        // Leads: the old `leads` role table is replaced by one holding the lead's own details.
        Schema::create('leads_new', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('occupation', 120)->nullable();
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email', 191)->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('first_name');
            $table->index('birthdate');
            $table->index('email');
            $table->index('mobile_number');
        });

        DB::statement('INSERT INTO leads_new (first_name, middle_name, last_name, occupation, birthdate, gender, email, mobile_number, notes, created_at, updated_at)
            SELECT p.first_name, p.middle_name, p.last_name, p.occupation, p.birthdate, p.gender, p.email, p.mobile_number, p.notes, p.created_at, p.updated_at
            FROM persons p
            WHERE p.is_client = 1 AND NOT EXISTS (SELECT 1 FROM clients c WHERE c.id = p.id)
            ORDER BY p.id');

        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email', 191)->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->string('relationship', 40);
            $table->enum('beneficiary_type', ['primary', 'contingent'])->default('primary');
            $table->enum('designation', ['revocable', 'irrevocable'])->default('revocable');
            $table->decimal('allocation_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['policy_id', 'beneficiary_type']);
        });

        DB::statement('ALTER TABLE beneficiaries
            ADD CONSTRAINT beneficiaries_allocation_chk
            CHECK (allocation_percentage IS NULL OR (allocation_percentage > 0 AND allocation_percentage <= 100))');

        DB::statement('INSERT INTO beneficiaries (policy_id, first_name, middle_name, last_name, birthdate, gender, email, mobile_number,
                relationship, beneficiary_type, designation, allocation_percentage, created_at, updated_at)
            SELECT pb.policy_id, p.first_name, p.middle_name, p.last_name, p.birthdate, p.gender, p.email, p.mobile_number,
                   pb.relationship, pb.beneficiary_type, pb.designation, pb.allocation_percentage, pb.created_at, pb.updated_at
            FROM policy_beneficiaries pb
            JOIN persons p ON p.id = pb.person_id
            ORDER BY pb.id');

        Schema::dropIfExists('policy_beneficiaries');

        // Policies: owner / insured now reference clients; a deleted client takes their policies along.
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['policy_owner_id']);
            $table->dropForeign(['policy_insured_id']);
        });
        Schema::table('policies', function (Blueprint $table) {
            $table->foreign('policy_owner_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('policy_insured_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::dropIfExists('policy_owners');
        Schema::dropIfExists('policy_insureds');
        Schema::dropIfExists('leads');
        Schema::rename('leads_new', 'leads');

        foreach (self::ACTIVITY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['person_id']);
            });
            DB::statement("ALTER TABLE {$name} RENAME COLUMN person_id TO client_id");

            // Rows about someone who did not become a client (a lead or beneficiary).
            $orphans = DB::table($name)->whereNotNull('client_id')->whereNotIn('client_id', DB::table('clients')->select('id'));
            $name === 'appointments' ? $orphans->delete() : $orphans->update(['client_id' => null]);

            Schema::table($name, function (Blueprint $table) {
                $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            });
        }

        Schema::dropIfExists('persons');
        Schema::dropIfExists('addresses');

        $this->createReporting();
    }

    public function down(): void
    {
        throw new RuntimeException('Replacing persons with clients / leads / beneficiaries cannot be rolled back.');
    }

    private function dropReporting(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_monthly_sales');
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_age_distribution');
        DB::unprepared('DROP VIEW IF EXISTS vw_policy_overview, vw_client_policy_overview, vw_monthly_sales,
            vw_policy_delivery_monitoring, vw_client_age_analytics, vw_premiums_due');
    }

    /**
     * The reporting layer from 2026_09_29_160400, re-pointed at clients / beneficiaries.
     * Business definitions are unchanged (see docs/ANALYTICS.md).
     */
    private function createReporting(): void
    {
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
    CONCAT_WS(' ', o.first_name, o.middle_name, o.last_name) AS owner_name,
    o.gender                                          AS owner_gender,
    TIMESTAMPDIFF(YEAR, o.birthdate, CURDATE())       AS owner_age,
    i.id                                              AS insured_id,
    CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name) AS insured_name,
    i.gender                                          AS insured_gender,
    TIMESTAMPDIFF(YEAR, i.birthdate, CURDATE())       AS insured_age,
    TIMESTAMPDIFF(YEAR, i.birthdate, p.issued_date)   AS insured_age_at_issue,
    (p.policy_owner_id = p.policy_insured_id)         AS is_self_insured,
    COALESCE(b.beneficiary_count, 0)                  AS beneficiary_count,
    b.beneficiary_names
FROM policies p
JOIN products pr ON pr.id = p.product_id
JOIN clients  o  ON o.id  = p.policy_owner_id
JOIN clients  i  ON i.id  = p.policy_insured_id
LEFT JOIN (
    SELECT policy_id,
           COUNT(*) AS beneficiary_count,
           GROUP_CONCAT(CONCAT_WS(' ', first_name, middle_name, last_name) ORDER BY beneficiary_type, last_name SEPARATOR ', ') AS beneficiary_names
    FROM beneficiaries
    GROUP BY policy_id
) b ON b.policy_id = p.id
SQL);

        // 2. Per-client roll-up across both roles, with the churn status.
        DB::unprepared(<<<'SQL'
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
           SUM(status IN ('active', 'pending'))         AS in_force_owned,
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
           SUM(status IN ('active', 'pending'))         AS in_force_insured,
           SUM(sum_assured)                             AS total_sum_assured_insured
    FROM policies
    GROUP BY policy_insured_id
) ins ON ins.policy_insured_id = c.id
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
       CONCAT_WS(' ', o.first_name, o.middle_name, o.last_name) AS owner_name,
       p.policy_insured_id,
       CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name) AS insured_name
FROM policies p
JOIN products pr ON pr.id = p.product_id
JOIN clients  o  ON o.id  = p.policy_owner_id
JOIN clients  i  ON i.id  = p.policy_insured_id
WHERE p.policy_delivery_date IS NULL
  AND p.status IN ('active', 'pending')
SQL);

        // 5. Age analytics source: one row per (policy, role). The person_role
        //    column makes it impossible to mix owner and insured ages by accident.
        DB::unprepared(<<<'SQL'
CREATE VIEW vw_client_age_analytics AS
SELECT p.id AS policy_id, YEAR(p.issued_date) AS issued_year, p.status, p.ape,
       'owner' AS person_role, c.id AS client_id, c.gender, c.birthdate,
       TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age
FROM policies p
JOIN clients c ON c.id = p.policy_owner_id
UNION ALL
SELECT p.id, YEAR(p.issued_date), p.status, p.ape,
       'insured', c.id, c.gender, c.birthdate,
       TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE())
FROM policies p
JOIN clients c ON c.id = p.policy_insured_id
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
        WHERE p.status = 'active'
    ) s
) d
SQL);

        // Stored procedure: 12 gap-filled months of sales for a year, optionally
        // scoped to one client *in a specific role* (owner or insured).
        DB::unprepared(<<<'SQL'
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
          AND p.status <> 'cancelled'
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
        SELECT CASE WHEN p_role = 'owner' THEN p.policy_owner_id ELSE p.policy_insured_id END AS client_id,
               p.ape
        FROM policies p
        WHERE p.status <> 'cancelled'
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
