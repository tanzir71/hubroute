# HubRoute MVP

HubRoute is a compact PHP 8 + SQLite parcel operations console for shared hosting. It supports customer pickup requests, hub assignment, agent delivery runs, public tracking, COD settlement, and route CSV export.

Landing page: `index.html`  
App entry: `hubroute.php`  
Repository: <https://github.com/tanzir71/hubroute>
Namecheap package: `hubroute-namecheap.zip`

## Security Posture

- PDO prepared statements with bound parameters.
- `htmlEscape()`/`e()` escaping for template output.
- CSRF tokens for state-changing forms.
- `password_hash()` / `password_verify()` for accounts.
- `session_regenerate_id()` after login plus idle/absolute timeouts.
- SQLite rate limiting for login and POST actions.
- CSP and standard browser security headers.
- Authorization checks for parcel, route, hub, agent, settlement, and CSV access.

See [SECURITY.md](SECURITY.md) for the full security notes and [SETUP.md](SETUP.md) for cPanel deployment.

## Demo Account

The landing page and login screen highlight the seeded hub operator demo:

- Demo URL: `hubroute.php?r=login&demo=hub`
- Email: `pickuphub@hubroute.local`
- Password: `hub1234`
- Role: North Pickup Hub operator

Use it to inspect parcel queues, route assignment, scan/event capture, and settlement workflows. Change or disable all seeded credentials before production use.

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

## Namecheap Deployment ZIP

`hubroute-namecheap.zip` is the cPanel upload bundle. It includes the PHP app, static landing page, docs, `.env.example`, the PowerShell security scan, and staged `data/.htaccess` plus `data/index.html` denial files. It excludes private/runtime state such as `.env`, SQLite databases, logs, `.git`, and design-source notes.

## Static Security Scan

On Windows PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File tests\security_scan.ps1
```

The script checks for the expected hardening hooks and common direct SQL concatenation patterns.
