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
2. Upload only these runtime files to the app directory: `hubroute.php`, `index.php`, `health.php`, `.htaccess`, and `.env.example`.
3. Optional: copy `.env.example` to `.env` if you need custom paths, timezone, or rate-limit/session settings.
4. If your host allows private folders, set `DATA_DIR` in `.env` to a writable path outside `public_html`. Leave `DB_PATH` commented unless the SQLite file needs a different path from `DATA_DIR/hubroute.sqlite`.
5. Visit `/health.php` and confirm PHP version, PDO SQLite, SQLite3, data-directory writability, database-directory writability, and SQLite write all pass.
6. Visit the app domain root, or `/hubroute.php?r=login&demo=hub`, to create `hubroute.sqlite`, `php-error.log`, and runtime denial files.
7. Confirm `/data/hubroute.sqlite` is not downloadable if you kept `DATA_DIR=data`. If it is reachable, move `DATA_DIR` outside `public_html` immediately.
8. Rotate or disable seeded credentials before using real parcel/customer data.
9. Back up the SQLite database on a schedule and test restores before handling live operations.

If you package the PHP app as a ZIP yourself, include only the runtime files above and exclude `.env`, SQLite databases, logs, `.git`, Node/Vercel demo files, and generated runtime state.

## First Login

The PHP source seeds a hub operator account so you can confirm the app is running:

- URL: `/hubroute.php?r=login&demo=hub`
- Email: `pickuphub@hubroute.local`
- Password: `hub1234`

The URL prefills only the email field. The password is shown in the login helper text and must be typed manually. Rotate or disable this account before live use.

If the login page loads but sign-in or public tracking fails with a startup error, recheck that `pdo_sqlite` and `sqlite3` are enabled and that `data/` is writable by PHP.

If the app still fails and `health.php` is deployed, open `/health.php`. It checks PHP version, PDO SQLite, SQLite3, data-folder writability, and an actual SQLite write.

## Local Smoke Run

If PHP is installed locally:

```bash
mkdir -p data
php -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`. Copy `.env.example` to `.env` first only if you want to test a custom `DATA_DIR`, timezone, or rate limit.

## Cron

HubRoute does not require scheduled tasks for normal operation.

Optional weekly log cleanup on cPanel:

```cron
0 3 * * 0 /usr/local/bin/php -r "foreach (glob('/home/CPANEL_USER/public_html/data/*.log') as $f) { if (filesize($f) > 5242880) { file_put_contents($f, ''); } }"
```

Replace `CPANEL_USER` with your hosting username and adjust the path if `DATA_DIR` is outside `public_html`.

## Permissions

- `hubroute.php`: `644`
- `health.php`: `644`
- `index.php`: `644`
- `.htaccess`: `644`
- `.env.example`: `644`
- `.env`: `600` or the strictest permission your host supports
- `data/`: `750` or `770`
- `data/hubroute.sqlite`: created by PHP; keep writable by the PHP user only and include it in backups
