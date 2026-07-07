import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("PHP layout shell includes branded logo, active nav state, and operator identity", () => {
  assert.match(php, /logo-mark/);
  assert.match(php, /logo-bg/);
  assert.match(php, /\$currentRoute\s*=/);
  assert.match(php, /\.navlink\.active/);
  assert.match(php, /aria-current="page"/);
  assert.match(php, /strtoupper\(roleLabel/);
});

test("PHP layout footer uses the product positioning copy", () => {
  assert.match(php, /self-hosted courier &amp; parcel operations console/);
  assert.match(php, /Public Tracking/);
});
