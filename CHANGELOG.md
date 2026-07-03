# Changelog

## 2026-07-03

- Reworked the GitHub landing page with a tighter TinyProctorJS-inspired layout, smaller fixed type scale, demo CTA, setup block, and security checklist.
- Added a visible seeded hub-operator demo path at `hubroute.php?r=login&demo=hub` that prefills the email only.
- Documented the Namecheap/cPanel deployment ZIP and demo credential rotation expectations.

## 2026-05-14

- Hardened PHP runtime with CSP/security headers, generic error output, server-side logging, `.env` configuration, and data-directory denial files.
- Added CSRF-protected logout, session timeout enforcement, SQLite rate limiting, input validation, and route/status/event whitelists.
- Tightened authorization around parcel scan/event access, hub assignment, agent status updates, settlements, and CSV exports.
- Added RoughCut-inspired landing page plus setup, security, and verification documentation.
