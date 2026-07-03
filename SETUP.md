# HubRoute Setup

HubRoute production is the PHP 8 + SQLite app. No external database is required.

## Deployment Targets

| Target | Status | Best path | Notes |
| --- | --- | --- | --- |
| Shared hosting / cPanel | Supported production | Upload and extract `hubroute-php-sqlite.zip` | PHP 8.1+, `pdo_sqlite`, `sqlite3`, writable private data directory, and cron for maintenance. No terminal is required if you already have the zip. |
| VPS / SSH host | Supported production | Upload/extract the zip or sync the extracted files | Use Apache, Nginx, or PHP-FPM for traffic. The built-in PHP server is only for smoke tests. |
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

### Option 1: Shared-hosting production zip, no terminal needed

Use this path if you have cPanel, DirectAdmin, Plesk, a host file manager, FTP, or SFTP.

What you need before starting:

- `hubroute-php-sqlite.zip`
- A domain or subdomain pointed at your hosting account
- PHP 8.1 or newer
- PHP extensions: `pdo_sqlite` and `sqlite3`

Steps:

1. Log in to your hosting control panel.
2. Open File Manager.
3. Turn on “Show Hidden Files” if your panel has that option. Files like `.htaccess` and `.env.example` are supposed to be there.
4. Open your website folder. This is usually `public_html`; for a subdomain, it may be a folder named after the subdomain.
5. Upload `hubroute-php-sqlite.zip` into that folder.
6. Select the zip and click Extract.
7. After extraction, confirm these files are visible directly in the website folder: `index.php`, `hubroute.php`, `health.php`, `maintenance.php`, `.htaccess`, `.env.example`, and `data/`.
8. If the files are inside a new folder named `hubroute-php-sqlite`, move the files from inside that folder up into `public_html` unless you intentionally want the app to live at `/hubroute-php-sqlite/`.
9. Delete `hubroute-php-sqlite.zip` from the hosting folder after extraction.
10. In your host panel, set the site to PHP 8.1 or newer.
11. Enable `pdo_sqlite` and `sqlite3` if your host exposes PHP extensions.
12. Open `https://YOUR-DOMAIN/health.php`.
13. If every check passes, open `https://YOUR-DOMAIN/`.
14. Log in as the seeded admin, create your production admin, then rotate or disable seeded accounts before real use.

This zip deployment does not require build tools, Composer, Git, or a separate database service on the shared host.

The package contains a small `README.txt` plus these runtime files:

- `hubroute.php`
- `maintenance.php`
- `index.php`
- `health.php`
- `.htaccess`
- `.env.example`
- `data/.htaccess`
- `data/index.html`

### Option 2: One-command SSH deploy

Use this only if you are comfortable with SSH. Extract `hubroute-php-sqlite.zip` locally, replace the host path, and run:

```bash
rsync -az --delete hubroute-php-sqlite/ USER@HOST:/home/USER/public_html/
```

Then open `/health.php` and `/`.

### Option 3: Ask an LLM or deployment agent

Point the LLM at this repository and give it this prompt:

```text
Deploy HubRoute from this repo as the PHP 8 + SQLite production app.
Use only hubroute.php, maintenance.php, index.php, health.php, .htaccess, .env.example, and the data denial files.
Do not deploy the browser walkthrough, Node source, generated static output, .git, .env, logs, or SQLite databases.
Confirm PHP 8.1+, pdo_sqlite, sqlite3, writable DATA_DIR, /health.php, and the first login.
Configure cron to run php maintenance.php run --apply for automated backups and retention cleanup.
Keep SQLite as production storage. Do not add Postgres, MySQL, Supabase, or another backend.
If the host allows it, put DATA_DIR outside public_html and leave DB_PATH unset unless needed.
After deploy, use Admin -> Users to create a production admin and rotate or disable all seeded accounts before real operations.
```

## Vercel Production Path

Vercel production is a developer/agent port, not a zip upload. Use this path only when the product owner specifically wants HubRoute to run on Vercel infrastructure.

What does **not** carry over:

- Do not upload `hubroute-php-sqlite.zip` to Vercel.
- Do not expect Vercel to run `hubroute.php`, `maintenance.php`, or `.htaccess`.
- Do not use a writable local `data/hubroute.sqlite` file in production. Vercel functions are stateless and local files are not durable application storage.
- Do not deploy the static browser walkthrough as the user-facing production app. The demo is maintainer-only.

Can HubRoute provide a Vercel-ready fileset? Yes. This repo includes `vercel-app/` and packages it as `dist/hubroute-vercel.zip`. It is a second artifact, not a replacement for `hubroute-php-sqlite.zip`.

The current Vercel artifact contains:

- `package.json`, `package-lock.json`, and Vercel app source.
- `vercel.json` with cron configuration.
- Static setup and public tracking pages.
- API routes for health, protected setup, public tracking, and protected maintenance cron.
- Hosted SQLite-compatible database client setup through Turso/libSQL.
- Schema creation and a seed script for first-run starter data.
- `.env.example` listing required Vercel environment variables, but no real tokens.

Full dashboard/login/admin parity can be layered into this artifact later by porting the remaining `hubroute.php` flows.

What a fileset still cannot include:

- A real Vercel project connection.
- Production secrets such as `TURSO_AUTH_TOKEN`, `SESSION_SECRET`, or `CRON_SECRET`.
- A durable local SQLite file checked into the deployment.
- A configured custom domain owned by the deployer.

Recommended Vercel shape:

