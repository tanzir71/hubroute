import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const css = readFileSync("vercel-app/styles.css", "utf8");
const tokens = readFileSync("styles/tokens.css", "utf8");
const home = readFileSync("vercel-app/index.html", "utf8");
const track = readFileSync("vercel-app/track.html", "utf8");
const setup = readFileSync("vercel-app/setup.html", "utf8");
const readme = readFileSync("vercel-app/README.md", "utf8");

const envNames = [
  "TURSO_DATABASE_URL",
  "TURSO_AUTH_TOKEN",
  "SESSION_SECRET",
  "CRON_SECRET",
  "SETUP_SECRET",
];

function compact(value) {
  return value.replace(/\s+/g, "");
}

test("vercel app CSS uses the shared design tokens and zero-radius shell", () => {
  for (const token of tokens.match(/--[a-z0-9-]+:[^;]+;/g) ?? []) {
    const expected = compact(token).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    assert.match(compact(css), new RegExp(expected));
  }

  for (const line of css.split(/\r?\n/).filter((row) => row.includes("border-radius"))) {
    if (line.includes(".led")) continue;
    assert.match(line, /border-radius:\s*0\b/, `unexpected radius: ${line}`);
  }
});

test("vercel homepage is a product landing, not an env-var checklist", () => {
  assert.match(home, /logo-mark/);
  assert.match(home, /Track a parcel/);
  assert.match(home, /class="btn primary" href="\/track\.html"/);
  assert.match(home, /Operator setup/);
  assert.match(home, /href="\/setup\.html"/);
  assert.match(home, /href="\/api\/health"/);
  for (const envName of envNames) {
    assert.doesNotMatch(home, new RegExp(envName));
  }
});

test("vercel setup and docs carry deployment environment details", () => {
  for (const page of [track, setup]) {
    assert.match(page, /logo-mark/);
  }
  for (const envName of envNames) {
    assert.match(setup, new RegExp(envName));
    assert.match(readme, new RegExp(envName));
  }
});
