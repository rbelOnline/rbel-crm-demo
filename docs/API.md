# API reference

Base URL `/api`. JSON in and out. All routes except `POST /login` require an authenticated Sanctum session: call `GET /sanctum/csrf-cookie` first, then send the `X-XSRF-TOKEN` header. Validation errors return **422** with `errors: {field: [message]}`.

Access levels: **manage** = admin or advisor; **admin** = admin only.

**Mass delete** (manage): `POST /clients/bulk-delete`, `/policies/bulk-delete`, `/appointments/bulk-delete`, `/email-templates/bulk-delete` with `ids` (1–100 distinct integers). Each record follows the single-delete rules (e.g. people linked to a policy are skipped) and is deleted and audited individually. Response: `{deleted: n, skipped: [{id, name, reason}]}`.

## Auth & profile
| Method | Path | Notes |
|---|---|---|
| POST | `/login` | `email`, `password`, `remember`. Throttled |
| POST | `/logout` | |
| GET | `/user` | current user + `permissions` |
| PUT | `/profile` | `name`, `email`, `phone`, `job_title`, `license_number`, `bio` |
| PUT | `/profile/password` | `current_password`, `password`, `password_confirmation` |

## Reference & dashboard
| GET | `/meta` | products, enums, years, placeholders, churn definition |
|---|---|---|
| GET | `/dashboard?year=&age_role=owner\|insured` | Issue year (defaults to the current year) for Total Clients, churn, pending delivery, goals (by target date), generations and monthly sales. Also: due today, birthdays and anniversaries (current month), upcoming appointments |
| GET | `/clients/lookup?q=&ids=&role=owner\|owns\|insured` | type-ahead for client pickers; `owner` = may own a policy (`is_policy_owner`), `owns` / `insured` = actually owns / is insured under a policy |
| GET | `/owner-lookup?q=` · `?client_id=` · `?lead_id=` | Policy Owner type-ahead for the Clients form: owner-flagged clients and leads, each item `{kind: client\|lead, id, first_name, middle_name, last_name, age}` |

## Clients (people on policies; profiles at `/people/:id` in the UI)

The UI's **Clients** module is the policies API (`/policies`). API paths are unchanged.

| GET | `/clients` | `search`, `is_policy_owner` (0/1), `role` (owner, insured, owner_only, insured_only), `client_status` (active, inactive = churned, completed, prospect), `gender`, `birth_month`, `age_min`, `age_max`, `sort` (name, email, birthdate, created_at, owned_policies, insured_policies, total_ape), `direction`, `per_page`, `page` |
|---|---|---|
| GET | `/clients/export` | `.xlsx` of the whole filtered list (same filters/sort as `/clients`, no paging). Audited as `exported` |
| POST | `/clients` | `first_name`, `middle_name`, `last_name`, `occupation`, `birthdate`, `gender`, `email`, `mobile_number`, `address`, `is_policy_owner`. No `full_name` in responses: clients build it from the parts |
| GET | `/clients/{id}` | includes owned policies, insured policies, appointments; `meta.policy_activity` |
| PUT | `/clients/{id}` | `is_policy_owner` cannot be turned off while the client owns policies (422) |
| DELETE | `/clients/{id}` | manage. Also deletes every policy they own or are insured under, and their appointments, reminders, goals and email logs |
| POST | `/clients/bulk-delete` | manage. `ids[]`, same rule as DELETE |

## Leads (prospects; kept apart from clients until converted)
| GET | `/leads` | `search`, `gender`, `birth_month`, `age_min`, `age_max`, `sort` (name, email, birthdate, created_at), `direction`, `per_page`, `page` |
|---|---|---|
| GET | `/leads/export` | `.xlsx`, same filters/sort. Audited as `exported` |
| POST | `/leads` | `first_name`, `middle_name`, `last_name`, `occupation`, `birthdate`, `gender`, `email`, `mobile_number`, `notes` |
| GET/PUT | `/leads/{id}` | |
| DELETE | `/leads/{id}` · POST `/leads/bulk-delete` | manage |
| POST | `/leads/{id}/convert` | optional `address`, `is_policy_owner` (default true). Creates a client from the lead's details and deletes the lead. Returns the client (201) |

## Fund Types (options for the Fund Type dropdown on client records)
| GET | `/fund-types` | `search`, `status` (active, inactive), `suitability`, `sort` (name, suitability, policies, created_at), `direction`, `per_page` |
|---|---|---|
| POST/PUT | `/fund-types` · `/fund-types/{id}` | manage. `name` (unique), `suitability` (required: conservative, moderate, aggressive), `is_active` |
| DELETE | `/fund-types/{id}` · POST `/fund-types/bulk-delete` | manage. 409 / skipped if a client record uses it (mark it inactive instead) |

Policies take an optional `fund_type_ids[]` (any number; only active fund types can be added, ones already on the record may stay; replaced only when the key is sent). Responses include `fund_type_ids` and `fund_types[{id,name,suitability}]`. `/meta` lists `fund_types` (with `suitability`) and `fund_suitabilities`.