- Keep the current PHP + SQLite shared-hosting app intact.
- Use the separate Vercel-native app in `vercel-app/` as the deployable starter.
- Rebuild server behavior in Next.js App Router or another Vercel-supported server runtime.
- Keep SQLite semantics by using hosted SQLite-compatible storage such as Turso/libSQL.
- Recreate the PHP app's auth, role checks, status transitions, idempotency, audit log, CSV export, public tracking, user access control, and retention rules.
- Replace `maintenance.php` with a protected Vercel Cron route.

Suggested route mapping:

| Current PHP behavior | Vercel-native equivalent |
| --- | --- |
| `/` / `index.php` | Next.js app shell and authenticated dashboard routes |
| `hubroute.php?r=track&code=...` | `/track/[code]` page plus `/api/track/[code]` redacted public API |
| `hubroute.php` POST actions | Server Actions or `/api/...` route handlers |
| `health.php` | `/api/health` checking env, database connection, and write capability |
| `maintenance.php run --apply` | `/api/cron/maintenance` called by Vercel Cron |
| SQLite file at `data/hubroute.sqlite` | Hosted libSQL/Turso database |
| PHP sessions | Signed cookies plus a durable session table, or an auth provider chosen for the Vercel app |

Minimum Vercel environment variables:

- `TURSO_DATABASE_URL`
- `TURSO_AUTH_TOKEN`
- `SESSION_SECRET`
- `CRON_SECRET`
- `APP_TIMEZONE`
- Retention settings matching the PHP app: backup, terminal parcel, audit, idempotency, and rate-limit retention windows.

Cron shape:

```json
{
  "crons": [
    {
      "path": "/api/cron/maintenance",
      "schedule": "15 2 * * *"
    }
  ]
}
```

The cron route must verify `Authorization: Bearer ${CRON_SECRET}` before running backup or cleanup work.

Deploy flow:

1. Build or unzip the Vercel-native app separately from the PHP zip.
2. Provision hosted SQLite-compatible storage and add the environment variables to Vercel.
3. Deploy `hubroute-vercel.zip` or the `vercel-app/` folder.
4. Open `/api/health`.
5. Open `/setup` and enter `SETUP_SECRET` to create schema and starter data.
6. Open `/track/HR260703DHK1A2`.
7. Add the remaining dashboard/login/admin flows when moving beyond the starter artifact.

LLM prompt for that path:

```text
Port HubRoute to a Vercel-native production app.
Keep the existing PHP + SQLite shared-hosting build intact and do not upload the PHP zip to Vercel.
Use the separate Vercel-ready fileset in vercel-app/ or dist/hubroute-vercel.zip.
Build on the included Vercel Node Functions starter or port additional flows to Next.js App Router.
Use hosted SQLite-compatible storage such as Turso/libSQL so production remains SQLite-based.
Do not use local filesystem SQLite for Vercel production.
Preserve auth, RBAC, status transitions, idempotency, audit logging, CSV export, public tracking, user access control, and maintenance retention rules.
Map health.php to /api/health and maintenance.php to a protected /api/cron/maintenance route.
Configure Vercel environment variables: TURSO_DATABASE_URL, TURSO_AUTH_TOKEN, SESSION_SECRET, CRON_SECRET, APP_TIMEZONE, and retention settings.
Do not replace SQLite with Postgres, MySQL, Supabase, or another backend unless the project owner explicitly changes the database decision.
Use preview deployments for validation, then promote the validated preview or deploy production.
After deploy, create a production admin and rotate or disable seeded accounts.
```

## Shared-Hosting Checklist

1. PHP version is 8.1 or newer.
2. `pdo_sqlite` is enabled.
3. `sqlite3` is enabled.
4. The extracted app files are directly in the website folder, not accidentally nested one folder too deep.
5. Hidden files are present: `.htaccess` and `.env.example`.
6. `health.php` opens in the browser and shows passing checks.
7. `/` opens the app.
8. Optional but recommended: copy `.env.example` to `.env` for custom paths, timezone, or rate limits.
9. If possible, set `DATA_DIR` in `.env` to a writable private folder outside `public_html`.
10. Leave `DB_PATH` commented unless the database file must live somewhere other than `DATA_DIR/hubroute.sqlite`.
11. If using the default `data/` folder, confirm `/data/hubroute.sqlite` is not downloadable.
12. Use `Admin -> Users` to create a production admin, then rotate or disable seeded credentials before entering real parcel/customer data.
13. Add the maintenance cron below for automated backups and old-data cleanup.

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

## Rotate Or Disable Seed Credentials

Do this immediately after the first production deployment and before entering real parcel, merchant, rider, or customer data.

1. Log in as the seeded admin: `admin@hubroute.local` / `admin1234`.
2. Open `Admin -> Users`.
3. Create a production admin with a real email address and a unique temporary password of at least 12 characters.
4. Log out, then log back in as the production admin.
5. Return to `Admin -> Users`.
6. For every seeded user, either set a unique production password or set status to `disabled`.
7. Disable `admin@hubroute.local` after the production admin login is confirmed.
8. Confirm the old seed passwords no longer work.
9. Open `Admin -> Audit` and confirm the user access changes were recorded.
10. Run a backup after rotation:

```bash
php maintenance.php backup
```

Use active/disabled status as user access control. Admins can reset passwords, disable unused accounts, re-enable accounts, and create production admin users. Hub, agent, and customer access remains scoped by role and linked hub/agent/customer records.

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
