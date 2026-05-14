# HubRoute Security

## Fixes Applied

- SQL access uses PDO with exceptions and prepared statements. Dynamic status filters are whitelisted before the query is assembled.
- Output reflected into HTML goes through `htmlEscape()` via the existing `e()` template helper.
- State-changing forms use CSRF tokens, including logout.
- Passwords use `password_hash()` and `password_verify()`, and login regenerates the session ID.
- Idle and absolute session timeouts are enforced with configurable values.
- Login and POST actions are rate limited per IP/account using SQLite.
- Parcel, route, agent, and settlement access now enforce ownership checks before reads or writes.
- Hub assignment validates that selected routes and agents belong to the current hub.
- Security headers include CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and a restrictive `Permissions-Policy`.
- Runtime errors are logged to `data/php-error.log`; clients receive generic startup/action errors.
- `data/` gets `.htaccess` and `index.html` denial files at runtime. Prefer moving `DATA_DIR` outside the web root.
- There are no file upload endpoints in this app. If uploads are added later, validate MIME/type/size, reject dangerous extensions, sanitize names, and store outside web root.

## Rotate Keys and Credentials

1. Copy `.env.example` to `.env` and keep `.env` private.
2. Change all seeded account passwords after first deployment.
3. If `.env` is exposed, replace it, rotate all account passwords, and move `DATA_DIR` outside `public_html`.
4. If the SQLite database is exposed, treat all parcel/customer data as compromised and rotate user passwords.

## Logging

- Default: `ENABLE_EXTRA_LOGGING=false`.
- Temporary troubleshooting: set `ENABLE_EXTRA_LOGGING=true`, reproduce the issue, download `data/php-error.log`, then set it back to `false`.
- Do not show PHP errors to users in production.

## Production Hardening

- Enforce HTTPS/TLS at the host or CDN.
- Move from SQLite to Postgres or MySQL for concurrent production traffic.
- Put automated backups around the database and test restores.
- Add WAF/CDN rules for `/hubroute.php` login and POST endpoints.
- Move `DATA_DIR` and `.env` outside the document root whenever the host supports it.
