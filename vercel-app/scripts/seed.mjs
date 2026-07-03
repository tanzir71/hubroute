import { readFileSync, existsSync } from "node:fs";
import { resolve } from "node:path";
import { seedStarterData } from "../lib/db.js";

function loadEnvFile(path) {
  if (!existsSync(path)) return;
  const lines = readFileSync(path, "utf8").split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#") || !trimmed.includes("=")) continue;
    const [key, ...rest] = trimmed.split("=");
    if (!process.env[key]) {
      process.env[key] = rest.join("=").replace(/^['"]|['"]$/g, "");
    }
  }
}

loadEnvFile(resolve(process.cwd(), ".env.local"));

const result = await seedStarterData();
console.log(JSON.stringify({ ok: true, result }, null, 2));
