# HubRoute Setup

## Hosted Browser Demo

The public product walkthrough runs at <https://hubroute.vercel.app/> from `public/index.html`. It is browser-only, dependency-free, and stores demo changes in localStorage.

`src/demo/` is the browser-demo source. After editing it, run `npm run sync:demo` to rebuild `public/index.html` and refresh `vercel-demo/index.html`; CI and `npm test` run `npm run check:demo` to catch drift.

Shared UI tokens live in `styles/tokens.css`. The demo build copies that file into the generated demo folders.

Demo accounts use password `hub1234`:

- `pickuphub@hubroute.local`
- `sortation@hubroute.local`
- `ctghub@hubroute.local`
- `savarhub@hubroute.local`

## Shared-Hosting PHP + SQLite Deployment

The PHP app is the simple production backend path for HubRoute. SQLite is the source of truth for users, parcels, events, idempotency keys, audit logs, and operational configuration.

1. In cPanel, set the domain or subdomain PHP version to PHP 8.1 or newer and enable `pdo_sqlite` and `sqlite3`.
2. Upload `hubroute-demo.zip` to the demo domain folder, usually `public_html/` or the subdomain document root, and extract it there with overwrite enabled.
3. Visit your demo domain. If using the single-file source directly, visit `/hubroute.php?r=login&demo=hub`.
4. First app startup creates `data/hubroute.sqlite`, `data/php-error.log`, and runtime denial files inside `data/`.
5. Confirm `/data/hubroute.sqlite` is not downloadable. If it is reachable, move `DATA_DIR` outside `public_html` in `.env`.
6. For a public demo, rotate or disable seeded credentials before using real parcel/customer data.
7. Back up `data/hubroute.sqlite` on a schedule and test restores before handling live operations.

The ZIP is intentionally demo-only: it does not need the GitHub landing page or docs. It should include denial files for `data/` and exclude `.env`, SQLite databases, logs, `.git`, and generated runtime state.

No `.env` file is required for the default demo. Copy `.env.example` to `.env` only if you need to change paths, timezone, or rate-limit/session settings.

## Demo Login

The PHP source includes a seeded hub operator demo account:

- URL: `/hubroute.php?r=login&demo=hub`
- Email: `pickuphub@hubroute.local`
- Password: `hub1234`

The demo URL prefills only the email field. The password is shown in the login helper text and must be typed manually.

If the login page loads but sign-in or public tracking fails with a startup error, recheck that `pdo_sqlite` and `sqlite3` are enabled and that `data/` is writable by PHP.

If the app still fails and `health.php` is deployed, open `/health.php`. It checks PHP version, PDO SQLite, SQLite3, data-folder writability, and an actual SQLite write.

## Local Smoke Run

If PHP is installed locally:

```bash
cp .env.example .env
mkdir -p data
php -S 127.0.0.1:8080 hubroute.php
```

Then open `http://127.0.0.1:8080/hubroute.php`.

## Cron

HubRoute does not require scheduled tasks for normal operation.

Optional weekly log cleanup on cPanel:

```cron
0 3 * * 0 /usr/local/bin/php -r "foreach (glob('/home/CPANEL_USER/public_html/data/*.log') as $f) { if (filesize($f) > 5242880) { file_put_contents($f, ''); } }"
```

Replace `CPANEL_USER` with your hosting username and adjust the path if `DATA_DIR` is outside `public_html`.

## Permissions

- `demo.php`: `644`
- `health.php`: `644`
- `index.php`: `644`
- `.htaccess`: `644`
- `.env.example`: `644`
- `.env`: `600` or the strictest permission your host supports
- `data/`: `750` or `770`
- `data/hubroute.sqlite`: created by PHP; keep writable by the PHP user only and include it in backups
