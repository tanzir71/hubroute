# HubRoute Setup

HubRoute production is the PHP 8 + SQLite app. No external database is required.

## Deployment Targets

| Target | Status | Best path | Notes |
| --- | --- | --- | --- |
| Shared hosting / cPanel | Supported production | `npm run deploy:shared` | PHP 8.1+, `pdo_sqlite`, `sqlite3`, writable private data directory, and cron for maintenance. |
| VPS / SSH host | Supported production | `npm run deploy:shared && rsync ...` | Use Apache, Nginx, or PHP-FPM for traffic. The built-in PHP server is only for smoke tests. |
| Vercel static walkthrough | Supported demo only | `npm run deploy:vercel` | This deploys the browser walkthrough generated into `public/index.html`; it stores data in browser storage and is not the production backend. |
| Vercel production app | Requires a Vercel-native port | Next.js/Node functions plus hosted storage | The current PHP + local SQLite bundle is for PHP hosts. A production Vercel version should preserve the product rules while moving server code to Vercel-supported runtimes and using hosted SQLite-compatible storage such as libSQL/Turso if SQLite semantics must remain. |

## Why PHP + SQLite

HubRoute uses PHP + SQLite because the production target is practical, not fashionable. In many emerging-market deployments, the available infrastructure is a shared host, cPanel account, FTP/SFTP access, or a small VPS maintained by a local vendor. PHP remains a good fit for that reality.

Advantages:

- Backward-compatible with older shared-host and LAMP-style infrastructure.
- Deployable by zip extraction or file copy, without a container platform or build server.
- Low startup cost: no required managed database, Redis, queue, object storage, or always-on app server.
- SQLite is a single local database file, which keeps backups, restores, staging copies, and support handoff easy.
- PHP's request lifecycle reduces long-running-process operations; the host manages PHP workers, restarts, HTTPS, and logs.
- Works on shared hosting first, then moves cleanly to a VPS with Apache, Nginx, or PHP-FPM as usage grows.
- Mature security and data-access primitives are available in the base platform, including PDO prepared statements, `password_hash()`, and standard session controls.

## Fastest Paths

### Option 1: Vercel browser demo

```bash
npm run deploy:vercel
```

This rebuilds the browser walkthrough, runs the test suite, and deploys `public/` to Vercel production with `npx --yes vercel deploy --prod`. The first run may ask you to log in or link the Vercel project.

For a preview URL instead of production:

```bash
npm run deploy:vercel:preview
```

Use this path for the interactive Vercel demo only. It does not run the PHP backend or create the production SQLite database.

### Option 2: Shared-hosting production zip

```bash
npm run deploy:shared
```

Upload `dist/hubroute-php-sqlite.zip` to the hosting folder, extract it, open `/health.php`, then open `/`.
If someone gave you a prebuilt `hubroute-php-sqlite.zip`, skip the command and just upload/extract that file.

The package contains a small `README.txt` plus these runtime files:

- `hubroute.php`
- `maintenance.php`
- `index.php`
- `health.php`
- `.htaccess`
- `.env.example`
- `data/.htaccess`
- `data/index.html`

`npm run deploy:shared` is an easy name for `npm run package:php`.

### Option 3: One-command SSH deploy

From a checked-out repo, replace the host path and run:

```bash
npm run deploy:shared && rsync -az --delete dist/hubroute-php-sqlite/ USER@HOST:/home/USER/public_html/
```

Then open `/health.php` and `/`.
If someone gave you a prebuilt zip, extract it locally and sync the extracted folder instead.

### Option 4: Ask an LLM or deployment agent

Point the LLM at this repository and give it this prompt:

```text
Deploy HubRoute from this repo as the PHP 8 + SQLite production app.
Use only hubroute.php, maintenance.php, index.php, health.php, .htaccess, .env.example, and the data denial files.
Do not deploy the browser walkthrough, Node source, generated static output, .git, .env, logs, or SQLite databases.
Confirm PHP 8.1+, pdo_sqlite, sqlite3, writable DATA_DIR, /health.php, and the first login.
Configure cron to run php maintenance.php run --apply for automated backups and retention cleanup.
Keep SQLite as production storage. Do not add Postgres, MySQL, Supabase, or another backend.
If the host allows it, put DATA_DIR outside public_html and leave DB_PATH unset unless needed.
After deploy, rotate or disable all seeded accounts before real operations.
```

## Vercel Paths

### Browser walkthrough on Vercel

Use this only for the public demo or product walkthrough:

```bash
npm run deploy:vercel
```

This path rebuilds and tests the static browser walkthrough from `public/`, then runs `npx --yes vercel deploy --prod`. It does not use the PHP backend, does not create the production SQLite database, and does not run real operations.

### Production build on Vercel

Vercel production is possible, but it is not the same artifact as the shared-hosting zip. The existing production backend is PHP 8 + local SQLite, while Vercel production functions run on Vercel-supported runtimes such as Node.js, Edge, Bun, or Rust.

If a user wants HubRoute production on Vercel, treat it as a Vercel-native port:

