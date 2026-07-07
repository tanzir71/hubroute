import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("PHP controls use tokenized button, label, and focus styling", () => {
  assert.match(php, /\.btn\{[^}]*min-height:38px/);
  assert.match(php, /\.btn\.primary\{[^}]*background:var\(--brand\)/);
  assert.match(php, /\.btn\.secondary\{[^}]*border-color:var\(--line-strong\)/);
  assert.match(php, /\.btn\.danger\{[^}]*border-color:var\(--danger\)/);
  assert.match(php, /\.btn\.danger:hover\{[^}]*background:var\(--danger\)/);
  assert.match(php, /input:focus,select:focus,textarea:focus,[^}]*outline:2px solid var\(--brand\)/);
  assert.match(php, /label\{[^}]*font-size:11\.5px[^}]*text-transform:uppercase/);
});

test("PHP flashes have distinct ok, error, and info treatments", () => {
  assert.match(php, /\.flash\.ok\{[^}]*border-left:2px solid var\(--ok\)/);
  assert.match(php, /\.flash\.err\{[^}]*border-left:2px solid var\(--danger\)/);
  assert.match(php, /\.flash\.info\{[^}]*border-left:2px solid var\(--brand\)/);
  assert.match(php, /\.flash\.info\{[^}]*background:var\(--brand-soft\)/);
});
