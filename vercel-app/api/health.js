import { ensureSchema, envStatus, execute } from "../lib/db.js";
import { sendJson } from "../lib/http.js";

export default async function handler(req, res) {
  if (req.method !== "GET") {
    return sendJson(res, 405, { ok: false, error: "method_not_allowed" });
  }

  const env = envStatus();
  const missing = Object.entries(env)
    .filter(([, present]) => !present)
    .map(([key]) => key);

  if (missing.includes("TURSO_DATABASE_URL") || missing.includes("TURSO_AUTH_TOKEN")) {
    return sendJson(res, 503, {
      ok: false,
      service: "hubroute-vercel",
      env,
      missing,
      next: "Set Vercel environment variables, then redeploy."
    });
  }

  try {
    await ensureSchema();
    const parcels = await execute("SELECT COUNT(*) AS count FROM parcels");
    const events = await execute("SELECT COUNT(*) AS count FROM events");
    return sendJson(res, 200, {
      ok: true,
      service: "hubroute-vercel",
      env,
      counts: {
        parcels: Number(parcels.rows[0]?.count || 0),
        events: Number(events.rows[0]?.count || 0)
      }
    });
  } catch (error) {
    return sendJson(res, 500, {
      ok: false,
      service: "hubroute-vercel",
      env,
      error: error.message
    });
  }
}
