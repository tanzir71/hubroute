# Deploy HubRoute Tracking on Vercel

- Public product site: <https://hubroute-ops.vercel.app/>
- Zero-config browser walkthrough: <https://hubroute.vercel.app/>

The walkthrough is a static localStorage sandbox. The Vercel starter documented below is a separate hosted-storage foundation and requires Turso plus secrets; it is not the PHP shared-hosting production bundle.

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Ftanzir71%2Fhubroute%2Ftree%2Fmain%2Fvercel-app&env=TURSO_DATABASE_URL,TURSO_AUTH_TOKEN,SESSION_SECRET,CRON_SECRET,SETUP_SECRET&project-name=hubroute-tracking&repository-name=hubroute-tracking)

This guide deploys the Vercel starter in `vercel-app/`: public tracking, setup, health, and maintenance cron plumbing backed by Turso/libSQL.

No local terminal is required for the happy path.

## What You Need

- A GitHub account.
- A Vercel account.
- A free Turso account.
- About 10 minutes.

## 1. Create the Turso Database

1. Open `https://turso.tech/` and click **Sign Up** or **Log In**.
2. After the dashboard opens, click **Create database**.
3. In **Database name**, enter `hubroute`.
4. Keep the free/default group unless you already know your preferred group.
5. Pick the region closest to your users.
6. Click **Create database**.
7. Open the new database page and find the connection value labeled **URL** or **Database URL**.
8. Click **Copy URL** and save it as `TURSO_DATABASE_URL`.
9. Open the database token area, usually labeled **Tokens** or **Auth tokens**.
10. Click **Create Token**.
11. Choose read/write or full-access permissions for this HubRoute app.
12. Click **Create Token**, then copy the generated token immediately and save it as `TURSO_AUTH_TOKEN`.

## 2. Put the Vercel App in GitHub

Fast path: click the **Deploy with Vercel** button at the top of this file. It sends Vercel to the `vercel-app/` folder in this repository and asks for the five required environment variables.

Manual path, still no terminal:

1. Open `https://github.com/new`.
2. Set **Repository name** to `hubroute-vercel` or `hubroute-tracking`.
3. Choose **Private** unless you intentionally want the starter public.
4. Click **Create repository**.
5. On the empty repository page, click the **uploading an existing file** link. If you do not see it, click **Add file** then **Upload files**.
6. Open this project on your computer and open the `vercel-app/` folder.
7. Drag the contents of `vercel-app/` into the GitHub upload box. Upload the contents, not the parent folder.
8. Confirm the top level in GitHub includes `api/`, `lib/`, `scripts/`, `index.html`, `track.html`, `setup.html`, `styles.css`, `package.json`, `package-lock.json`, `vercel.json`, `.env.example`, and `README.md`.
9. Click **Commit changes**.

## 3. Import the Project in Vercel

1. Open `https://vercel.com/dashboard`.
2. Use the team switcher if needed so the correct personal account or team is selected.
3. Click **Add New...**.
4. Click **Project**.
5. Find the GitHub repository you created and click **Import**.
6. On **Configure Project**, set **Framework Preset** to **Other** if Vercel asks.
7. If you uploaded only the contents of `vercel-app/`, keep **Root Directory** as the repository root.
8. If you imported the whole HubRoute repository instead, click **Edit** beside **Root Directory**, choose `vercel-app`, and click **Continue**.
9. Leave build and output command fields at their defaults.

## 4. Add Environment Variables

In the **Environment Variables** area, add these five keys exactly:

| Key | Value |
| --- | --- |
| `TURSO_DATABASE_URL` | Paste the Turso URL from **Copy URL**. |
| `TURSO_AUTH_TOKEN` | Paste the Turso token from **Create Token**. |
| `SESSION_SECRET` | Use a random secret. |
| `CRON_SECRET` | Use a different random secret. |
| `SETUP_SECRET` | Use a different random secret that you can paste into `/setup`. |

For the three secrets, use a password manager or mash the keyboard 40+ chars. If you already use a terminal, `openssl rand -hex 32` is also fine, but it is optional.

Click **Add** or **Save** after each variable, depending on the Vercel screen you see.

## 5. Deploy and Verify

1. Click **Deploy**.
2. Wait for the deployment to finish and click **Continue to Dashboard**.
3. Open `https://YOUR-VERCEL-DOMAIN/api/health`.
4. Confirm the response says the environment is configured.
5. Open `https://YOUR-VERCEL-DOMAIN/setup`.
6. Paste your `SETUP_SECRET`.
7. Click the setup button to create tables and starter tracking data.
8. Open `https://YOUR-VERCEL-DOMAIN/track/HR260703DHK1A2`.
9. Confirm the public tracking page shows a stepper, hub path, and timeline.

## Troubleshooting

| Problem | Likely cause | Fix |
| --- | --- | --- |
| `/api/health` health returns 500 | One or more environment variables are missing or misspelled. | Open Vercel project **Settings** -> **Environment Variables**, check all five keys, then redeploy. |
| `/setup` setup 403 | The value pasted into the setup form does not match `SETUP_SECRET`. | Copy `SETUP_SECRET` from your password manager or Vercel settings, paste it again, and retry. |
| Blank tracking or `not_found` for `HR260703DHK1A2` | Starter seed has not run yet. | Open `/setup`, enter `SETUP_SECRET`, run seed, then reload `/track/HR260703DHK1A2`. |
| Vercel cannot find the app | The repository root points at the wrong folder. | In Vercel **Settings** -> **Build and Deployment**, set **Root Directory** to the folder that contains `vercel.json`. |
| Turso connection errors | URL/token pair does not belong to the same database or token is expired. | Create a fresh database token in Turso, update `TURSO_AUTH_TOKEN` in Vercel, then redeploy. |

## After It Works

- Keep `TURSO_AUTH_TOKEN`, `SESSION_SECRET`, `CRON_SECRET`, and `SETUP_SECRET` private.
- Rotate `SETUP_SECRET` after setup if multiple people saw it.
- Replace starter data before real operations.
- Confirm Vercel cron is listed for `/api/cron/maintenance`.
