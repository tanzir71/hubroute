# M1 Backend Contract

Status: SQLite/PHP contract for `[M1-01]` through `[M1-06]`. Backend decision is resolved: keep HubRoute on simple PHP 8 + SQLite.

This file defines the data, form/API, event, idempotency, authorization, and audit contracts for the existing `hubroute.php` backend. Do not introduce Postgres, Supabase, serverless functions, MySQL, or a new framework unless Tanzir explicitly asks for that change later.

The executable status-transition contract lives in `src/domain/status-rules.mjs`, with tests in `tests/status-rules.test.mjs`. The executable authorization matrix lives in `src/domain/authorization-rules.mjs`, with tests in `tests/authorization-rules.test.mjs`. The executable idempotency policy lives in `src/domain/idempotency-rules.mjs`, with tests in `tests/idempotency-rules.test.mjs`. The executable audit-log policy lives in `src/domain/audit-rules.mjs`, with tests in `tests/audit-rules.test.mjs`.

## Scope

M1 turns the browser demo into a durable multi-user backend with server-side auth, RBAC, immutable parcel events, and audit logging.

Use SQLite migrations inside the PHP app startup path. Keep `DATA_DIR` private, enable WAL/busy timeout, and keep scope enforcement in backend queries and mutation handlers.

## Core Entities

Existing tables use SQLite integer primary keys. New tables should keep `created_at` and `updated_at` where useful. Operational records should be soft-deleted or corrected through events/audit entries, not hard-deleted.

| Entity | Required fields | Notes |
|---|---|---|
| `organization` | `id`, `name`, `contact`, `kyc_status`, `payout_details` | Merchant tenant boundary. |
| `user` | `id`, `organization_id`, `role`, `email`, `phone`, `password_hash`, `status`, `mfa_enabled` | Roles: `admin`, `ops`, `hub_staff`, `agent`, `merchant`, `customer`. |
| `hub` | `id`, `name`, `type`, `zone_id`, `address`, `geo`, `active` | Types: `pickup`, `sortation`, `warehouse`, `delivery`, `return`. |
| `zone` | `id`, `parent_id`, `level`, `name`, `code` | Hierarchy: division, district, thana/upazila, area/postcode. |
| `coverage_area` | `id`, `area_id`, `hub_id`, `service_level`, `active` | Powers serviceability and default routing. |
| `parcel` | `id`, `tracking_code`, `merchant_id`, `sender`, `recipient`, `weight`, `dimensions`, `item_value`, `cod_amount`, `service_level`, `status`, `current_hub_id`, `assigned_agent_id`, `origin_hub_id`, `dest_area_id`, `sla_due_at` | `tracking_code` must be unique. Status changes are event validated. |
| `parcel_event` / `events` | `id`, `parcel_id`, `event_key`/`event_type`, `hub_id`, `agent_id`, `actor_id`/`user_id`, `note`, `geo`, `photo_url`, `idempotency_key`, `created_at`/`ts` | Append-only. Existing table is `events`; evolve without breaking current data. |
| `run` | `id`, `hub_id`, `agent_id`, `type`, `run_date`, `status`, `stops` | Types: `linehaul`, `last_mile`, `pickup`; status: `planned`, `dispatched`, `closed`. |
| `cod_ledger` | `id`, `parcel_id`, `collected_amount`, `collected_by`, `collected_at`, `remittance_id` | Created from delivery/COD collection event. |
| `remittance` | `id`, `merchant_id`, `period_start`, `period_end`, `gross_cod`, `fees`, `net_payable`, `status`, `paid_at` | Merchant payout batch. |
| `rate_card` | `id`, `merchant_id`, `origin_zone_id`, `dest_zone_id`, `service_level`, `weight_min`, `weight_max`, `charge` | Pricing contract. |
| `sla_policy` | `id`, `origin_zone_id`, `dest_zone_id`, `service_level`, `hours` | Computes `parcel.sla_due_at`. |
| `notification` | `id`, `parcel_id`, `channel`, `template_key`, `recipient`, `status`, `attempts`, `last_error` | SMS/email/webhook delivery log. |
| `idempotency_keys` | `id`, `actor_user_id`, `operation`, `idempotency_key`, `request_hash`, `status`, `result_route`, `result_params`, `created_at`, `updated_at` | Prevents duplicate form/API mutations. Unique `(actor_user_id, operation, idempotency_key)`. |
| `audit_log` | `id`, `actor_user_id`, `actor_role`, `action`, `entity_type`, `entity_id`, `before_json`, `after_json`, `reason`, `metadata_json`, `created_at` | Required for privileged mutations and custody-affecting changes. |

