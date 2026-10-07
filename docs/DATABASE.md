# Database design

MySQL 8+ (developed on 9.1), InnoDB, `utf8mb4_0900_ai_ci`.

## Entity relationships

```
users ─┬─< audit_logs
       ├─< appointments >── clients
       ├─< goals
       ├─< reminders >───── clients / policies (optional)
       ├─< email_templates ─< email_logs >── clients / policies
       │
clients ─┬─< policies  (policy_owner_id)    ← Policy Owner (clients.is_policy_owner = 1)
         └─< policies  (policy_insured_id)  ← Policy Insured (any client)
policies ─< beneficiaries
leads (standalone; converted into clients)
products ─< policies
```

People are kept in three tables, each with its own details:

- `clients`: people on a policy. `is_policy_owner` marks who may **own** a policy; any client may be the **insured**. A self-insured policy points both foreign keys at the same client row.
- `leads`: prospects with no policy yet, with `notes`. Converting a lead (`POST /leads/{id}/convert`) copies their details into `clients` and deletes the lead.
- `beneficiaries`: one row per beneficiary designation on a policy, with the beneficiary's own name, birthdate and contact details.

No display name or birthday key is stored: the SPA joins `first_name` / `middle_name` / `last_name` (`fullName()` in `resources/js/lib/format.ts`), and birthday queries use `MONTH(birthdate)` / `DAY(birthdate)`.

## Tables (key columns)

| Table | Notes |
|---|---|
| `clients` | `first_name`, `middle_name`, `last_name`, `occupation`, `birthdate`, `gender`, `email`, `mobile_number`, `address`, `is_policy_owner` |
| `leads` | `first_name`, `middle_name`, `last_name`, `occupation`, `birthdate`, `gender`, `email`, `mobile_number`, `notes` |
| `products` | `code`, `name`, `category` |
| `fund_types` | `name` (unique), `suitability` (conservative, moderate, aggressive), `is_active`. A policy holds any number of them via `fund_type_policy` (policy_id cascade, fund_type_id RESTRICT on delete) |
| `policies` | `policy_number` (unique), **`policy_owner_id`**, **`policy_insured_id`**, `product_id`, `ape`, `sum_assured`, `issued_date`, `mode_of_payment`, `status`, `status_changed_at`, `policy_delivery_date` (NULL = pending), `is_orphan`, coverage document metadata. **Generated:** `issued_month`. **CHECK:** `ape >= 0`, `sum_assured >= 0` |
| `beneficiaries` | `policy_id` (cascade), `first_name`, `middle_name`, `last_name`, `birthdate`, `gender`, `email`, `mobile_number`, `relationship`, `beneficiary_type`, `designation`, `allocation_percentage` (CHECK 0 < x ≤ 100) |
| `appointments` | `client_id`, `title`, `appointment_date`, `appointment_time`, `status`, `label` (green, blue, yellow, red; names in `app_settings.calendar_labels`), `notes` |
| `schedule_items` | personal Calendar items: `user_id` (owner, cascade), `title`, `date`, `start_time`, `end_time`, `label`, `notes` |
| `goals` | `target_amount` (CHECK > 0), `current_amount` (CHECK ≥ 0, derived), `start_date` (Date from), `target_date` (Date to), `status`. `current_amount` = APE of all policies issued in the range, not postponed |
| `reminders` | `type`, `due_date`, `completed_at`, optional client/policy |
| `email_templates` / `email_logs` | logs keep recipient, subject, status, error and timestamps, but **not** the body |
| `audit_logs` | `user_id`, `action`, `module`, `record_id`, `old_values` JSON, `new_values` JSON, `ip_address`, `created_at` |

Deleting a client deletes everything related to them: every policy they own **or** are insured under (with those policies' beneficiaries, documents and reminders), plus their appointments, reminders, goals and email logs. `PolicyService::deleteClient()` does this through Eloquent so each removal is audited; the foreign keys also `CASCADE` as a safety net. Deleting a policy cascades to its beneficiaries.

## Indexes

`policy_number` (unique) · `(policy_owner_id, issued_date)` · `(policy_insured_id, issued_date)` · `(status, issued_date)` · `issued_date` · `(product_id, issued_date)` · `mode_of_payment` · `(policy_delivery_date, status)` · `is_orphan` · `(issued_month, status)` · clients and leads: `(last_name, first_name)`, `first_name`, `birthdate`, `email`, `mobile_number`; clients also `(is_policy_owner, last_name)` · appointments `(appointment_date, appointment_time)`, `(status, appointment_date)` · audit_logs `(module, record_id)`, `(user_id, created_at)`, `(action, created_at)`.

## Views

| View | Purpose | Techniques |
|---|---|---|
| `vw_policy_overview` | policy + owner + insured + product + beneficiary names | multi-JOIN (clients joined twice), aggregated derived table, GROUP_CONCAT |
| `vw_client_policy_overview` | per-client roll-up across both roles and **`client_status`** (active / inactive = churned / completed / prospect: the churn definition) | two aggregated derived tables |
| `vw_monthly_sales` | sales per year/month with running YTD, rank in year and previous month | CTE + `SUM() OVER`, `RANK()`, `LAG()` |
| `vw_policy_delivery_monitoring` | undelivered in-force policies with an aging bucket | CASE bucketing |
| `vw_client_age_analytics` | one row **per (policy, role)** with a `person_role` column, so owner and insured ages cannot be mixed by accident | UNION ALL |
| `vw_premiums_due` | next premium due date per active policy | month arithmetic with `DATE_ADD(... MONTH)` clamping month-ends |

## Stored procedures

They are used only where a parameterised, gap-filled result set is simpler in SQL than in PHP:

- `sp_monthly_sales(year, role, client_id)`: exactly 12 rows (recursive CTE of months, LEFT JOIN), with running total, `DENSE_RANK`, and `LAG`. It can be scoped to one client **as owner or as insured**, and raises `SIGNAL 45000` if the role is invalid.

## Transactions

`PolicyService` wraps policy create, update and delete in `DB::transaction`, together with creating owner / insured clients and syncing beneficiary rows. Deleting a client (with their policies and activity) and converting a lead are transactional too. A failure at any step rolls back everything; `PolicyCrudTest::test_policy_and_beneficiaries_are_created_atomically` proves this. Age-range replacement and document metadata updates are also transactional.

## Definitions used by analytics

- **Generations** (age graphs, by birth year; `App\Support\Generations`): Gen Alpha 2013+, Gen Z 1997–2012, Millennials 1981–1996, Gen X 1965–1980, Baby Boomers 1946–1964, Silent Generation 1928–1945, Greatest Generation ≤1927, Unknown (no birthdate). Distinct owners **or** insureds, sales only (not postponed).
- **Sale**: a policy whose status is not `postponed` (never issued), counted in the month of `issued_date`.
- **Policy statuses**: `pending`, `cooling_off`, `active`, `postponed`, `lapsed`, `surrendered`, `matured`, `terminated`. Closed = `surrendered`, `matured` or `terminated`.
- **In-force**: status `active`, `cooling_off` (free-look period after issue) or `pending`.
- **Pending delivery**: in-force and `policy_delivery_date IS NULL`.
- **Churn**: see README §3.
