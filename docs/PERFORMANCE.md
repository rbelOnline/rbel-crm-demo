# Performance

## Checking query plans

```bash
php artisan crm:explain                       # EXPLAIN for the hot queries
php artisan crm:explain --analyze             # EXPLAIN ANALYZE (executes them)
php artisan crm:explain --analyze --only=policies-by-insured
```

Results on the seeded data (MySQL 9.1):

| Query | Access | Index |
|---|---|---|
| Policies filtered by **Policy Owner**, newest first | `ref`, backward index scan, no filesort | `policies_policy_owner_id_issued_date_index` |
| Policies filtered by **Policy Insured**, newest first | `ref`, backward index scan, no filesort | `policies_policy_insured_id_issued_date_index` |
| Year filter | `range`, covering | `policies_issued_date_index` |
| Policy number prefix search | `range`, covering | `policies_policy_number_unique` |
| Birthdays in month | `ALL` | `MONTH(birthdate)` on `clients` (no stored birthday key; fine at an advisor's scale) |
| Anniversaries in month | `range` | `policies_issued_month_index` (generated column) |
| Pending-delivery KPI | `range`, covering | `policies_policy_delivery_date_status_index` |

Sample `EXPLAIN ANALYZE` for the insured filter:

```
-> Limit: 15 row(s)  (actual time=0.026..0.030 rows=1)
   -> Nested loop inner join
      -> Index lookup on policies using policies_policy_insured_id_issued_date_index (policy_insured_id = 1) (reverse)
      -> Single-row covering index lookup on owner using PRIMARY (id = policies.policy_owner_id)
```

## Design choices

- **Sargable filters.** Year filters are written as `issued_date >= 'Y-01-01' AND issued_date < 'Y+1-01-01'`, never `YEAR(issued_date) = ?`, so the index applies. Age filters on clients are converted to birthdate ranges for the same reason.
- **Generated column** `issued_month` turns "anniversaries this month" into an index range scan. Names and birthdays are not stored in derived form: names are matched with `CONCAT_WS(first, middle, last)` and birthdays with `MONTH(birthdate)`.
- **Composite indexes lead with the role key** (`policy_owner_id` or `policy_insured_id`) followed by `issued_date`. Role-scoped lists and the per-person `sp_monthly_sales` therefore read only the matching rows, already in order.
- **No N+1.** Lists eager-load `owner`, `insured` and `product` and use `withCount` / `withSum`. `Model::preventLazyLoading()` is enabled outside production, so an accidental lazy load throws an error during development and tests.
- **Server-side pagination** everywhere (max 100 per page). The browser never receives the full table.
- **Caching.** Expensive analytics (stored procedures, CTE and window queries) are cached for 10 minutes under a version-stamped key (`App\Support\AnalyticsCache`). Any write to clients, policies or beneficiaries bumps the version, so results are never stale and no cache tags are needed. Appointment, goal and reminder counts are cheap and read live.
- **Views with window functions** (`vw_monthly_sales`) cannot be merged into the outer query, so MySQL materializes them. They aggregate first (one row per month), so the materialized set stays tiny. The dashboard and analytics use `sp_monthly_sales`, which filters by date range before aggregating.
- **Contains-search** on names (`LIKE '%term%'` on `CONCAT_WS(first_name, middle_name, last_name)`) scans the clients table. This is fine at a single advisor's scale (thousands of rows, well under 10 ms). If the book grows to hundreds of thousands, switch to a FULLTEXT index on `persons(full_name, email)`; prefix searches on policy number and email already use indexes.

Typical API response times on the seeded data with the dev server: 140–360 ms end to end, most of it PHP bootstrapping in `artisan serve`.
