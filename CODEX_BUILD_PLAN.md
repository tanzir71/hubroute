# HubRoute — Codex Build Plan

**Goal:** take HubRoute from a browser walkthrough to a production-ready parcel operations platform that a **nationwide logistics operation** can run on — *minimal in surface area, rich in capability*. This document is the single source of truth for Codex. Work top to bottom by milestone; do not skip the guardrails.

> Owner: Tanzir · Status: Draft v1 · Design refresh: complete (see §5) · Backend decision: PHP 8 + SQLite

---

## 1. How Codex should use this document

- **Build by milestone (M0 → M4).** Each milestone is releasable. Do not start Mn+1 until Mn's Definition of Done (§10) is met.
- **One feature = one PR.** Keep PRs under ~400 lines of diff where possible. Every PR references the feature ID here (e.g. `[P2-04]`).
- **Never break the demo.** The browser‑only demo (`public/index.html`) and the static landing/docs must keep working at every commit. If a change would break them, gate it behind a flag or fork the file.
- **Match the design system in §5 exactly.** No new colors, no rounded corners, no ad‑hoc spacing. Reuse the tokens.
- **Acceptance criteria are contracts.** A feature is not done until every checkbox in its acceptance list passes, plus tests (§8).
- **Backend choice is resolved.** Keep the backend simple: PHP 8 + SQLite, with PDO, WAL mode, app-enforced scoping, idempotent form/API mutations, audit logging, and clear backups. Do not introduce Postgres/Supabase/MySQL/serverless unless Tanzir explicitly reverses this.
- **Security is not a phase.** Apply §7 controls as you build each feature, not at the end.

---

## 2. Current state (audit)

HubRoute today is three separate artifacts sharing a brand:

| Artifact | File(s) | What it is | Production gap |
|---|---|---|---|
| Landing page | `index.html` | Static marketing page (GitHub Pages) | Now redesigned; copy still demo‑oriented |
| Docs | `docs.html` | Static docs (GitHub Pages) | Now redesigned; content thin for real operators |
| Browser demo | `public/index.html`, `vercel-demo/index.html` | Single‑file SPA, **all state in `localStorage`**, seeded Bangladesh data | Not a real backend — no persistence, no auth, no multi‑user, no security boundary |
| PHP source app | `hubroute.php`, `health.php`, `index.php` | PHP 8 + **SQLite** server app for shared hosting | Chosen production backend; needs incremental schema depth, API/form idempotency, audit review, backups, and feature depth |

**What already exists (concept‑complete, demo‑grade):** role model (admin / hub / agent / customer / public), dashboard, parcels CRUD + search/filter, routes, riders/agents, customers, scan/event capture, hub‑and‑spoke custody handoff, public tracking by code, COD notion, CSV export, CSRF/prepared‑SQL/session hardening on the PHP side.

**What's still product backlog beyond first operations:** deeper SQLite schema coverage, password reset/OTP, more server-side RBAC tests, nationwide network model (zones/districts/coverage), SLA/ETA, exceptions/RTO/reattempts, COD reconciliation + merchant remittance, merchant self-service + bulk import, rider mobile run-sheet with proof-of-delivery, notifications (SMS/email/webhooks), reporting/analytics, waybill/label printing, observability, backups, and restore drills.

---

## 3. Product vision & scope guardrails

**Vision:** one lean operations console + a public tracking page + a rider view + a merchant view, backed by a real multi‑tenant backend, that a courier company can use to move parcels across an entire country through a hub‑and‑spoke network.

**"Minimal but feature‑rich" means:**
- Few screens, high density. Reuse the console shell; add **views**, not new apps.
- Every feature must earn its place against a real operational job (intake, sort, linehaul, last‑mile, COD, exception, remittance, report).
- Prefer configuration over new code paths (status rules, SLA, rate card are data, not hardcoded).

**Explicitly out of scope (v1):** full route optimization/vehicle routing, warehouse WMS/bin management, in‑app chat, native mobile apps (use responsive PWA for riders), automated linehaul scheduling, ML ETA. Note these as "Later" so they aren't accidentally built.