1. Keep the PHP + SQLite shared-hosting app intact.
2. Rebuild server routes in Next.js or another Vercel-supported Node runtime.
3. Keep the same auth, role checks, status transitions, idempotency, audit logging, CSV export, public tracking, and retention behavior.
4. Use hosted SQLite-compatible storage such as libSQL/Turso if the deployment must remain SQLite-based.
5. Configure Vercel environment variables and a cron route for the backup/cleanup equivalent.
6. Deploy with either `vercel deploy --prod` or `vercel build --prod && vercel deploy --prebuilt --prod`.

LLM prompt for that path:

```text
Port HubRoute to a Vercel-native production app.
Keep the existing PHP + SQLite shared-hosting build intact.
Rebuild server behavior in Next.js or Vercel-supported Node routes.
Preserve auth, RBAC, status transitions, idempotency, audit logging, CSV export, public tracking, and maintenance retention rules.
Use hosted SQLite-compatible storage such as libSQL/Turso if production must stay SQLite-based.
Do not replace SQLite with Postgres, MySQL, Supabase, or another backend unless the project owner explicitly changes the database decision.
Configure Vercel environment variables, a production build, and a cron route for backup/cleanup behavior.
Deploy with vercel deploy --prod or vercel build --prod followed by vercel deploy --prebuilt --prod.
```

## Shared-Hosting Checklist

1. In cPanel or the host panel, set the domain or subdomain to PHP 8.1 or newer.
2. Enable `pdo_sqlite` and `sqlite3`.
3. Upload the generated zip or the runtime files listed above.
4. Optional: copy `.env.example` to `.env` for custom paths, timezone, or rate limits.
5. If possible, set `DATA_DIR` in `.env` to a writable private folder outside `public_html`.
6. Leave `DB_PATH` commented unless the database file must live somewhere other than `DATA_DIR/hubroute.sqlite`.
7. Open `/health.php` and confirm every check passes.
8. Open `/` or `/hubroute.php?r=login&demo=hub`.
9. Confirm `/data/hubroute.sqlite` is not downloadable if `DATA_DIR=data`.
10. Rotate or disable seeded credentials before entering real parcel/customer data.
11. Add the maintenance cron below for automated backups and old-data cleanup.

## First Login

The PHP app seeds one hub operator account for the first check:

- URL: `/hubroute.php?r=login&demo=hub`
- Email: `pickuphub@hubroute.local`
- Password: `hub1234`

The URL prefills only the email field. Type the password manually.

## Local Smoke Run

If PHP is installed locally:

```bash
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`. The built-in PHP server is for local smoke testing, not production traffic.

## Seeded Accounts

Change these immediately after first deployment.

- Admin: `admin@hubroute.local` / `admin1234`
- Hubs: `pickuphub@hubroute.local`, `warehouse@hubroute.local`, `eastmile@hubroute.local` / `hub1234`
- Agents: `amina@hubroute.local`, `noah@hubroute.local`, `liam@hubroute.local`, `maya@hubroute.local`, `sofia@hubroute.local`, `owen@hubroute.local` / `agent1234`
- Customers: `alice@example.com`, `bob@example.com` / `customer1234`

## Automated Backups And Cleanup

HubRoute includes a CLI-only maintenance command. It creates a SQLite backup before any applied cleanup, then removes old terminal parcels and other old operational rows according to retention settings.

Default retention:

- Backups: 30 days
- Terminal parcels and their events: 180 days
- Audit log: 365 days
- Idempotency keys: 14 days
- Rate limits: 7 days

Dry-run first:

```bash
php maintenance.php prune
```

Run backup plus cleanup:

```bash
php maintenance.php run --apply
```

Recommended nightly cron on cPanel:

```cron
15 2 * * * /usr/local/bin/php /home/CPANEL_USER/public_html/maintenance.php run --apply >> /home/CPANEL_USER/hubroute-maintenance.log 2>&1
```

Replace `CPANEL_USER` with your hosting username and adjust the path if the app is not in `public_html`.

Retention is configured in `.env`:

```dotenv
BACKUP_DIR=data/backups
MAINTENANCE_BACKUP_KEEP_DAYS=30
MAINTENANCE_PRUNE_TERMINAL_DAYS=180
MAINTENANCE_PRUNE_AUDIT_DAYS=365
MAINTENANCE_PRUNE_IDEMPOTENCY_DAYS=14
MAINTENANCE_PRUNE_RATE_LIMIT_DAYS=7
MAINTENANCE_VACUUM_AFTER_PRUNE=true
```

Active parcels are never pruned by this command. Only parcels in `delivered`, `failed`, or `returned` status older than the terminal retention window are removed.

## Permissions

- `hubroute.php`: `644`
- `maintenance.php`: `644`
- `health.php`: `644`
- `index.php`: `644`
- `.htaccess`: `644`
- `.env.example`: `644`
- `.env`: `600` or the strictest permission your host supports
- `data/`: `750` or `770`
- `data/hubroute.sqlite`: created by PHP; keep writable by the PHP user only and include it in backups
- `data/backups/`: created by maintenance; keep private and include it in off-host backup rotation if possible
