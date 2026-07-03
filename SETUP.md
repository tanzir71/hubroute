# HubRoute Setup

## Namecheap/cPanel Deployment

1. In cPanel, set the domain or subdomain PHP version to PHP 8.1 or newer and enable `pdo_sqlite` and `sqlite3`.
2. Upload `hubroute-namecheap.zip` to the target folder, usually `public_html/`, and extract it there. If you are not using the ZIP, upload `hubroute.php`, `index.html`, `README.md`, `SETUP.md`, `SECURITY.md`, `CHANGELOG.md`, `.env.example`, and `tests/security_scan.ps1`.
3. Copy `.env.example` to `.env`, edit values if needed, and keep `.env` out of Git.
4. Create or confirm `data/` next to `hubroute.php`. Prefer permissions `750` or `770`; use `775` only if your host requires it. The ZIP includes starter `data/.htaccess` and `data/index.html` denial files.
5. Visit `https://yourdomain.com/hubroute.php`. First run creates `data/hubroute.sqlite`, `data/php-error.log`, and runtime denial files inside `data/`.
6. Confirm `https://yourdomain.com/data/hubroute.sqlite` is not downloadable. If it is reachable, move `DATA_DIR` outside `public_html` in `.env`.
7. Sign in with a seeded account, immediately create replacement users/passwords, then remove or disable seeded credentials for production.

## Demo Login

The deployment includes a seeded hub operator demo account:

- URL: `https://yourdomain.com/hubroute.php?r=login&demo=hub`
- Email: `pickuphub@hubroute.local`
- Password: `hub1234`

The demo URL prefills only the email field. The password is shown in the login helper text and must be typed manually.

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

- `hubroute.php`: `644`
- `index.html` and docs: `644`
- `.env`: `600` or the strictest permission your host supports
- `data/`: `750` or `770`
- `data/hubroute.sqlite`: created by PHP; keep writable by the PHP user only
