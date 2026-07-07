import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const landing = readFileSync("index.html", "utf8");
const docs = readFileSync("docs.html", "utf8");
const demo = readFileSync("src/demo/index.html", "utf8");
const generatedDemo = readFileSync("public/index.html", "utf8");
const php = readFileSync("hubroute.php", "utf8");
const vercelCss = readFileSync("vercel-app/styles.css", "utf8");
const vercelHome = readFileSync("vercel-app/index.html", "utf8");
const vercelTrack = readFileSync("vercel-app/track.html", "utf8");
const vercelSetup = readFileSync("vercel-app/setup.html", "utf8");

test("all primary HTML documents declare language, viewport, and accessible nav labels", () => {
  for (const html of [landing, docs, demo, generatedDemo, vercelHome, vercelTrack, vercelSetup]) {
    assert.match(html, /<html lang="en">/);
    assert.match(html, /<meta name="viewport" content="width=device-width, initial-scale=1">/);
  }
  assert.match(landing, /<nav class="nav" aria-label="Main navigation">/);
  assert.match(docs, /<nav class="nav" aria-label="Main navigation">/);
  assert.match(demo, /aria-label="Primary navigation"/);
});

test("interactive controls have explicit visible focus coverage", () => {
  assert.match(landing, /a:focus-visible,\.btn:focus-visible/);
  assert.match(docs, /a:focus-visible,\.btn:focus-visible/);
  assert.match(demo, /a:focus-visible,\.btn:focus-visible,\.text-action:focus-visible/);
  assert.match(generatedDemo, /a:focus-visible,\.btn:focus-visible,\.text-action:focus-visible/);
  assert.match(php, /a:focus-visible,input:focus,select:focus,textarea:focus,\.btn:focus-visible,\.navlink:focus-visible/);
  assert.match(vercelCss, /a:focus-visible, input:focus, select:focus, textarea:focus, \.btn:focus-visible/);
});

test("PHP operational forms label filter and assignment controls", () => {
  assert.match(php, /<label class="field-inline">Search parcels<input name="q"/);
  assert.match(php, /<label class="field-inline">Filter status<select name="status"/);
  assert.match(php, /<label class="assign-field">Route assignment<select name="route_id"/);
  assert.match(php, /<label class="assign-field">Agent assignment<select name="agent_id"/);
  assert.match(php, /<label class="field-inline">Scan tracking code<input name="code"/);
});

test("status chips and decorative SVGs expose text while hiding decoration", () => {
  assert.match(php, /<span class="led" aria-hidden="true"><\/span><span>' \. e\(\$label\) \. '<\/span>/);
  assert.match(php, /focusable="false" aria-hidden="true"/);
  assert.match(vercelHome, /focusable="false" aria-hidden="true"/);
  assert.match(vercelTrack, /focusable="false" aria-hidden="true"/);
  assert.match(vercelSetup, /focusable="false" aria-hidden="true"/);
});
