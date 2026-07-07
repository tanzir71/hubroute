# HubRoute — Codex Refinement Loop

**Purpose:** run this file in a loop until HubRoute looks and feels like a real, working, trustworthy product — not a demo. This plan is self-contained: an agent (or a beginner developer) can execute every task with only this file, the repo, and the verification commands below.

**Scope of this plan:** UI/UX refinement + confidence-building features + a clean Vercel deployment artifact. It does NOT change the stack. For deep backend milestones (M1–M4), see `CODEX_BUILD_PLAN.md`; this plan supersedes it on anything UI, copy, landing, tracking page, and deployment-artifact related.

> Owner: Tanzir · Status: Loop v1 · Stack is frozen: PHP 8 + SQLite (production), static HTML (landing/docs), vanilla JS demo, `vercel-app/` starter (Node functions + Turso/libSQL). No frameworks, no CSS libraries, no build step for the PHP app.

---

## 0. Loop protocol (read first, follow exactly)

Work through tasks **top to bottom, phase by phase**. Each task has a stable ID, the files it touches, exact instructions, and acceptance checks.

```
LOOP:
  1. Find the first unchecked [ ] task in the earliest incomplete phase.
  2. Read every file listed under "Files" for that task before editing.
  3. Implement ONLY that task. Do not bundle tasks.
  4. Run the task's acceptance checks, then the GLOBAL GATES below.
  5. All green → check the box [x] in THIS file, commit:
        git commit -am "PASS <TASK-ID>: <short title>"
  6. Any red → fix and re-run. After 3 failed attempts, mark the task
     [B] BLOCKED with a one-line reason under it, commit, move to next task.
  7. When every task in every phase is [x] or [B], run the FINAL AUDIT (§9).
     If the audit finds regressions, add new tasks to §8 (Punch list) and loop again.
  8. Stop only when §9 passes and the punch list is empty.
```

**GLOBAL GATES — run after every task, all must pass:**

```bash
npm test                      # domain tests + demo build/mirror check + demo smoke test
php -l hubroute.php && php -l index.php && php -l health.php && php -l maintenance.php
npm run package:php           # shared-hosting zip must still build
npm run package:vercel        # vercel starter zip must still build
```

**Hard guardrails (violating any of these = task failed):**

- Never introduce a framework, npm UI dependency, CSS library, font CDN, or analytics script.
- `hubroute.php` stays a single self-contained file (its CSS stays inline — it ships as a zip to cPanel hosts with no build step).
- Never break `public/index.html` (browser demo), the CI workflow, or the two packaging scripts.
- All colors/spacing/typography come from the token set in §3. **No new colors. Zero border-radius everywhere** (the only round thing allowed is an 8px status LED dot).
- Never commit secrets. Never weaken CSRF, prepared statements, escaping (`e()` / `htmlEscape()`), rate limiting, or auth checks while restyling — this is a restyle, not a rewrite of handlers.
- Keep diffs small: one task = one commit, ideally < 400 changed lines.
- User-visible strings: plain English, no lorem ipsum, no "TODO", no "coming soon".

---

## 1. Why this plan exists — the confidence audit

HubRoute currently reads as "a demo pretending to be a product". Concrete evidence found in the repo:

| # | Problem | Where | Impact |
|---|---------|-------|--------|
| 1 | **Three surfaces, three different design languages.** Landing/docs/demo use the cobalt zero-radius token system. The *actual production app* (`hubroute.php`) uses a different, unbranded monochrome UI: rounded corners (6–8px), no logo, no cobalt, generic gray pills, `--pri:#080808` buttons. The Vercel starter uses a third variant (rounded, different grays). | `hubroute.php` lines ~1288–1331, `vercel-app/styles.css` | The moment someone logs into the real app it looks like a different, unfinished project. Kills trust instantly. |
| 2 | **The landing page repeatedly confesses it's a demo.** Title tag is "Bangladesh parcel routing demo"; hero CTA is "Open hosted demo"; the note says "the production roadmap moves this surface onto a real backend"; a whole panel prints demo credentials above the fold area. | `index.html` | Reads as "not a working app" — exactly the problem statement. |
| 3 | **PHP app has no visual state design.** No styled empty states, no skeleton/loading affordances, plain black flash messages, table-only screens, pills stripped of color (`bg-blue…bg-red` all map to gray). | `hubroute.php` | Feels like scaffolding. |
| 4 | **Public tracking page is the weakest screen** in both the PHP app and `vercel-app/track.html` (raw event list, no progress stepper, no hub path, no branding, injected HTML from API values without escaping in the Vercel tracker). | `hubroute.php` track route, `vercel-app/track.html` | Tracking is the ONE public, shareable surface — competitors treat it as their storefront. |
| 5 | **Vercel starter looks like a placeholder** ("HubRoute Vercel — Production starter", env var list as homepage content). | `vercel-app/index.html` | Anyone deploying it lands on a page of internal notes. |
| 6 | **No screenshots/product proof on the landing page** — the "screenshots" are hand-built CSS mockups, and docs are copy-heavy. | `index.html` §screenshots | Fake-looking screenshots make real products look fake too. |
| 7 | **Dead anchors.** `index.html` links to `docs.html#deployment` and `docs.html#roadmap`, but `docs.html` has no such ids (real ids: `#hosting`, `#vercel`, `#zip-deploy`, `#quick-start`, `#workflow`, `#security`, …). | `index.html`, `docs.html` | Broken links on the marketing page = "abandoned project" signal. Fixed in A-01/A-03. |

**Assets already in place (do not rebuild):** design tokens (`styles/tokens.css`), logo SVG set (`brand/`), domain rule tests, CI, packaging scripts, seeded Bangladesh data, hardened PHP handlers.

---

## 2. Competitor analysis → what "inspires confidence"

Benchmarks chosen for the same market (Bangladesh courier ops) and the same product class (lean delivery management for small operators).

### 2.1 Bangladesh incumbents (what merchants already expect)

| Competitor | What they nail | What HubRoute copies |
|---|---|---|
| **Pathao Courier** (merchant.pathao.com) | Self-serve merchant panel: register, book, track, fast COD payout visibility | Merchant-facing dashboard clarity; COD amounts always visible next to parcels |
| **RedX** | Live parcel tracking, COD payout in 24–72h, clear settlement states | COD outstanding/settled states as first-class UI chips, not footnotes |
| **Steadfast** | Dead-simple booking + bulk workflows, plain fast panel | Density + speed over decoration; forms that need zero training |
| **eCourier / Paperfly** | Coverage/serviceability messaging ("we deliver to X areas") | State coverage plainly (hub list = coverage list) |

### 2.2 Global SMB delivery tools (UI quality bar)

| Competitor | Positioning | Lesson for HubRoute |
|---|---|---|
| **Shipday** (~$39/mo) | "Simple and affordable" for small courier shops; praised for zero-training UI | HubRoute's edge is the same: simplicity. Every screen must be usable with no manual. |
| **Onfleet** (~$619/mo) | Polished dashboard, live map, clean visual hierarchy | Polish level to imitate (hierarchy, spacing, restraint) — without the map/price. |
| **Track-POD / Detrack** (~$29/unit) | Per-driver pricing, barcode scan, POD, branded notifications | Scan-first event capture and printable labels are table stakes, not extras. |
| **AfterShip / parcelLab** (tracking pages) | Branded tracking page = marketing surface: logo on top, progress stepper (confirmed → shipped → out for delivery → delivered), uniform status language, mobile-first | The public tracking page gets the most design investment of any screen (Phase C). |

### 2.3 Positioning conclusion (drives all copy in Phase A)

Every competitor is SaaS with per-month/per-driver fees and a cloud dependency. **HubRoute's honest differentiator: a self-hosted courier console you own — runs on $3/mo shared hosting or free-tier Vercel, one file + one database, no per-parcel fees.** That is the story the landing page must tell. "Demo" language is banished; the hosted walkthrough becomes a secondary "Try it in the browser" link.

