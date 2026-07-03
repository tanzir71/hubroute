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

## Deploy In The Vercel Dashboard

1. Create a Turso/libSQL database.
2. Create a database token.
3. Create a Vercel project from this folder.
4. Add these Vercel environment variables:
   - `TURSO_DATABASE_URL`
   - `TURSO_AUTH_TOKEN`
   - `SESSION_SECRET`
   - `CRON_SECRET`
   - `SETUP_SECRET`
   - `APP_TIMEZONE`
5. Deploy the project.
6. Open `/api/health`.
7. Open `/setup` and enter `SETUP_SECRET`.
8. Open `/track/HR260703DHK1A2`.

## Deploy With Vercel CLI

```bash
npm install
npx vercel deploy --prod
```

After deploy, configure the environment variables in the Vercel dashboard or with `vercel env add`, redeploy, then run `/setup`.

## Notes

- Do not commit real `.env` files or tokens.
- Do not use a local SQLite file on Vercel production.
- Rotate or replace starter data before real operations.
- The cron route requires `Authorization: Bearer <CRON_SECRET>`.
