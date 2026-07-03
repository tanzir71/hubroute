import { ensureSchema, seedStarterData } from "../lib/db.js";
import { methodNotAllowed, readJson, sendJson } from "../lib/http.js";

function authorized(req, body) {
  const expected = process.env.SETUP_SECRET;
  if (!expected) return false;
  const header = req.headers.authorization || "";
  const bearer = header.startsWith("Bearer ") ? header.slice("Bearer ".length) : "";
  return bearer === expected || body.setupSecret === expected;
}

export default async function handler(req, res) {
  if (req.method !== "POST") return methodNotAllowed(res, ["POST"]);
  let body = {};
  try {
    body = await readJson(req);
  } catch {
    return sendJson(res, 400, { ok: false, error: "invalid_json" });
  }

  if (!authorized(req, body)) {
    return sendJson(res, 401, {
      ok: false,
      error: "unauthorized",
      next: "Set SETUP_SECRET in Vercel and submit the same value."
    });
  }

  try {
    await ensureSchema();
    const seed = await seedStarterData();
    return sendJson(res, 200, {
      ok: true,
      seed,
      next: "Open /api/health and /track/HR260703DHK1A2."
    });
  } catch (error) {
    return sendJson(res, 500, { ok: false, error: error.message });
  }
}