**Primary users & top jobs:**
- **Merchant/customer:** book pickups, create parcels (single + bulk), track, get COD remittance, see reports.
- **Hub staff:** intake/scan, sort by destination zone, dispatch linehaul + last‑mile, receive, handle exceptions.
- **Rider/agent:** run‑sheet, scan, capture proof of delivery, collect COD, mark exceptions/reattempts.
- **Admin/ops control:** network config, users, SLA, exceptions oversight, COD/finance, analytics, audit.
- **Public:** track by code with redacted PII.

---

## 4. Target architecture

### Chosen path — simple PHP + SQLite backend

Keep the browser-only Vercel demo for prospect walkthroughs. The real backend is the existing PHP 8 application backed by SQLite. Build production depth into that code path instead of introducing a managed database or serverless provider.

```
Browser (console / rider PWA / merchant / public track)
        │  HTTPS, secure session cookie, CSRF, idempotency key
        ▼
PHP 8 app (`hubroute.php`)
        │  PDO prepared statements, app-level RBAC/scope filters
        ▼
SQLite database (`data/hubroute.sqlite`)  [source of truth]
        ├─ WAL + busy timeout
        ├─ append-only custody events
        ├─ idempotency keys
        ├─ audit log
        └─ operational tables
```

- **DB:** SQLite remains the production database. Use PDO, prepared statements, transactions, WAL mode, indexes, app-level scope checks, daily backups, and restore testing.
- **Auth/RBAC:** PHP sessions + password hashing. Enforce hub/agent/customer/admin access in backend queries and mutation handlers. No access decisions may live only in the browser.
- **API/forms:** server-rendered forms are acceptable. Add JSON endpoints only when they serve rider offline sync, merchant import, webhooks, or future PWA flows.
- **Frontend:** keep the static/browser demo separate from the PHP-backed app. Reuse the design tokens and copy patterns where practical.
- **Rider:** responsive PWA later, syncing to PHP endpoints with idempotency keys.
- **Deploy:** shared hosting or simple VPS with PHP 8.1+, PDO SQLite, private `DATA_DIR`, HTTPS, backups, health check, and log access.

### 4.3 Data model (core entities)

Model these regardless of backend. Names are guidance.

- **organization / merchant** — tenant boundary for merchants; `id, name, contact, kyc_status, payout_details, created_at`.
- **user** — `id, org_id?, role (admin|ops|hub_staff|agent|merchant|customer), email, phone, password_hash, status, mfa`.
- **hub** — `id, name, type (pickup|sortation|warehouse|delivery|return), zone_id, address, geo, active`.
- **zone / district / area** — nationwide hierarchy: `division → district → thana/upazila → area/postcode`; each `area` maps to a serving `hub` (coverage). Powers serviceability + routing.
- **parcel** — `id, tracking_code, merchant_id, sender, recipient (name, phone, address, area_id), weight, dimensions, item_value, cod_amount, service_level, status, current_hub_id, assigned_agent_id, origin_hub_id, dest_area_id, sla_due_at, created_at`.
- **parcel_event** — immutable log: `id, parcel_id, type (created|picked_up|arrived_hub|departed_hub|sorted|in_transit|out_for_delivery|delivered|failed_attempt|hold|rto_initiated|returned|cod_collected|correction), hub_id?, agent_id?, actor_id, note, geo?, photo_url?, created_at`. **Parcel status is derived from / validated against events + a status‑transition ruleset.**
- **route / run** — `id, hub_id, agent_id, type (linehaul|last_mile|pickup), date, status (planned|dispatched|closed), stops[]`.
- **cod_ledger** — `parcel_id, collected_amount, collected_by, collected_at, remittance_id?`.
- **remittance** — merchant payout batch: `id, merchant_id, period, gross_cod, fees, net_payable, status, paid_at`.
- **rate_card / sla_policy** — pricing by weight×zone; SLA hours by service level and lane.
- **notification / webhook_delivery** — outbound message log.
- **audit_log** — every privileged mutation: `actor, action, entity, before, after, at`.