## Policies
| GET | `/policies` | `search`, **`policy_owner_id`**, **`policy_insured_id`**, **`owner_search`**, **`insured_search`**, **`owner_birth_month`**, **`insured_birth_month`** (1–12), `birth_month` (1–12, owner **or** insured — used by the Clients UI), `product_id`, `status`, `mode_of_payment` (multi values comma-separated), `is_orphan`, `issued_from`, `issued_to`, `year`, `delivery` (pending, delivered), `relationship` (self, different), `sort` (policy_number, **policy_owner**, **policy_insured**, product, ape, issued_date, mode_of_payment, sum_assured, status, policy_delivery_date, is_orphan), `direction`, `per_page` |
|---|---|---|
| GET | `/policies/export` | `.xlsx` of the whole filtered list (same filters/sort as `/policies`, no paging); separate Policy Owner and Policy Insured columns. Audited as `exported` |
| POST | `/policies` | policy fields + optional `beneficiaries[]` (atomic). Each role: `policy_owner_id` **or** `policy_owner{first_name,last_name}` (always creates a new client, flagged as a Policy Owner); `policy_insured_id` **or** `policy_insured{…}` (a new insured-only client), or `insured_same_as_owner: true`. `policy_owner_id` must be a client with `is_policy_owner`. Instead of an owner id, `policy_owner_lead_id` picks a lead: on save the lead is converted into a client (Policy Owner, address from `policy_owner.address`, which is then required) and removed. `policy_owner` / `policy_insured` may also carry `birthdate`, `gender`, `email`, `mobile_number`, `address`, `occupation`: they seed the new client, or (with an id, also on PUT) update that client's record. Beneficiary items: optional `id` (keeps that beneficiary) plus the fields below |
| GET | `/policies/{id}` | |
| PUT | `/policies/{id}` | beneficiaries are replaced only if the key is sent |
| DELETE | `/policies/{id}` | manage |
| GET/POST | `/policies/{id}/beneficiaries` | `first_name`, `middle_name`, `last_name`, `birthdate`, `gender`, `email`, `mobile_number`, `relationship`, `beneficiary_type`, `designation`, `allocation_percentage` |
| PUT/DELETE | `/policies/{id}/beneficiaries/{bid}` | scoped binding; DELETE requires manage |
| POST | `/policies/{id}/document` | multipart `document` (pdf/jpg/png ≤ 10 MB), upload or replace |
| GET | `/policies/{id}/document` | inline stream |
| GET | `/policies/{id}/document/download` | attachment |
| DELETE | `/policies/{id}/document` | manage |

## Activity
| GET/POST | `/appointments` | `search`, `status`, `date`, `date_from`, `date_to`, `client_id`, `upcoming` |
|---|---|---|
| GET | `/appointments/calendar?from=&to=` | every appointment in the range (max 45 days), by date and time, with `client` — used by the Calendar module |
| GET/POST | `/schedule-items` · `?from=&to=` (max 45 days) | the signed-in user's **personal** schedule: `title`, `date`, `start_time`, `end_time` (optional, after start), `repeat` (none, daily, weekly, monthly, yearly), `repeat_until` (optional last day), `label` (green, blue, yellow, red), `notes`. GET expands repeating items into one row per occurrence (`date` = that day, `starts_on` = first day); a monthly item on the 29th–31st skips months without that day, a yearly one on Feb 29 falls only in leap years. Private: other users get 404 |
| POST | `/schedule-items/{id}/skip` | `date`: remove one day from a repeating item |
| PUT/DELETE | `/schedule-items/{id}` | own items only |
| GET/PUT | `/calendar-labels` | names of the four colour labels shared by appointments and schedule items (`labels{green,blue,yellow,red}`); PUT requires manage. Appointments take an optional `label` and can be filtered by it |
| GET/PUT/DELETE | `/appointments/{id}` | DELETE requires manage |
| GET/POST | `/goals` | `search`, `status`, `sort` (incl. `progress`). Response includes `summary`. Body: `start_date` (Date from) and `target_date` (Date to, on/after start); `title`, `description`, `target_amount`, `status`; `current_amount` is derived (APE of all policies issued in the range) |
| GET/PUT/DELETE | `/goals/{id}` | |
| GET/POST | `/reminders` | `state` (open, due_today, overdue, completed), `type` |
| PUT/DELETE | `/reminders/{id}` · PATCH `/reminders/{id}/complete` | |

