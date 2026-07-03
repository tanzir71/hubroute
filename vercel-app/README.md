# HubRoute Vercel Artifact

This is a separate Vercel-ready fileset for HubRoute. It is not the PHP shared-hosting zip and it does not use local filesystem SQLite.

## What This Artifact Includes

- Static app pages for setup and public tracking.
- Vercel Node Functions under `api/`.
- Hosted SQLite-compatible database access through Turso/libSQL.
- `/api/health` for deployment checks.
- `/api/setup` to create schema and seed starter public-tracking data.
- `/api/track?code=...` for redacted public tracking.
- `/api/cron/maintenance` for protected retention cleanup.
- `vercel.json` with a daily cron schedule.

This is the deployable Vercel foundation. It intentionally keeps the PHP shared-hosting app intact.

## Beginner Path: Vercel Dashboard

Use this path if you can use GitHub and the Vercel website. You do not need npm on your own computer.

1. Extract `hubroute-vercel.zip`.
2. Open the extracted folder and confirm these items are at the top level: `api/`, `lib/`, `scripts/`, `index.html`, `track.html`, `setup.html`, `styles.css`, `package.json`, `package-lock.json`, `vercel.json`, `.env.example`, and `README.md`.
3. Create a new empty GitHub repository, for example `hubroute-vercel`.
4. Upload the contents of the extracted folder into the root of that GitHub repository. Do not upload the parent folder itself.
5. Do not upload `.env`, local SQLite databases, logs, backup files, the PHP shared-hosting zip, or browser walkthrough files.
6. Create a hosted SQLite-compatible database in Turso/libSQL.
7. Copy the database URL into `TURSO_DATABASE_URL`.
8. Create a database token and copy it into `TURSO_AUTH_TOKEN`.
9. Create unique 32+ character secrets for `SESSION_SECRET`, `CRON_SECRET`, and `SETUP_SECRET`.
10. In Vercel, choose **Add New -> Project**.
11. Import the GitHub repository from step 3.
12. Keep the root directory as the repository root. If you placed the files inside a subfolder, choose the folder that contains `vercel.json`.
13. If Vercel asks for a framework, choose **Other** or **No Framework**.
14. Keep advanced command fields at their defaults.
15. Add these Production environment variables in Vercel:
    - `TURSO_DATABASE_URL`
    - `TURSO_AUTH_TOKEN`
    - `SESSION_SECRET`
    - `CRON_SECRET`
    - `SETUP_SECRET`
    - `APP_TIMEZONE`
    - `MAINTENANCE_PRUNE_TERMINAL_DAYS`
    - `MAINTENANCE_PRUNE_AUDIT_DAYS`
    - `MAINTENANCE_PRUNE_IDEMPOTENCY_DAYS`
16. Click **Deploy**.
17. Open `https://YOUR-VERCEL-DOMAIN/api/health`.
18. If health reports missing environment variables, add the missing values in Vercel and redeploy.
19. Open `https://YOUR-VERCEL-DOMAIN/setup`.
20. Enter `SETUP_SECRET` to create the schema and starter public-tracking data.
21. Open `https://YOUR-VERCEL-DOMAIN/track/HR260703DHK1A2`.
22. In Vercel project settings, confirm the scheduled cron points at `/api/cron/maintenance`.
23. Before real operations, replace starter data and continue the remaining dashboard/login/admin port.

## Optional Vercel CLI Path

Use this only if you already have Node.js/npm and prefer a terminal workflow. The dashboard path above is easier for beginners.

1. Extract `hubroute-vercel.zip`.
2. Open a terminal inside the extracted folder, the same folder that contains `vercel.json`.
3. Run:

```bash
npx vercel login
npx vercel link
```

4. Add the same environment variables listed above in the Vercel dashboard.
5. Run:

```bash
npx vercel --prod
```

6. Open `/api/health`, run `/setup`, and test `/track/HR260703DHK1A2`.

## Notes

- Do not commit real `.env` files or tokens.
- Do not use a local SQLite file on Vercel production.
- Rotate or replace starter data before real operations.
- The cron route requires `Authorization: Bearer <CRON_SECRET>`.
