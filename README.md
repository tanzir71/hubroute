# HubRoute MVP

HubRoute is a compact parcel operations product with a simple PHP 8 + SQLite backend for shared hosting or a small VPS. It supports customer pickup requests, system-generated tracking numbers, hub assignment, rider delivery runs, public tracking, COD settlement, audit logging, idempotent mutations, and route CSV export.

Production app entry: `hubroute.php`
Production PHP files: `hubroute.php`, `maintenance.php`, `index.php`, `health.php`, `.htaccess`, `.env.example`
Setup runbook: [SETUP.md](SETUP.md)
Security notes: [SECURITY.md](SECURITY.md)
Repository: <https://github.com/tanzir71/hubroute>

## Get Running Fast

Shared-hosting production zip, no terminal required:

1. Get `hubroute-php-sqlite.zip`.
2. Log in to cPanel or your host control panel.
3. Open File Manager.
4. Turn on “Show Hidden Files” if your panel has that option.
5. Open `public_html` or the folder for your subdomain.
6. Upload `hubroute-php-sqlite.zip`.
7. Click Extract.
8. Make sure `index.php`, `hubroute.php`, `health.php`, `maintenance.php`, `.htaccess`, `.env.example`, and `data/` are directly inside the web folder.
9. Delete the zip after extraction.
10. Set PHP to 8.1+ and enable `pdo_sqlite` plus `sqlite3`.
11. Open `https://YOUR-DOMAIN/health.php`.
12. Open `https://YOUR-DOMAIN/`.

If extraction creates a folder named `hubroute-php-sqlite`, move the files inside that folder up into `public_html` unless you intentionally want the app at `/hubroute-php-sqlite/`.

LLM-assisted deploy: point an LLM or deployment agent at this repo and use the prompt in [SETUP.md](SETUP.md#option-3-ask-an-llm-or-deployment-agent).

Automated backup and cleanup cron:

```bash
php maintenance.php run --apply
```

The command backs up SQLite first, then prunes old terminal parcels, old audit rows, old idempotency keys, old rate-limit rows, and expired backup files according to `.env` retention settings.

## Deployment Targets

Shared hosting and small VPS deployments are the supported production path today: upload/extract `hubroute-php-sqlite.zip` or sync the extracted PHP runtime files over SSH, then run the PHP 8 + SQLite app.

The Vercel browser demo is maintainer-only and is not a user deployment path. A production Vercel app should be a separate Vercel-native port using Next.js/Node functions and hosted SQLite-compatible storage such as libSQL/Turso if the product must remain SQLite-based. The PHP + local SQLite production bundle is intentionally kept simple for shared hosts.

## Why PHP + SQLite

HubRoute uses PHP + SQLite deliberately. Many courier, merchant, and local-operations teams in emerging markets still start on inexpensive shared hosting, cPanel, FTP/SFTP uploads, or older LAMP-style infrastructure where containers, managed databases, and always-on Node services may be unavailable, expensive, or hard for local IT teams to support.

Technical advantages:

- Broad backward compatibility with existing shared hosts, Apache/Nginx/PHP-FPM setups, and small VPSes.
- Low startup cost because there is no required managed database, queue, Redis instance, container registry, or app server process.
- Simple deployment by zip extraction, file copy, FTP/SFTP, cPanel file manager, or `rsync`.
- SQLite keeps early production data in one file, which makes backup, restore, migration, and support handoff straightforward.
- PHP's request-scoped runtime lets the host manage workers, memory cleanup, process restarts, HTTPS, and logs.
- Mature built-in security primitives such as PDO prepared statements, `password_hash()`, and standard session handling.
- A clear upgrade path: the same product rules can later move to a Vercel-native or managed-database version without raising the first deployment cost.

## Default Seed Accounts

Change or disable these immediately after first deployment under `Admin -> Users`.

- Admin: `admin@hubroute.local` / `admin1234`
- Hubs: `pickuphub@hubroute.local`, `warehouse@hubroute.local`, `eastmile@hubroute.local` / `hub1234`
- Agents: `amina@hubroute.local`, `noah@hubroute.local`, `liam@hubroute.local`, `maya@hubroute.local`, `sofia@hubroute.local`, `owen@hubroute.local` / `agent1234`
- Customers: `alice@example.com`, `bob@example.com` / `customer1234`

Production rotation flow: log in as the seeded admin, create a real production admin in `Admin -> Users`, log in as that production admin, then reset or disable every seeded account and confirm old seed passwords fail.

## Security Posture

- PDO prepared statements with bound parameters.
- `htmlEscape()`/`e()` escaping for template output.
- CSRF tokens for state-changing forms.
- `password_hash()` / `password_verify()` for accounts.
- `session_regenerate_id()` after login plus idle/absolute timeouts.
- SQLite rate limiting for login and POST actions.
- SQLite-backed idempotency keys for parcel, event, route, settlement, and admin form mutations.
- SQLite audit log for privileged and custody-affecting mutations.
- Admin user access control for creating production admins, resetting passwords, and disabling unused or seeded accounts.
- CLI maintenance for SQLite backups and retention cleanup.
- CSP and standard browser security headers.
- Authorization checks for parcel, route, hub, agent, settlement, and CSV access.

The M1 SQLite/PHP backend contract lives at [docs/m1-backend-contract.md](docs/m1-backend-contract.md).
The executable status-transition, authorization, idempotency, and audit-log rules live in [src/domain/status-rules.mjs](src/domain/status-rules.mjs), [src/domain/authorization-rules.mjs](src/domain/authorization-rules.mjs), [src/domain/idempotency-rules.mjs](src/domain/idempotency-rules.mjs), and [src/domain/audit-rules.mjs](src/domain/audit-rules.mjs).

## Maintainer / Source Commands

These commands are for maintainers or developers working from the source repo. They run on your computer, CI, or a deployment agent. They do not run on cPanel/shared hosting.

Build the shared-hosting zip from source:

```bash
npm run deploy:shared
```

The browser walkthrough is separate from production and is maintainer-only. It is generated from `src/demo/` into `public/index.html` and stores changes in localStorage.

Local smoke run:

```bash
php -S 127.0.0.1:8080
```

```bash
npm run sync:demo
npm run check:demo
```

`npm test` runs the domain-rule tests, generated-output checks, and browser walkthrough smoke test.

Internal Vercel demo deploy:

```bash
npm run maintainer:deploy:demo
npm run maintainer:deploy:demo:preview
```

## Static Security Scan

On Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File tests\security_scan.ps1
```

The script checks for the expected hardening hooks and common direct SQL concatenation patterns.