### 4.4 API surface

Reuse the PRD route table (`/login`, `/parcels`, `/parcels/{id}`, `/routes`, `/scan`, `/track/{code}`, `/admin/*`) and add JSON endpoints under `/api` for: bulk parcel import, event capture (idempotent, offline‑replayable), COD collect, remittance run, serviceability check, label/waybill PDF, reports, webhook management. All mutating endpoints: authenticated, RBAC‑checked, rate‑limited, idempotency‑key aware for scan/event.

---

## 5. Design system (use exactly — already applied to landing, docs, console)

The UI was refreshed to a **sharp, technical operations** aesthetic with a single cobalt accent. Build all new UI with these tokens.

```css
--bg:#f4f5f7;            /* app canvas */         --panel:#ffffff;   --panel-2:#f8f9fb;
--text:#141821;         --muted:#5c6470;
--line:#e5e8ee;         --line-strong:#d3d8e0;
--ink:#0b0d12;          /* near‑black: headings */
--brand:#2f56d9;        --brand-ink:#2444b8;      --brand-soft:#eef2fe;   /* cobalt accent */
--ok:#0f7a4d;  --warn:#9a5b00;  --danger:#c0362c;  /* status/data only, muted */
--mono: ui-monospace,"SF Mono","JetBrains Mono","Roboto Mono",Menlo,Consolas,monospace;
```

**Rules:**
1. **Zero border‑radius.** Everything squared. The only round element permitted is the tiny status‑LED dot. No pills — status chips are squared, uppercase, letter‑spaced.
2. **One accent.** Cobalt `--brand` is the primary action + brand color. `--ok/--warn/--danger` are for parcel/data status only, used sparingly.
3. **Mono numerics.** Tracking codes, KPI values, IDs, metric numbers, counts, timestamps → `--mono`. Prose stays Inter.
4. **Crisp structure over shadows.** Group with 1px `--line` borders and shared edges (grids collapse internal borders). Shadows only on floating layers (modals, dropdowns, toast) via `--shadow`.
5. **Buttons:** primary = solid cobalt; secondary = 1px `--line-strong` outline; danger = red outline→fill on hover. Min‑height 38–42px.
6. **Type:** Inter, tight negative tracking on headings; labels uppercase 11–12px letter‑spaced `--muted`.
7. **Logo/favicon:** the route‑arrow mark (cobalt square, white ascending arrow + waypoint). Inline SVG uses classes `.logo-bg/.logo-arrow/.logo-head/.logo-node`; favicon is the matching data‑URI already embedded in all three pages. Do not reintroduce the old "HR" glyph.
8. **Accessibility:** all interactive states need visible focus (`2px solid --brand`); status must not rely on color alone (pair with label/icon).

New components must be added to a shared stylesheet/token file once the console is modularized, so the three surfaces stay in sync.

---

## 6. Feature backlog by milestone

Legend: **[Must]** ship for a usable product · **[Should]** strong value · **[Later]** post‑v1. IDs are stable references for PRs.

### M0 — Production hardening of what exists *(no new features)*
Make the current surfaces feel finished and safe to show real prospects.
- **[M0‑01][Must]** Consolidate `public/index.html` and `vercel-demo/index.html` into one source (build step or symlink) so they can't drift.
- **[M0‑02][Must]** Split the 1,800‑line console into ES modules (state, views, components, api‑shim) behind the same output. Enables everything after.
- **[M0‑03][Must]** Extract the design tokens (§5) into one `styles/tokens.css` imported by all three surfaces.
- **[M0‑04][Should]** Landing/docs copy pass: position for a courier operator, not just a demo; add a real "how it works" and screenshots.
- **[M0‑05][Must]** Add empty/loading/error states everywhere the console currently assumes seeded data.
- **DoD:** demo works, no drift, Lighthouse ≥ 90 (perf/a11y/best‑practices) on landing + console.

