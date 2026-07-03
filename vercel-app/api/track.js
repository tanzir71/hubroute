import { ensureSchema, execute } from "../lib/db.js";
import { methodNotAllowed, sendJson } from "../lib/http.js";

function cleanCode(value) {
  return String(value || "")
    .replace(/[^A-Za-z0-9_-]/g, "")
    .toUpperCase()
    .slice(0, 64);
}

export default async function handler(req, res) {
  if (req.method !== "GET") return methodNotAllowed(res, ["GET"]);
  const code = cleanCode(req.query.code);
  if (!code) {
    return sendJson(res, 400, { ok: false, error: "tracking_code_required" });
  }

  try {
    await ensureSchema();
    const result = await execute(
      `SELECT id, tracking_code, pickup_area, dropoff_area, amount_cents, status, current_hub, updated_at
       FROM parcels WHERE tracking_code = ?`,
      [code]
    );
    const parcel = result.rows[0];
    if (!parcel) {
      return sendJson(res, 404, { ok: false, error: "not_found", code });
    }

    const events = await execute(
      `SELECT event_type, hub_name, public_note, ts
       FROM events WHERE parcel_id = ?
       ORDER BY ts DESC, id DESC LIMIT 30`,
      [Number(parcel.id)]
    );

    return sendJson(res, 200, {
      ok: true,
      parcel: {
        trackingCode: parcel.tracking_code,
        status: parcel.status,
        currentHub: parcel.current_hub,
        pickupArea: parcel.pickup_area,
        dropoffArea: parcel.dropoff_area,
        paymentMode: Number(parcel.amount_cents || 0) > 0 ? "cod" : "prepaid",
        updatedAt: parcel.updated_at
      },
      events: events.rows.map((event) => ({
        type: event.event_type,
        hub: event.hub_name,
        note: event.public_note,
        at: event.ts
      }))
    });
  } catch (error) {
    return sendJson(res, 500, { ok: false, error: error.message });
  }
}
