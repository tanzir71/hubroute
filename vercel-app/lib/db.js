import { createClient } from "@libsql/client/web";

let dbClient = null;

export function envStatus() {
  return {
    TURSO_DATABASE_URL: Boolean(process.env.TURSO_DATABASE_URL),
    TURSO_AUTH_TOKEN: Boolean(process.env.TURSO_AUTH_TOKEN),
    SESSION_SECRET: Boolean(process.env.SESSION_SECRET),
    CRON_SECRET: Boolean(process.env.CRON_SECRET),
    SETUP_SECRET: Boolean(process.env.SETUP_SECRET)
  };
}

export function getDb() {
  if (!process.env.TURSO_DATABASE_URL || !process.env.TURSO_AUTH_TOKEN) {
    throw new Error("Missing TURSO_DATABASE_URL or TURSO_AUTH_TOKEN");
  }
  if (!dbClient) {
    dbClient = createClient({
      url: process.env.TURSO_DATABASE_URL,
      authToken: process.env.TURSO_AUTH_TOKEN
    });
  }
  return dbClient;
}

export async function execute(sql, args = []) {
  return getDb().execute({ sql, args });
}

export async function ensureSchema() {
  const db = getDb();
  const statements = [
    `CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL UNIQUE,
      role TEXT NOT NULL CHECK (role IN ('admin','hub','agent','customer')),
      active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL
    )`,
    `CREATE TABLE IF NOT EXISTS parcels (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tracking_code TEXT NOT NULL UNIQUE,
      customer_name TEXT NOT NULL,
      pickup_area TEXT NOT NULL,
      dropoff_area TEXT NOT NULL,
      amount_cents INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL CHECK (status IN ('requested','assigned','en_route','picked_up','in_warehouse','out_for_delivery','delivered','failed','returned')),
      current_hub TEXT,
      metadata_json TEXT NOT NULL DEFAULT '{}',
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    )`,
    `CREATE TABLE IF NOT EXISTS events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      parcel_id INTEGER NOT NULL,
      event_type TEXT NOT NULL,
      hub_name TEXT,
      public_note TEXT,
      ts TEXT NOT NULL,
      FOREIGN KEY (parcel_id) REFERENCES parcels(id)
    )`,
    `CREATE TABLE IF NOT EXISTS audit_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      actor_email TEXT,
      action TEXT NOT NULL,
      entity_type TEXT NOT NULL,
      entity_id INTEGER NOT NULL,
      before_json TEXT,
      after_json TEXT,
      reason TEXT,
      created_at TEXT NOT NULL
    )`,
    `CREATE TABLE IF NOT EXISTS idempotency_keys (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      actor_key TEXT NOT NULL,
      operation TEXT NOT NULL,
      idempotency_key TEXT NOT NULL,
      request_hash TEXT NOT NULL,
      status TEXT NOT NULL,
      created_at TEXT NOT NULL,
      UNIQUE(actor_key, operation, idempotency_key)
    )`,
    `CREATE TABLE IF NOT EXISTS maintenance_runs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      action TEXT NOT NULL,
      details_json TEXT NOT NULL DEFAULT '{}',
      created_at TEXT NOT NULL
    )`
  ];

  for (const sql of statements) {
    await db.execute(sql);
  }
}

export function nowIso() {
  return new Date().toISOString();
}

export function daysAgoIso(days) {
  return new Date(Date.now() - Number(days) * 24 * 60 * 60 * 1000).toISOString();
}

export async function seedStarterData() {
  await ensureSchema();
  const existing = await execute("SELECT COUNT(*) AS count FROM parcels");
  const count = Number(existing.rows[0]?.count || 0);
  if (count > 0) {
    return { inserted: false, parcels: count };
  }

  const now = nowIso();
  const parcels = [
    {
      tracking: "HR260703DHK1A2",
      customer: "Maya Boutique",
      pickup: "Banani, Dhaka",
      dropoff: "Dhanmondi, Dhaka",
      amount: 129900,
      status: "assigned",
      hub: "Dhaka North Pickup",
      note: "Pickup assigned"
    },
    {
      tracking: "HR260704UTR7M5",
      customer: "Bongo Market",
      pickup: "Mirpur, Dhaka",
      dropoff: "Uttara, Dhaka",
      amount: 0,
      status: "picked_up",
      hub: "Dhaka North Pickup",
      note: "Collected from sender"
    },
    {
      tracking: "HR260705CTG4L7",
      customer: "Port Traders",
      pickup: "Tejgaon, Dhaka",
      dropoff: "Halishahar, Chattogram",
      amount: 154000,
      status: "in_warehouse",
      hub: "Tejgaon Sortation",
      note: "Received at sortation hub"
    }
  ];

  for (const parcel of parcels) {
    const result = await execute(
      `INSERT INTO parcels (tracking_code, customer_name, pickup_area, dropoff_area, amount_cents, status, current_hub, metadata_json, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        parcel.tracking,
        parcel.customer,
        parcel.pickup,
        parcel.dropoff,
        parcel.amount,
        parcel.status,
        parcel.hub,
        JSON.stringify({ source: "vercel_starter_seed" }),
        now,
        now
      ]
    );
    const parcelId = Number(result.lastInsertRowid);
    await execute(
      "INSERT INTO events (parcel_id, event_type, hub_name, public_note, ts) VALUES (?, ?, ?, ?, ?)",
      [parcelId, "requested", parcel.hub, "Parcel request created", now]
    );
    await execute(
      "INSERT INTO events (parcel_id, event_type, hub_name, public_note, ts) VALUES (?, ?, ?, ?, ?)",
      [parcelId, parcel.status, parcel.hub, parcel.note, now]
    );
  }

  await execute(
    "INSERT INTO maintenance_runs (action, details_json, created_at) VALUES (?, ?, ?)",
    ["seed", JSON.stringify({ parcels: parcels.length }), now]
  );
  return { inserted: true, parcels: parcels.length };
}