### M1 — Real backend, auth, RBAC *(the foundation)*
- **[M1‑01][Must]** Harden SQLite migrations/schema; implement or map the §4.3 schema (parcels, events, hubs, zones, users, routes, idempotency, audit).
- **[M1‑02][Must]** Auth: email+password with hashing, PHP session hardening, password reset, optional OTP/magic link; lockout + rate limiting.
- **[M1‑03][Must]** RBAC enforced server‑side with SQLite-backed scope filters: hub scoping, merchant/customer scoping, public redaction. Add an authorization test matrix.
- **[M1‑04][Must]** Keep the PHP-backed console as the real app and keep the `localStorage` browser demo as a separate offline/prospect build.
- **[M1‑05][Must]** Immutable `parcel_event` log + status‑transition ruleset (config‑driven). Status is derived/validated, never free‑set.
- **[M1‑06][Must]** Audit log for privileged mutations.
- **DoD:** two real users in different hubs cannot see each other's out‑of‑scope parcels (proven by tests); page reload preserves data; no secrets in client.

### M2 — Core nationwide operations
- **[M2‑01][Must]** **Network model:** division→district→thana→area hierarchy; area→hub coverage mapping; **serviceability check** by area/postcode at parcel creation.
- **[M2‑02][Must]** **Parcel lifecycle depth:** holds, failed attempts + **reattempt** scheduling, **RTO** (return to origin) flow, exception reasons taxonomy.
- **[M2‑03][Must]** **Hub sort & dispatch:** sort inbound by destination zone; build **linehaul** run (hub→hub) and **last‑mile** run (hub→doorstep); dispatch/receive with scan.
- **[M2‑04][Must]** **Rider PWA run‑sheet:** installable, mobile‑first; scan queue that works offline and syncs; **proof of delivery** (photo + signature or delivery OTP); COD collected capture; exception capture.
- **[M2‑05][Must]** **COD:** collection at delivery → `cod_ledger`; COD outstanding by rider/hub/merchant.
- **[M2‑06][Should]** **SLA/ETA:** `sla_due_at` from service level + lane; aging + breach flags on dashboards.
- **[M2‑07][Must]** **Public tracking upgrade:** timeline + hub path + status, redacted PII, branded, shareable link, mobile‑clean.
- **DoD:** a parcel can be booked → picked up → sorted → linehauled → last‑mile → delivered with POD + COD, entirely against the real backend, across 2+ hubs.

### M3 — Merchant self‑service, COD finance, notifications
- **[M3‑01][Must]** **Merchant portal:** register/login, create parcels (single), **bulk CSV import** with validation + error report, request pickups + schedule, track own parcels.
- **[M3‑02][Must]** **Pickup requests → pickup runs** for riders.
- **[M3‑03][Must]** **COD reconciliation + merchant remittance:** batch payouts, fees from rate card, remittance statements (PDF/CSV).
- **[M3‑04][Must]** **Notifications:** SMS + email + merchant **webhooks** on key status changes; delivery **OTP**. Templated, logged, retried.
- **[M3‑05][Should]** **Rate card / pricing:** weight×zone charges; per‑parcel charge shown to merchant.
- **[M3‑06][Should]** **Waybill/label printing:** barcode/QR, thermal‑printer‑friendly PDF; bulk label sheet.
- **DoD:** a merchant can bulk‑import 100 parcels, get labels, track them, and receive a COD remittance statement.

