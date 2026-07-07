import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";

const deployPath = "DEPLOY_VERCEL.md";
const deploy = existsSync(deployPath) ? readFileSync(deployPath, "utf8") : "";
const readme = readFileSync("README.md", "utf8");
const vercelReadme = readFileSync("vercel-app/README.md", "utf8");
const vercelJson = readFileSync("vercel-app/vercel.json", "utf8");

const requiredEnv = [
  "TURSO_DATABASE_URL",
  "TURSO_AUTH_TOKEN",
  "SESSION_SECRET",
  "CRON_SECRET",
  "SETUP_SECRET",
];

test("DEPLOY_VERCEL guide exists with a complete deploy button URL", () => {
  assert.equal(existsSync(deployPath), true);
  assert.match(deploy, /\[!\[Deploy with Vercel\]\(https:\/\/vercel\.com\/button\)\]\(https:\/\/vercel\.com\/new\/clone\?/);
  assert.match(deploy, /repository-url=https%3A%2F%2Fgithub\.com%2Ftanzir71%2Fhubroute%2Ftree%2Fmain%2Fvercel-app/);
  assert.match(deploy, /env=TURSO_DATABASE_URL,TURSO_AUTH_TOKEN,SESSION_SECRET,CRON_SECRET,SETUP_SECRET/);
  for (const envName of requiredEnv) {
    assert.match(deploy, new RegExp(envName));
  }
});

test("DEPLOY_VERCEL happy path is dashboard-only and beginner explicit", () => {
  assert.match(deploy, /No local terminal is required for the happy path\./);
  assert.match(deploy, /Create database/);
  assert.match(deploy, /Copy URL/);
  assert.match(deploy, /Create Token/);
  assert.match(deploy, /uploading an existing file/);
  assert.match(deploy, /Add New/);
  assert.match(deploy, /Project/);
  assert.match(deploy, /mash the keyboard 40\+ chars/);
  assert.match(deploy, /\/api\/health/);
  assert.match(deploy, /\/setup/);
  assert.match(deploy, /HR260703DHK1A2/);
  assert.doesNotMatch(deploy, /```(?:bash|sh|powershell|shell|console|terminal)/i);
  assert.doesNotMatch(deploy, /\bnpx\s+vercel\b/);
  assert.doesNotMatch(deploy, /\bgit\s+(clone|push|add|commit)\b/);
});

test("DEPLOY_VERCEL includes the required troubleshooting table", () => {
  assert.match(deploy, /\| Problem \| Likely cause \| Fix \|/);
  assert.match(deploy, /health returns 500/i);
  assert.match(deploy, /setup 403/i);
  assert.match(deploy, /blank tracking/i);
});

test("READMEs point Vercel beginners to the standalone guide", () => {
  assert.match(readme, /\[DEPLOY_VERCEL\.md\]\(DEPLOY_VERCEL\.md\)/);
  assert.doesNotMatch(readme, /Beginner Vercel path:\s*\n\s*1\./);
  assert.match(vercelReadme, /DEPLOY_VERCEL\.md/);
});

test("vercel app routes support clean setup and tracking URLs", () => {
  const config = JSON.parse(vercelJson);
  assert.equal(config.cleanUrls, true);
  assert.ok(config.rewrites.some((rewrite) => rewrite.source === "/setup" && rewrite.destination === "/setup.html"));
  assert.ok(config.rewrites.some((rewrite) => rewrite.source === "/track" && rewrite.destination === "/track.html"));
  assert.ok(config.rewrites.some((rewrite) => rewrite.source === "/track/:code" && rewrite.destination === "/track.html?code=:code"));
});
