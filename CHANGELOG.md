# Changelog

## 2026-07-03

- Reworked the GitHub landing page with a tighter TinyProctorJS-inspired layout, smaller fixed type scale, demo CTA, setup block, and security checklist.
- Added a visible seeded hub-operator demo path at `hubroute.php?r=login&demo=hub` that prefills the email only.
- Made the login GET route render before SQLite startup so shared-hosting runtime issues do not blank the demo login page.
- Split hosting responsibilities: GitHub Pages serves the landing page, while Namecheap receives only the PHP demo-runtime ZIP.
- Added a demo front door (`index.php`), packaged `demo.php`, and root `.htaccess` so the cPanel demo works from the domain root after extraction.
- Guarded disabled env/config functions and added `health.php` for shared-hosting diagnostics.

## 2026-05-14

- Hardened PHP runtime with CSP/security headers, generic error output, server-side logging, `.env` configuration, and data-directory denial files.
- Added CSRF-protected logout, session timeout enforcement, SQLite rate limiting, input validation, and route/status/event whitelists.
- Tightened authorization around parcel scan/event access, hub assignment, agent status updates, settlements, and CSV exports.
- Added RoughCut-inspired landing page plus setup, security, and verification documentation.