## Status And Event Rules

`parcel.status` must be derived from, or validated against, the latest accepted `parcel_event`. Direct free-form status writes are not allowed.

| Event key | Allowed from | Resulting status | Notes |
|---|---|---|---|
| `created` | none | `requested` | Merchant/admin creates parcel. |
| `assigned` | `requested`, `picked_up`, `arrived_hub`, `sorted`, `failed_attempt`, `hold` | `assigned` | Route/rider assigned. |
| `picked_up` | `requested`, `assigned` | `picked_up` | Pickup custody begins. |
| `arrived_hub` | `picked_up`, `in_transit`, `departed_hub` | `arrived_hub` | Hub receive scan. |
| `sorted` | `arrived_hub`, `hold` | `sorted` | Ready for linehaul/dispatch. |
| `departed_hub` | `sorted`, `arrived_hub`, `assigned` | `in_transit` | Handoff out of current hub. |
| `out_for_delivery` | `arrived_hub`, `sorted`, `assigned` | `out_for_delivery` | Last-mile dispatch. |
| `delivered` | `out_for_delivery` | `delivered` | Requires POD or delivery OTP when configured. |
| `failed_attempt` | `out_for_delivery` | `failed_attempt` | Requires reason and optional reattempt date. |
| `hold` | any non-terminal | `hold` | Requires reason. |
| `rto_initiated` | `failed_attempt`, `hold` | `rto_initiated` | Return flow begins. |
| `returned` | `rto_initiated`, `in_transit`, `arrived_hub` | `returned` | Terminal return state. |
| `cod_collected` | `delivered` | no status change | Creates/updates `cod_ledger`. |
| `correction` | any | validated target | Requires audit log and admin/ops role. |

Terminal statuses are `delivered` and `returned`; only `correction` can alter terminal parcels.

## API Surface

All mutating PHP form actions and future JSON endpoints require authentication, RBAC checks, rate limiting, CSRF where cookie-authenticated, and an idempotency key where noted.

| Method | Path | Purpose | Idempotency |
|---|---|---|---|
| `POST` | `/api/auth/login` | Email/password login. | No |
| `POST` | `/api/auth/logout` | End session. | No |
| `POST` | `/api/auth/password-reset` | Request reset. | No |
| `GET` | `/api/me` | Current user, role, hub/merchant scope. | No |
| `GET` | `/api/parcels` | Paginated parcel list with filters. | No |
| `POST` | `/api/parcels` | Create parcel. | Yes |
| `GET` | `/api/parcels/{id}` | Parcel detail. | No |
| `PATCH` | `/api/parcels/{id}` | Correct non-event parcel metadata. | Yes |
| `POST` | `/api/parcels/bulk-import` | Merchant/admin CSV import. | Yes |
| `POST` | `/api/events` | Capture scan/event, including offline replay. | Yes |
| `GET` | `/api/runs` | Route/run list. | No |
| `POST` | `/api/runs` | Create pickup/linehaul/last-mile run. | Yes |
| `PATCH` | `/api/runs/{id}` | Dispatch or close run. | Yes |
| `POST` | `/api/cod/collect` | Record COD collection. | Yes |
| `POST` | `/api/remittances` | Create merchant payout batch. | Yes |
| `GET` | `/api/serviceability` | Check coverage by area/postcode. | No |
| `GET` | `/api/track/{code}` | Public redacted tracking. | No |
| `POST` | `/api/webhooks` | Merchant webhook config. | Yes |
| `GET` | `/api/reports/*` | Reports and exports. | No |
| `GET` | `/api/admin/audit-log` | Audit review. | No |

## Idempotency Contract

The backend must store an idempotency record scoped by actor, operation, and idempotency key. A duplicate request with the same fingerprint returns the stored response when complete, asks the client to retry when the original is still processing, and rejects the request when the same key is reused with a different fingerprint.

Required idempotent operations:

| Operation | Endpoint | Retention | Notes |
|---|---|---:|---|
| `customer_create` | PHP form action | 24h | Prevents duplicate pickup requests. |
| `customer_confirm` | PHP form action | 24h | Prevents duplicate receipt confirmations. |
| `hub_assign` | PHP form action | 24h | Prevents duplicate assignment events. |
| `hub_create_route` | PHP form action | 24h | Prevents duplicate route creation. |
| `record_event` | PHP form action | 72h | Prevents duplicate scan/event capture. |
| `agent_step` | PHP form action | 72h | Prevents duplicate rider status updates. |
| `settle` | PHP form action | 72h | Prevents duplicate COD settlement writes. |
| `admin_create_hub` | PHP form action | 24h | Prevents duplicate hub creation. |
| `admin_create_agent` | PHP form action | 24h | Prevents duplicate agent/user creation. |
| `admin_create_customer` | PHP form action | 24h | Prevents duplicate customer/user creation. |
| `create_parcel` | `POST /api/parcels` | 24h | Prevents duplicate bookings on retry. |
| `update_parcel_metadata` | `PATCH /api/parcels/{id}` | 24h | Prevents duplicate metadata corrections on retry. |
| `bulk_import_parcels` | `POST /api/parcels/bulk-import` | 72h | Prevents duplicate batch import jobs. |
| `capture_parcel_event` | `POST /api/events` | 72h | Supports offline rider scan replay. |
| `create_run` | `POST /api/runs` | 24h | Prevents duplicate route/run creation. |
| `update_run` | `PATCH /api/runs/{id}` | 24h | Prevents duplicate dispatch/close actions. |
| `collect_cod` | `POST /api/cod/collect` | 72h | Prevents duplicate COD ledger writes. |
| `create_remittance` | `POST /api/remittances` | 168h | Prevents duplicate payout batches. |
| `configure_webhook` | `POST /api/webhooks` | 24h | Prevents duplicate webhook configuration writes. |

## Audit Logging Contract

Audit records are append-only. They must include actor, action, entity type, entity id, before/after snapshots where required, reason where required, and timestamp.

| Action | Entity types | Capability | Required evidence |
|---|---|---|---|
| `parcel_event_correction` | `parcel`, `parcel_event` | `correct_terminal_custody_event` | before, after, reason |
| `parcel_metadata_update` | `parcel` | `update_parcel_metadata` | before, after |
| `route_run_update` | `run` | `dispatch_close_run` | before, after |
| `cod_ledger_adjustment` | `cod_ledger` | `collect_cod` | before, after, reason |
| `remittance_created` | `remittance` | `create_remittance` | after |
| `remittance_mark_paid` | `remittance` | `create_remittance` | before, after |
| `user_role_changed` | `user` | `manage_users_roles` | before, after, reason |
| `network_config_changed` | `hub`, `zone`, `coverage_area`, `rate_card`, `sla_policy` | `manage_hubs_zones_rates_sla` | before, after, reason |

## Authorization Matrix

Legend: `A` allowed, `S` scoped allowed, `D` denied. Scoped means the server must filter by hub, merchant, assignment, or public redaction rules.

| Capability | Admin | Ops | Hub staff | Agent | Merchant | Customer | Public |
|---|---:|---:|---:|---:|---:|---:|---:|
| View all parcels | A | A | D | D | D | D | D |
| View hub-scoped parcels | A | A | S | S | D | D | D |
| View merchant parcels | A | A | D | D | S | S | D |
| Public tracking by code | A | A | S | S | S | S | S |
| Create parcel | A | A | S | D | S | S | D |
| Update parcel metadata | A | A | S | D | S | D | D |
| Capture parcel event | A | A | S | S | D | D | D |
| Correct terminal/custody event | A | S | D | D | D | D | D |
| Create route/run | A | A | S | D | D | D | D |
| Dispatch/close run | A | A | S | S | D | D | D |
| Collect COD | A | A | S | S | D | D | D |
| Create remittance | A | A | D | D | D | D | D |
| View reports | A | A | S | S | S | D | D |
| Manage users/roles | A | D | D | D | D | D | D |
| Manage hubs/zones/rates/SLA | A | S | D | D | D | D | D |
| View audit log | A | A | D | D | D | D | D |

## Public Redaction Contract

`/api/track/{code}` must never expose full sender or recipient PII.

Allowed fields:

- `tracking_code`
- public status label
- public event timeline with event type, public hub/city label, and timestamp
- pickup/dropoff city or area, not full street address
- payment mode: `prepaid` or `cod`, plus amount only when product policy permits

Denied fields:

- full phone number
- email
- full street address
- internal notes
- actor/user IDs
- hub internal IDs
- webhook/audit metadata

## Acceptance Checklist Before M1 Build

- [x] Backend decision resolved: PHP 8 + SQLite.
- [ ] Entity names and route names accepted or amended against the existing SQLite tables.
- [x] Authorization matrix is converted into automated allow/deny tests.
- [x] Event transition table is converted into unit tests.
- [x] Idempotency behavior is covered for all endpoints marked idempotent in the API surface table.
