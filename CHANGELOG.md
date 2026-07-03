# Changelog

## 2026-07-04

- Added CLI-only SQLite maintenance for automated backups, retention cleanup, and cron-friendly production pruning.
- Added admin user access control for creating production admins, resetting passwords, and disabling seeded or unused accounts.
- Added `npm run package:php` for a fresh shared-hosting zip and rewrote setup docs around zip extraction, one-command deploys, and LLM-assisted deployment.
- Added `npm run deploy:shared` as the user production deployment entry point and moved Vercel demo deployment behind maintainer-only scripts.
- Documented why PHP + SQLite is the preferred first production target for older shared-host infrastructure, emerging-market deployments, and low startup cost.
- Documented shared-hosting, VPS, and Vercel-native production paths while marking the static Vercel demo as maintainer-only.
- Added a GitHub Pages Jekyll config so Pages publishes only the static landing/docs assets instead of the PHP app, Node source, tests, zips, and runtime files.
- Fixed the PHP + SQLite handoff path by aligning `index.php`, `health.php`, `.env.example`, setup docs, and seeded-account docs with `hubroute.php`.
- Resolved the backend direction to simple PHP 8 + SQLite and updated the build plan/M1 contract accordingly.
- Added SQLite-backed idempotency and audit tables to the PHP backend, idempotency keys on mutating forms, and an admin audit-log view.
- Added provider-neutral audit-log rules and validation tests for privileged `[M1-06]` mutations.
- Added provider-neutral idempotency rules and duplicate-request tests for the `[M1]` API contract.
- Added provider-neutral authorization matrix rules and allow/deny tests for the `[M1-03]` RBAC contract.
- Added provider-neutral status-transition rules and unit tests for the `[M1-05]` parcel event contract.
- Added provider-neutral `[M1]` backend contract notes covering entities, APIs, event transitions, public redaction, idempotency, and RBAC test scope.
- Added `[M0-04]` landing/docs copy pass with courier-operator positioning, a clearer workflow, and screenshot-style console sections.
- Added `[M0-05]` structured console empty/error/loading panels plus smoke coverage for an empty valid demo dataset.
- Added `[M0-03]` shared `styles/tokens.css` imported by landing, docs, and the generated console demo.
- Added `[M0-02]` ES-module demo source under `src/demo/` with a bundled single-file `public/index.html` build output.
- Added `[M0-01]` demo sync scripts and CI so `vercel-demo/index.html` stays mirrored from generated `public/index.html`.

## 2026-07-03

- Added `docs.html` for GitHub Pages with demo accounts, hub-and-spoke workflow, deployment notes, and security posture.
- Tightened the GitHub Pages landing/docs typography and replaced README/SETUP/SECURITY landing links with navigable `docs.html` sections.
- Expanded the Vercel browser demo with multi-hub account switching, explicit parcel send/receive handoffs, custody-flow filters, searchable dropdowns, and a favicon.
- Polished the Vercel demo layout by flattening nested metric/choice panels and rebuilding public tracking around a tracking-number lookup for senders and receivers.
- Reworked the GitHub landing page with a tighter TinyProctorJS-inspired layout, smaller fixed type scale, demo CTA, setup block, and security checklist.
- Added a visible seeded hub-operator demo path at `hubroute.php?r=login&demo=hub` that prefills the email only.
- Made the login GET route render before SQLite startup so shared-hosting runtime issues do not blank the demo login page.
- Split hosting responsibilities: GitHub Pages serves the landing page and docs, while Vercel hosts the interactive browser demo.
- Added the initial cPanel front door, health check, and root `.htaccess` so the PHP app can work from the domain root.
- Guarded disabled env/config functions and added `health.php` for shared-hosting diagnostics.

## 2026-05-14

- Hardened PHP runtime with CSP/security headers, generic error output, server-side logging, `.env` configuration, and data-directory denial files.
- Added CSRF-protected logout, session timeout enforcement, SQLite rate limiting, input validation, and route/status/event whitelists.
- Tightened authorization around parcel scan/event access, hub assignment, agent status updates, settlements, and CSV exports.
- Added RoughCut-inspired landing page plus setup, security, and verification documentation.