Sources: [Track-POD vs Onfleet](https://www.track-pod.com/blog/onfleet-alternative-trackpod/) · [Shipday vs Track-POD](https://www.selecthub.com/delivery-management-software/shipday-vs-track-pod/) · [Onfleet POD apps](https://onfleet.com/blog/proof-of-delivery-apps-couriers/) · [branded tracking best practices (parcelLab)](https://parcellab.com/blog/guide-to-branded-tracking-pages/) · [LateShipment branded tracking](https://www.lateshipment.com/blog/branded-parcel-tracking/) · [BD courier landscape](https://www.boostupads.com/best-courier-service-providers-in-bangladesh/) · [Pathao/RedX/Steadfast integrations](https://apps.shopify.com/wd-easy-courier-bd)

---

## 3. Design contract (single source of truth)

All surfaces use the tokens in `styles/tokens.css` (already correct — do not change values):

```css
--bg:#f4f5f7; --panel:#ffffff; --panel-2:#f8f9fb; --text:#141821; --muted:#5c6470;
--line:#e5e8ee; --line-strong:#d3d8e0; --ink:#0b0d12;
--brand:#2f56d9; --brand-ink:#2444b8; --brand-soft:#eef2fe;
--ok:#0f7a4d; --warn:#9a5b00; --danger:#c0362c;
--mono:ui-monospace,"SF Mono","JetBrains Mono","Roboto Mono",Menlo,Consolas,monospace;
```

Rules (identical to `CODEX_BUILD_PLAN.md` §5, restated so this file is self-sufficient):

1. Zero border-radius. Squared chips, uppercase, letter-spaced. Only round element: 8px status LED dot.
2. One accent: cobalt `--brand` for primary actions + brand. `--ok/--warn/--danger` for parcel/data status only.
3. Mono numerics: tracking codes, KPIs, IDs, counts, timestamps → `--mono`.
4. Structure with 1px `--line` borders and collapsed shared edges; shadows only on floating layers.
5. Buttons: primary solid cobalt; secondary 1px `--line-strong` outline; danger red outline→fill on hover; min-height 38–42px.
6. Inter for prose; labels uppercase 11–12px letter-spaced `--muted`.
7. Logo = route-arrow mark (cobalt square, white arrow + waypoint) from `brand/`; matching data-URI favicon on every page. Never the old "HR" glyph.
8. Focus visible everywhere (`2px solid var(--brand)`); status never by color alone (always paired with a text label).

**PHP app exception:** `hubroute.php` cannot `<link>` a stylesheet from the zip reliably on all hosts, so its CSS stays inline — but the inline `:root` block must contain byte-identical token values to `styles/tokens.css`. Task B-01 adds an automated check for this.

**Status chip vocabulary (uniform across ALL surfaces, per competitor benchmark 2.2):**

| Status group | Chip color | Examples |
|---|---|---|
| Pre-transit | `--brand` on `--brand-soft` | requested, created, assigned |
| In motion | `--warn` tint | picked_up, at hub, in transit, out for delivery |
| Success | `--ok` tint | delivered, cod_settled |
| Problem | `--danger` tint | failed_attempt, returned, hold |

---

## 4. Phase A — Landing & docs: from "demo" to "product" *(confidence copy + proof)*

### A-01 — Reposition the landing page copy
- [x] Done
- **Files:** `index.html`
- **Do:**
  - `<title>` → `HubRoute — self-hosted courier & parcel operations console`. Update `<meta name="description">` to match (no "demo").
  - Kicker → `Self-hosted courier operations`. Keep the H1 or sharpen it; it may not contain "demo".
  - Rewrite the hero lead around the §2.3 positioning: own your courier console; PHP 8 + SQLite; runs on shared hosting you already pay for; booking → hub sortation → last-mile → COD settlement → public tracking.
  - CTAs become: primary `Get HubRoute` (→ `docs.html#hosting` — NOT `#deployment`, which doesn't exist), secondary `Live walkthrough` (→ hosted demo), tertiary `Track a parcel` (sample code link).
  - Fix every dead cross-page anchor (audit item 7): all `docs.html#deployment` links → `#hosting` (or the closest real id), remove/retarget `docs.html#roadmap`.
  - Delete the `runtime-note` sentence ("production roadmap moves this surface onto a real backend"). Replace with one factual line: `Free & open source · PHP 8 + SQLite · deploys to shared hosting or Vercel`.
  - `proof` strip becomes product facts: `1 file` app / `0 ৳` per-parcel fees / `4` hub types / `COD` settlement built in.
  - Move the demo-credentials panel out of section `#demo` into a collapsed `<details>` element titled "Walkthrough sign-ins" at the bottom of that section, keeping the rotation warning.
  - Sections `#demo`, `#setup`: retitle around trying vs deploying (e.g. "Try it in 30 seconds" / "Deploy it in 10 minutes"). Nav: rename "Walkthrough" link only if section ids stay stable; do not break anchors.
- **Accept:** `grep -ci "demo" index.html` returns a number ≤ 8 (was 20+; remaining uses only inside the walkthrough section); no grep hits for "roadmap moves"; all internal anchors resolve (every `href="#x"` has a matching `id="x"`); GLOBAL GATES pass.

### A-02 — Real screenshots instead of CSS fakes
- [B] BLOCKED
  - blocked-on B/C: screenshots must be captured after the production app and tracking UI are restyled.
- **Files:** `index.html`, new `assets/shots/*.png`
- **Do:** run the app locally (`php -S 127.0.0.1:8080`, complete first-run seed), capture 3 real PNG screenshots at 1280×800 — hub dashboard, parcel detail with timeline, public tracking (do this AFTER Phases B & C so the shots show the new UI; if Phase B/C not done yet, mark this task `[B] blocked-on B/C` and return). Compress (< 150 KB each). Replace the hand-built `.screenshots` CSS mockups with `<img>` (with meaningful `alt`, `loading="lazy"`, fixed `width`/`height` to avoid CLS), framed by a 1px `--line` border and a mono caption bar.
- **Accept:** images exist under `assets/shots/`, each < 150 KB; landing renders them; Lighthouse perf ≥ 90 on landing; GLOBAL GATES pass.

### A-03 — Docs: task-oriented, operator-first
- [x] Done
- **Files:** `docs.html`
- **Do:** restructure top of docs into three job-cards: `Deploy on shared hosting (10 min)`, `Deploy on Vercel (10 min)`, `Run your first parcel (5 min)`. Each is a numbered list with exact clicks/commands (source from `SETUP.md` — keep them in sync). Remove demo-first framing; the walkthrough becomes a sidebar note. Keep the REAL existing anchors working (`#quick-start`, `#workflow`, `#hosting`, `#zip-deploy`, `#vercel`, `#security`, `#operations`, `#troubleshooting`).
- **Accept:** the three job-cards exist and every command in them is copy-pasteable and correct against `SETUP.md`; every `docs.html#…` href in `index.html` resolves to a real id (verify: extract hrefs with grep, check each against `grep -o 'id="[a-z-]*"' docs.html`); GLOBAL GATES pass.

### A-04 — Consistent head metadata on every HTML surface
- [x] Done
- **Files:** `index.html`, `docs.html`, `src/demo/index.html`, `vercel-app/*.html`
- **Do:** every page gets: same route-arrow favicon data-URI, `og:title`, `og:description`, `og:type`, `theme-color` `#2f56d9`, and a real `<title>` (no page titled "HubRoute Vercel"). Demo page title: `HubRoute — live walkthrough`.
- **Accept:** `grep -L "og:title" index.html docs.html src/demo/index.html vercel-app/index.html vercel-app/track.html vercel-app/setup.html` returns empty; `npm run sync:demo` regenerates mirrors; GLOBAL GATES pass.

---

## 5. Phase B — The production app (`hubroute.php`): make the real thing look real

This is the highest-impact phase. The inline CSS block in `renderLayout()` (~lines 1288–1331) is replaced with the token design system. **Restyle only — handler/SQL/auth logic must not change.**

### B-01 — Token foundation + drift check
- [x] Done
- **Files:** `hubroute.php`, new `scripts/check-tokens.mjs`, `package.json`
- **Do:**
  - Replace the app's `:root` (`--bg:#fff; --pri:#080808 …`) with the full token set from §3, inline.
  - Set body `background:var(--bg)`, text `var(--text)`, keep Inter system stack.
  - Remove every `border-radius` except a new `.led{width:8px;height:8px;border-radius:999px}`.
  - Add `scripts/check-tokens.mjs`: reads `styles/tokens.css`, asserts every `--name:value` pair also appears (whitespace-insensitive) inside `hubroute.php`; exits 1 on mismatch. Wire into `package.json` → `"check:tokens": "node scripts/check-tokens.mjs"` and append it to the `test` script.
- **Accept:** `npm run check:tokens` passes; deliberately altering one token value in `hubroute.php` makes it fail (then revert); GLOBAL GATES pass.

### B-02 — App shell: header, nav, footer
- [x] Done
- **Files:** `hubroute.php` (`renderLayout()`)
- **Do:**
  - Topbar: white `--panel`, 1px `--line` bottom border. Left: inline route-arrow logo SVG (copy the exact markup from `index.html` nav) + `HubRoute` wordmark. Center/right: nav links styled as underline-tab items (`border-bottom:2px solid transparent`, active → `var(--brand)`, hover `--panel-2`), matching the demo console's `.tab` pattern. Far right: `email / ROLE` in mono 11px uppercase + Logout as a bordered secondary button.
  - Mark the current route's nav link active (compare `$_GET['r']` against the link target).
  - Footer: 1px top border, muted, `HubRoute · self-hosted parcel operations` + Public Tracking link.
- **Accept:** manual check on 3 roles (admin, hub, agent): logo renders, active tab is correct on ≥3 routes, keyboard focus ring visible on every link/button; GLOBAL GATES pass.

### B-03 — Buttons, forms, flashes
- [x] Done
- **Files:** `hubroute.php`
- **Do:** restyle to §3 rule 5/6: primary = solid cobalt (currently near-black), secondary = outline, danger = red outline→fill hover; min-height 38px. Inputs: squared, 1px `--line-strong`, focus `2px solid var(--brand)` outline, labels uppercase 11.5px `--muted`. Flashes: `ok` → left 2px `--ok` border on `#eef8f2`; `err` → `--danger` on light red tint; neutral info on `--brand-soft`. No layout/markup changes to forms beyond classes needed for styling.
- **Accept:** visual pass on login, parcel create, scan/event form; error flash and success flash both visibly distinct; GLOBAL GATES pass.

### B-04 — Status chips + LED (kill the gray pills)
- [x] Done
- **Files:** `hubroute.php`
- **Do:** replace `.pill` styling with squared status chips per §3 table: uppercase, 10.5px, letter-spaced, mono, 1px tinted border + tinted background + status color text. Map existing `bg-blue|indigo|purple` → pre-transit (brand), `bg-amber|orange|teal` → in-motion (warn), `bg-green` → success (ok), `bg-red` → problem (danger). Prepend the 8px `.led` dot inside each chip (same color). Keep the text label always (never color-only).
- **Accept:** parcel list shows 3+ visually distinct statuses; chip text readable at AA contrast (spot-check brand/ok/warn/danger tints against their backgrounds with a contrast checker ≥ 4.5:1); GLOBAL GATES pass.

### B-05 — Dashboard: KPIs + work queues that look alive
- [x] Done
- **Files:** `hubroute.php` (dashboard routes for each role)
- **Do:** KPI tiles → collapsed-border grid (one 1px `--line` wrapper, internal shared borders, no per-tile radius/shadow): mono 22px value, 10.5px uppercase muted label; the "needs attention" tile (exceptions/failed/unassigned) gets a `2px solid var(--brand)` top border. Under KPIs, each role keeps its existing queue tables but each table gets a header row with title + count chip + one primary action button (e.g. hub: `New parcel`, `Record event`).
- **Accept:** all four role dashboards render with the new KPI grid; numbers are mono; no horizontal scroll at 375px width; GLOBAL GATES pass.

### B-06 — Tables: density, hierarchy, mobile
- [x] Done
- **Files:** `hubroute.php`
- **Do:** `.table` → header row on `--panel-2`, 10.5px uppercase letter-spaced muted, sticky where it already was; tracking codes and amounts in `--mono`; row hover `--panel-2`; row link/action buttons compact (28px). At ≤ 720px, tables switch to stacked "card rows" (each `td` gets a `data-label` shown as an 10.5px muted label above the value) — pure CSS via `display:block` pattern; add the `data-label` attributes where tables are rendered.
- **Accept:** parcel list usable at 375px with no horizontal scroll; codes are mono; GLOBAL GATES pass.

### B-07 — Empty states everywhere
- [x] Done
- **Files:** `hubroute.php`
- **Do:** every list screen (parcels, routes, agents, hubs, events, settlements, users, audit) currently renders a bare table or nothing when empty. Add a shared `renderEmptyState($title, $hint, $actionLabel, $actionHref)` helper: centered block inside a dashed 1px `--line-strong` border, muted icon-free text, one primary button. Examples: parcels → "No parcels yet" / "Create the first parcel to start tracking custody." / `Create parcel`. Empty states must never show for public tracking "not found" (that has its own message).
- **Accept:** with a fresh DB (delete `data/hubroute.sqlite`, re-run first-run seed, or temporarily filter to an empty status) each list shows a designed empty state, not a bare table header; GLOBAL GATES pass.

### B-08 — Login screen = first impression
- [x] Done
- **Files:** `hubroute.php` (login route)
- **Do:** centered 400px max card on `--bg`: logo mark + `HubRoute` + one-line subtitle (`Parcel operations console`), email/password fields per B-03, full-width primary button `Sign in`, footer link to public tracking. Below the card, a muted 12px line: `Self-hosted · PHP 8 + SQLite`. No demo credentials rendered on this screen in any mode.
- **Accept:** login renders centered on desktop + mobile; no credentials text; focus order: email → password → submit; GLOBAL GATES pass.

### B-09 — Parcel detail: timeline as the hero
- [x] Done
- **Files:** `hubroute.php` (parcel detail route)
- **Do:** two-column layout ≥ 980px (main: timeline; side: parcel facts card + actions), single column below. Timeline: vertical list, each event = 2px `--line-strong` left border segment with the status-colored `.led` dot, event type as 12.5px semibold, hub + actor muted, timestamp mono 11px right-aligned. Latest event highlighted with `--brand-soft` background. Facts card: tracking code as mono 18px with a one-click `Copy` button (tiny inline JS `navigator.clipboard`, progressive enhancement — page must work without JS). COD amount always visible with settled/outstanding chip (competitor lesson §2.1).
- **Accept:** timeline reads top=newest, latest highlighted; copy button works; COD chip present on COD parcels; GLOBAL GATES pass.

### B-10 — Print-ready parcel label (waybill-lite)
- [x] Done
- **Files:** `hubroute.php`
- **Do:** add a `Print label` action on parcel detail → route `?r=parcel_label&id=…` (auth: same access rule as parcel detail) rendering a minimal A6-styled sheet: logo, tracking code huge in mono, from/to blocks, COD amount box, date, and a **pure-CSS code strip**: render the tracking code also as large mono in a bordered box (do NOT add a barcode library; note "scan = type the code" — keeps zero-dependency rule). `@media print` hides nav/footer. Button `window.print()` with a no-JS fallback note.
- **Accept:** label page renders; browser print preview fits one A6/A4; no new dependencies; unauthorized user gets the same denial as parcel detail; GLOBAL GATES pass.

### B-11 — First-run experience (fresh install confidence)
- [x] Done
- **Files:** `hubroute.php`
- **Do:** after first-run seeding, show a dismissible one-time banner (session-flagged) on the admin dashboard: "Fresh install — 3 steps to production: 1) create your real admin, 2) disable seed accounts, 3) run a backup (`php maintenance.php run --apply`)" with links to the relevant admin screens. Style: `--brand-soft` background, 2px `--brand` left border.
- **Accept:** banner appears once for admin on a fresh DB, dismisses, never shows again in that session; GLOBAL GATES pass.

---

## 6. Phase C — Public tracking: the storefront screen

Competitor rule (§2.2): the tracking page is the most-viewed, most-shared surface. It must look better than everything else.

### C-01 — PHP tracking page: progress stepper + hub path
- [ ] Done
- **Files:** `hubroute.php` (track route)
- **Do:**
  - Header: logo + `HubRoute` (links home), no auth nav.
  - Search state: single centered card, big mono input, primary `Track` button.
  - Result state, top to bottom:
    1. Tracking code (mono, large) + current status chip.
    2. **4-step progress stepper**: `Booked → At hub → Out for delivery → Delivered` (map internal statuses to these 4 public stages; failed/returned renders the stepper up to its last good stage plus a `--danger` chip). Completed steps: cobalt square markers connected by a 2px line; upcoming: `--line-strong`.
    3. **Hub path**: ordered horizontal chain of hub names (redacted to hub name + area only) with arrows, from the parcel's hub events.
    4. Event timeline (reuse B-09 styling), PII-redacted exactly as the current implementation redacts.
  - Not-found: friendly card "No parcel found for that code — check the number and try again", never an error dump.
  - Mobile-first: stepper wraps to vertical below 640px.
- **Accept:** track a seeded code end-state and a mid-transit code: stepper stage correct for both; no PII (recipient phone/full address) in page source; renders clean at 375px; GLOBAL GATES pass.

### C-02 — Demo console tracking parity
- [ ] Done
- **Files:** `src/demo/views.js` / `src/demo/main.js` (public tracking view), then `npm run sync:demo`
- **Do:** port the C-01 stepper + hub path to the browser demo's public tracking view so the walkthrough matches the product.
- **Accept:** `npm run sync:demo && npm run smoke:demo` pass; demo tracking shows the stepper; GLOBAL GATES pass.

---

## 7. Phase D — Vercel artifact: from placeholder to product *(separate deployable)*

Goal: someone with zero terminal experience deploys HubRoute tracking on Vercel free tier in ~10 minutes.

### D-01 — Restyle `vercel-app` to the design system
- [ ] Done
- **Files:** `vercel-app/styles.css`, `vercel-app/index.html`, `vercel-app/track.html`, `vercel-app/setup.html`
- **Do:** replace `vercel-app/styles.css` tokens with the §3 set (copy values verbatim from `styles/tokens.css`); zero radius; logo + favicon per A-04. `index.html` becomes a real mini-landing: logo, one-line value prop, primary `Track a parcel` CTA, and a bordered "Operator setup" card linking `/setup.html` + `/api/health` (move the env-var list into `setup.html` and the README — off the homepage).
- **Accept:** no `border-radius` > 0 in `vercel-app/styles.css` except a `.led` rule; homepage contains no env-var names; `npm run package:vercel` passes; GLOBAL GATES pass.

### D-02 — Tracking page: stepper + XSS fix
- [ ] Done
- **Files:** `vercel-app/track.html`
- **Do:** port the C-01 stepper/hub-path/timeline design. **Fix the injection risk:** current code interpolates API payload values into `innerHTML`; switch to building DOM nodes with `textContent` (or escape every interpolated value through a tiny `esc()` helper). Add loading skeleton (3 shimmer-free gray bars — static, no animation lib) and the friendly not-found card.
- **Accept:** a tracking value containing `<img onerror>` renders inert as text (test by temporarily hardcoding a malicious payload through the render path, then remove the test); stepper works against `/api/track`; GLOBAL GATES pass.

### D-03 — One-click deploy path
- [ ] Done
- **Files:** new `DEPLOY_VERCEL.md` (repo root), `vercel-app/README.md`, `README.md`, `vercel-app/vercel.json`
- **Do:** write `DEPLOY_VERCEL.md` — a beginner-proof, screenshot-level-explicit guide:
  1. Create free Turso account → create database → copy URL + token (exact button names).
  2. Fork/upload `vercel-app/` contents to a new GitHub repo (GitHub web UI path: "uploading an existing file", no git commands required).
  3. Vercel dashboard → Add New Project → import repo → paste the 5 env vars (`TURSO_DATABASE_URL`, `TURSO_AUTH_TOKEN`, `SESSION_SECRET`, `CRON_SECRET`, `SETUP_SECRET`) with a "generate a random secret" tip (`openssl rand -hex 32` OR "mash the keyboard 40+ chars" for non-terminal users).
  4. Deploy → verify `/api/health` → run `/setup` → track `HR260703DHK1A2`.
  5. Add a **Deploy with Vercel** button at the top: `https://vercel.com/new/clone?repository-url=<repo-url>&env=TURSO_DATABASE_URL,TURSO_AUTH_TOKEN,SESSION_SECRET,CRON_SECRET,SETUP_SECRET` (fill the real repo URL).
  6. Troubleshooting table: health returns 500 → check env vars; setup 403 → wrong `SETUP_SECRET`; blank tracking → run seed.
  Link `DEPLOY_VERCEL.md` from `README.md` (replace the current inline Vercel steps with a pointer) and from `vercel-app/README.md`.
- **Accept:** `DEPLOY_VERCEL.md` exists, contains the deploy-button URL with all 5 env keys, zero required terminal commands in the happy path; `README.md` links to it; GLOBAL GATES pass.

---

## 8. Phase E — Punch list & polish loop

### E-01 — Accessibility sweep
- [ ] Done
- **Files:** all HTML surfaces + `hubroute.php`
- **Do:** every interactive element has a visible focus state; every input has a `<label>`; status chips include text; icon-only buttons get `aria-label`; landing/docs/tracking pass axe (0 critical) and contrast AA.
- **Accept:** Lighthouse a11y ≥ 95 on landing, docs, demo, PHP tracking; GLOBAL GATES pass.

### E-02 — Performance & weight budget
- [ ] Done
- **Files:** all surfaces
- **Do:** landing < 250 KB transferred (images lazy + compressed from A-02); no render-blocking external requests (fonts are system/Inter-local already — verify no CDN crept in); demo `public/index.html` still boots < 2s on throttled mid-tier mobile profile.
- **Accept:** Lighthouse perf ≥ 90 landing + docs; `grep -RniE "fonts.googleapis|cdn.jsdelivr|unpkg" index.html docs.html hubroute.php src/ vercel-app/ --include=*.html --include=*.css --include=*.js --include=*.php` returns nothing; GLOBAL GATES pass.

### E-03 — Copy consistency sweep
- [ ] Done
- **Files:** all user-visible strings
- **Do:** one product name (`HubRoute`), one tagline everywhere; status vocabulary matches §3 table across landing, demo, PHP app, Vercel app; dates rendered consistently (`YYYY-MM-DD HH:MM`, Asia/Dhaka); currency always `৳` prefix with mono digits.
- **Accept:** spot-check matrix (4 surfaces × status names × date format) documented as a table appended under this task; GLOBAL GATES pass.

### E-04+ — (Loop-generated tasks)
Add new tasks here during the final audit (§9), using the same format: ID, Files, Do, Accept. Never edit completed task text.

---

## 9. Final audit (run when all boxes are [x] or [B])

Walk this checklist as a skeptical first-time visitor. Any "no" → add an E-task and loop.

1. Open the landing page cold. Within 5 seconds, is it clear this is a real product you can deploy today (not a demo)? Is there at least one real screenshot?
2. Click every nav link and CTA on landing + docs. Zero dead anchors, zero 404s.
3. Deploy the PHP zip to a local `php -S` server from scratch. Does login → dashboard → create parcel → scan event → public track feel like ONE product with ONE design language (compare side-by-side with the landing page)?
4. Fresh DB: does every empty list show a designed empty state?
5. Open public tracking on a phone-width viewport. Stepper correct? Shareable? No PII?
6. Follow `DEPLOY_VERCEL.md` literally, as a beginner. Any step that requires prior knowledge → fix the doc.
7. Run all GLOBAL GATES + `npm run check:tokens`. Green?
8. Lighthouse: landing ≥ 90/95 (perf/a11y), docs ≥ 90/95, PHP tracking a11y ≥ 95.
9. `git log` shows one commit per task with `PASS <ID>` format.

When all 9 pass and §8 has no unchecked tasks: **STOP. Output a summary of every task completed, every task blocked, and remaining known limitations.**

---

## Appendix — repo map (orientation for each loop iteration)

| Path | What it is | Phases that touch it |
|---|---|---|
| `index.html`, `docs.html` | Static landing + docs (GitHub Pages) | A, E |
| `styles/tokens.css` | Canonical design tokens (do not change values) | reference only |
| `brand/` | Logo SVGs + favicons | reference only |
| `hubroute.php` | THE production app (single file, PHP 8 + SQLite) | B, C, E |
| `index.php`, `health.php`, `maintenance.php`, `.htaccess` | Prod runtime support | untouched |
| `src/demo/*` | Browser demo source → built to `public/index.html` + `vercel-demo/` | C-02, A-04 |
| `scripts/*.mjs` | Build/sync/package/smoke scripts | B-01 adds one |
| `vercel-app/` | Separate Vercel + Turso deployable | D |
| `tests/`, `.github/workflows/static-checks.yml` | Domain tests + CI | must stay green |
| `CODEX_BUILD_PLAN.md` | Backend milestone plan (M0–M4) | unchanged; this file wins on UI scope |

**Suggested loop order note:** A-02 (screenshots) depends on B + C being done — the loop protocol's blocked-task rule handles this automatically: block it on the first pass, complete it near the end.
