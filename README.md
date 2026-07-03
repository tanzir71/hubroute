# HubRoute MVP

HubRoute is a compact parcel operations product with a static GitHub landing page, a browser-only Vercel walkthrough, and a simple PHP 8 + SQLite backend for the real shared-hosting app. It supports customer pickup requests, hub assignment, rider delivery runs, public tracking, COD settlement, audit logging, idempotent mutations, and route CSV export.

Landing page: `index.html` on GitHub Pages
Docs page: `docs.html` on GitHub Pages
Hosted demo: <https://hubroute.vercel.app/>
Vercel demo source: `public/index.html`
Source app entry: `hubroute.php`
Repository: <https://github.com/tanzir71/hubroute>
Legacy PHP demo package: `hubroute-demo.zip`

## Demo Source Workflow

`src/demo/` is the canonical browser-demo source. The source is split into ES modules for seeded data, the localStorage API shim, session/filter state, components/helpers, view routing, and the console orchestration layer.

`styles/tokens.css` is the shared design-token source for the landing page, docs, and console. The demo build copies it to `public/styles/tokens.css`; `npm run sync:demo` mirrors that copy to `vercel-demo/styles/tokens.css`.

`public/index.html` is the generated single-file demo output for Vercel. `vercel-demo/index.html` is kept as a generated mirror for legacy/static deploy paths.

```bash
npm run build:demo  # bundle src/demo into public/index.html
npm run sync:demo   # build public/index.html and mirror it to vercel-demo/index.html
npm run check:demo  # fail if either generated demo file has drifted
```

`npm test` runs the generated-output checks so pull requests do not accidentally edit only the built files.
The smoke test also boots the console with an empty valid dataset to verify empty/error handling on the operational screens.

## Security Posture

- PDO prepared statements with bound parameters.
- `htmlEscape()`/`e()` escaping for template output.
- CSRF tokens for state-changing forms.
- `password_hash()` / `password_verify()` for accounts.
- `session_regenerate_id()` after login plus idle/absolute timeouts.
- SQLite rate limiting for login and POST actions.
- SQLite-backed idempotency keys for parcel, event, route, settlement, and admin form mutations.
- SQLite audit log for privileged and custody-affecting mutations.
- CSP and standard browser security headers.
- Authorization checks for parcel, route, hub, agent, settlement, and CSV access.

See [docs.html](docs.html) for the product walkthrough, [SECURITY.md](SECURITY.md) for the full security notes, and [SETUP.md](SETUP.md) for optional PHP deployment notes.
The M1 SQLite/PHP backend contract lives at [docs/m1-backend-contract.md](docs/m1-backend-contract.md).
The executable status-transition, authorization, idempotency, and audit-log rules for that contract live in [src/domain/status-rules.mjs](src/domain/status-rules.mjs), [src/domain/authorization-rules.mjs](src/domain/authorization-rules.mjs), [src/domain/idempotency-rules.mjs](src/domain/idempotency-rules.mjs), and [src/domain/audit-rules.mjs](src/domain/audit-rules.mjs).

## Hosted Demo Accounts

The hosted browser demo highlights a Bangladesh hub-and-spoke network. All demo accounts use `hub1234`.

- Demo URL: <https://hubroute.vercel.app/>
- Pickup hub: `pickuphub@hubroute.local`
- Sortation hub: `sortation@hubroute.local`
- Delivery hub: `ctghub@hubroute.local`
- Return hub: `savarhub@hubroute.local`

Use these accounts to inspect parcel queues, CRUD, search/filter panels, route/rider assignment, hub-to-hub handoff, scan/event capture, and public tracking. Change or disable all seeded credentials before production use.

## Default Seed Accounts

Change these immediately after first deployment.

- Admin: `admin@hubroute.local` / `admin1234`
- Hubs: `pickuphub@hubroute.local`, `warehouse@hubroute.local`, `eastmile@hubroute.local` / `hub1234`
- Agents: `amina@hubroute.local`, `noah@hubroute.local`, `liam@hubroute.local`, `maya@hubroute.local`, `sofia@hubroute.local`, `owen@hubroute.local` / `agent1234`
- Customers: `alice@example.com`, `bob@example.com` / `customer1234`

## Local Run

```bash
cp .env.example .env
mkdir -p data
php -S 127.0.0.1:8080 hubroute.php
```

Open `http://127.0.0.1:8080/hubroute.php`.

## PHP Deployment ZIP

`hubroute-demo.zip` is the older PHP demo-runtime bundle. The primary public demo now runs at Vercel from `public/index.html`; the real landing page and docs stay in the GitHub repo/GitHub Pages. Any PHP bundle should exclude private/runtime state such as `.env`, SQLite databases, logs, `.git`, and design-source notes.

## Static Security Scan

On Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File tests\security_scan.ps1
```

The script checks for the expected hardening hooks and common direct SQL concatenation patterns.
