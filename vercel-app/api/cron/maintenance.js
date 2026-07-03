import { daysAgoIso, ensureSchema, execute, nowIso } from "../../lib/db.js";
import { methodNotAllowed, sendJson } from "../../lib/http.js";

function isAuthorized(req) {
  const expected = process.env.CRON_SECRET;
  if (!expected) return false;
  return req.headers.authorization === `Bearer ${expected}`;
}

async function pruneTerminalParcels(days) {
  const cutoff = daysAgoIso(days);
  const result = await execute(
    "SELECT id FROM parcels WHERE status IN ('delivered','failed','returned') AND updated_at < ?",
    [cutoff]
  );
  const ids = result.rows.map((row) => Number(row.id));
  for (const id of ids) {
    await execute("DELETE FROM events WHERE parcel_id = ?", [id]);
    await execute("DELETE FROM parcels WHERE id = ?", [id]);
  }
  return ids.length;
}

export default async function handler(req, res) {
  if (req.method !== "GET") return methodNotAllowed(res, ["GET"]);
  if (!isAuthorized(req)) {
    return sendJson(res, 401, { ok: false, error: "unauthorized" });
  }

  try {
    await ensureSchema();
    const terminalDays = Number(process.env.MAINTENANCE_PRUNE_TERMINAL_DAYS || 180);
    const auditDays = Number(process.env.MAINTENANCE_PRUNE_AUDIT_DAYS || 365);
    const idempotencyDays = Number(process.env.MAINTENANCE_PRUNE_IDEMPOTENCY_DAYS || 14);

    const terminalParcels = await pruneTerminalParcels(terminalDays);
    const audit = await execute("DELETE FROM audit_log WHERE created_at < ?", [daysAgoIso(auditDays)]);
    const idempotency = await execute("DELETE FROM idempotency_keys WHERE created_at < ?", [daysAgoIso(idempotencyDays)]);
    const details = {
      terminalParcels,
      auditRows: Number(audit.rowsAffected || 0),
      idempotencyRows: Number(idempotency.rowsAffected || 0)
    };
    await execute(
      "INSERT INTO maintenance_runs (action, details_json, created_at) VALUES (?, ?, ?)",
      ["cron_maintenance", JSON.stringify(details), nowIso()]
    );
    return sendJson(res, 200, { ok: true, details });
  } catch (error) {
    return sendJson(res, 500, { ok: false, error: error.message });
  }
}