## Email
| GET/POST | `/email-templates` | response includes `placeholders` |
|---|---|---|
| GET/PUT/DELETE | `/email-templates/{id}` | |
| POST | `/email-templates/{id}/duplicate` | creates "(copy)" draft |
| POST | `/email-templates/{id}/preview` | optional `client_id`, `policy_id`, unsaved `subject` and `body`; sample data if no client |
| POST | `/email-templates/{id}/send` | manage, throttled. `client_id` required, `policy_id` optional. Returns 201 (sent) or 502 (failed, logged) |
| GET | `/email-logs` | `status`, `email_template_id`, `client_id`, `search`. Recipients masked |
| POST | `/email-images` | multipart `image` (jpg/png/gif/webp ≤ 2 MB, ≤ 4000px; no SVG), throttled. Returns `{id, token: "{{image:ID}}", name, url}`. Put the token in a template body; it renders as an image in previews and is embedded inline (`cid:`) when sent. Unknown image tokens fail template validation |
| GET | `/email-images/{id}` | the image, for authenticated previews |

## Products (plans)
| Method | Path | Notes |
|---|---|---|
| GET | `/products` | `search` (plan name), `plan_type` (VUL, TRAD), `status` (active, inactive), `sort` (name, plan_type, policies, created_at), `direction`, `page`. Each item has `policies_count` |
| GET | `/products/{id}` | |
| POST | `/products` | manage. `name` (Plan Name, unique), `plan_type` (VUL or TRAD), `is_active` |
| PUT | `/products/{id}` | manage. Same fields |
| DELETE | `/products/{id}` | manage. **409** if any client record uses the plan (mark it inactive instead) |
| POST | `/products/bulk-delete` | manage. Plans in use are skipped |

Inactive plans cannot be chosen for new client records; an existing record may keep its (now inactive) plan.

## Documents (templates) and client documents
| Method | Path | Notes |
|---|---|---|
| GET | `/document-templates` | `search`, `status` (active, inactive), `kind` (fillable = .docx, other), `sort` (name, extension, used, updated_at). Also returns `placeholders` (name → description) |
| POST | `/document-templates` | manage, multipart: `name` (unique), `description`, `is_active`, `file` (docx, doc, pdf, xlsx, xls, odt; ≤ 10 MB). A .docx is scanned for `{{placeholders}}` |
| PUT | `/document-templates/{id}` | manage. Send as POST + `_method=PUT` when replacing `file`. Client documents already made are unchanged |
| GET | `/document-templates/{id}/download` | |
| DELETE | `/document-templates/{id}` | manage. Client documents made from it keep their files |
| POST | `/document-templates/bulk-delete` | manage |
| GET | `/policies/{id}/documents` | the client record's documents |
| POST | `/policies/{id}/documents` | `source=template` + `document_template_id` (active): a .docx is filled with this record's details (Policy Owner / Policy Insured via `owner_*` / `insured_*`, policy fields), other formats copied; response `unfilled` lists placeholders with no value. Or `source=upload` + `file` (pdf, docx, doc, xlsx, xls, odt, jpg, png; ≤ 10 MB). Optional `name` |
| PUT | `/policies/{id}/documents/{doc}` | `name`; optional `file` replaces it (POST + `_method=PUT`) |
| GET | `/policies/{id}/documents/{doc}/download` | scoped: 404 through another record |
| GET | `/policies/{id}/documents/fields` | this record's details for the editor: `[{key, label, value}]` (Policy Owner / Policy Insured via `owner_*` / `insured_*`) |
| GET | `/policies/{id}/documents/{doc}/content` | `{editable, html}`. `html` is null until first edited in the app (the browser then opens the .docx) |
| PUT | `/policies/{id}/documents/{doc}/content` | `html` (≤ 8 MB). Word documents only. HTML is sanitized (formatting, lists, tables, embedded images only; no scripts, links or remote images), the .docx is rebuilt and replaces the file; `unfilled` is recomputed. Audited as `edited_content` |
| DELETE | `/policies/{id}/documents/{doc}` | manage. Deleting a client record deletes its documents and files |

## Automations (admin)
| Method | Path | Notes |
|---|---|---|
| GET | `/automations` | `data[]` (birthday_greeting, premium_due: enabled, template, send_time, sender, last run summary), `senders[]`, `email_delivery_enabled` |
| PUT | `/automations/{id}` | `enabled`, `email_template_id` (an active template is required to enable), `send_time` (HH:MM), `sender_user_id` |
| GET | `/automations/{id}/preview` | today's recipients with `status` ready / no_email / already_sent |
| POST | `/automations/{id}/run` | send today's emails now; returns the run summary. Throttled |

Scheduled by `crm:automations` every 5 minutes (`php artisan schedule:work`). Automated emails are logged in `email_logs` with `automation` and a unique per-day `dedupe_key`.

## Analytics & admin
| GET | `/analytics?year=&role=owner\|insured` | sales, annual_sales, age_distribution, gender_distribution, policies, top_clients, retention |
|---|---|---|
| GET | `/analytics/sales?year=&client_id=&role=` | role required with client_id |
| GET | `/analytics/age-distribution?year=&role=` | distinct owners or insureds per generation (by birth year): `ranges[{key, label, years, person_count, policy_count, total_ape, pct}]`, youngest first |
| GET | `/audit-logs` · `/audit-logs/facets` | admin. Filters: `module`, `action`, `user_id`, `record_id`, `date_from`, `date_to`, `search` |
