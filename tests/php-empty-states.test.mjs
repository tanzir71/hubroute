import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("PHP app exposes the shared designed empty-state helper", () => {
  assert.match(php, /function renderEmptyState/);
  assert.match(php, /\.empty-state\{[^}]*border:1px dashed var\(--line-strong\)[^}]*text-align:center/);
  assert.match(php, /\.empty-state \.btn\{[^}]*margin-top:12px/);
});

test("major PHP list screens use designed empty states", () => {
  assert.ok((php.match(/renderEmptyState\(/g) ?? []).length >= 12);
  for (const title of [
    "No parcels yet",
    "No routes yet",
    "No events yet",
    "No settlements due",
    "No users yet",
    "No hubs yet",
    "No agents yet",
    "No customers yet",
    "No audit entries yet",
  ]) {
    assert.match(php, new RegExp(title));
  }
});