### M4 — Analytics, admin depth, and reliability
- **[M4‑01][Must]** **Reporting/analytics:** on‑time %, first‑attempt success, aging buckets, exceptions, COD outstanding & remitted, volume by hub/zone/merchant/agent; date filters; CSV/Excel export.
- **[M4‑02][Must]** **Admin console:** users/roles, hubs, zones/coverage, SLA policies, rate card, status/event reference data, audit review.
- **[M4‑03][Should]** **Saved views / advanced filters** across parcel, event, route lists; global search.
- **[M4‑04][Should]** **Bilingual UI (Bangla + English)**; Asia/Dhaka timezone throughout.
- **[M4‑05][Should]** **Realtime** queue/dashboard updates.
- **[M4‑06][Must]** **Observability:** structured logs, error tracking, uptime/health, basic metrics dashboard.
- **[Later]** route optimization, e‑commerce (WooCommerce/Shopify) order import, ML ETA, native apps, WMS.
- **DoD:** ops lead can answer "how are we doing today?" and "who owes what COD?" from the product; on‑call can see errors and health.

---

## 7. Non‑functional requirements (apply continuously)

- **Security:** server‑side authz with SQLite scope filters; parameterized queries; CSRF on cookie‑auth POSTs; secrets in env/private config, never client; password hashing (argon2/bcrypt where available, otherwise `password_hash()` defaults); rate limiting on auth + scan; signed/private storage for POD media; PII redaction on public surfaces; idempotency keys on event/scan/form mutations; full audit trail; dependency + secret scanning in CI. Do **not** put PII in URLs.
- **Access control tests:** every role × every sensitive endpoint has an allow/deny test.
- **Accessibility:** WCAG 2.1 AA — contrast, focus visible, keyboard paths, status not by color alone, labeled inputs, touch targets ≥ 44px on rider PWA.
- **Performance:** console interactive < 2.5s on mid mobile; list endpoints paginated + indexed; images/POD lazy‑loaded; avoid N+1.
- **Reliability:** offline scan queue with sync + conflict handling for riders; background jobs idempotent and retryable.
- **Data:** migrations versioned and reversible; daily backups; soft‑delete + audit for corrections (no hard deletes of operational records).
- **i18n/locale:** Bangla + English strings externalized; currency ৳, Asia/Dhaka time, local phone/address formats.

---

## 8. Testing strategy

- **Unit:** status‑transition rules, serviceability lookup, COD math, rate‑card pricing, SLA computation.
- **Integration/API:** each endpoint incl. authz allow/deny matrix, idempotent event capture, bulk import validation.
- **E2E (happy paths):** book→deliver with POD+COD across hubs; merchant bulk import→track; remittance run; public tracking redaction.
- **Regression guard:** the demo build must render (smoke test) on every PR.
- **Non‑functional:** a11y checks (axe) in CI on key screens; basic load test on list + scan endpoints.
- **Definition:** no feature merges without tests covering its acceptance criteria; CI green required.

---

## 9. Deployment & release

- Preview deploy per PR; staging with seeded realistic (non‑PII) data; production behind HTTPS.
- Migrations run in CI/CD with a gate; rollback plan documented per release.
- Rotate all seeded demo credentials; disable demo accounts in production; move SQLite `DATA_DIR` outside web root when the host supports it.
- Health endpoint + uptime monitor; error tracking wired before first real users.
- Release checklist: migrations applied, authz matrix green, backups/restores verified, secrets set, demo creds disabled, a11y/perf budgets met, monitoring live.

---

## 10. Codex execution guardrails (Definition of Done)

A PR is done when **all** hold:
- [ ] Implements exactly one backlog item; PR title references its ID.
- [ ] Meets every acceptance checkbox for that item.
- [ ] Applies §5 design tokens (no new colors, zero radius, mono numerics) and §7 security controls.
- [ ] Tests added/updated per §8; CI green; demo smoke test passes.
- [ ] No secrets, PII, or access decisions in client code.
- [ ] Docs/changelog updated; user‑facing strings externalized for i18n.
- [ ] Landing, docs, and console still build and render.

**First M1 PRs after M0:** `[M1‑01]` SQLite schema/idempotency/audit hardening, `[M1‑03]` backend authorization matrix coverage, then `[M1‑05]` status transition enforcement.

---

*Appendix — reference existing docs in `.trae/documents/` (PRD, technical architecture, page design) and `SECURITY.md` / `SETUP.md`. This plan supersedes them where they conflict on scope for the production build.*
