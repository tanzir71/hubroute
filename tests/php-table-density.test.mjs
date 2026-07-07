import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("PHP tables use dense headers, hover states, compact actions, and mono values", () => {
  assert.match(php, /function tableCell/);
  assert.match(php, /\.mono\{[^}]*font-family:var\(--mono\)/);
  assert.match(php, /\.table th\{[^}]*font-size:10\.5px[^}]*text-transform:uppercase[^}]*letter-spacing:\.04em/);
  assert.match(php, /\.table tbody tr:hover\{background:var\(--panel-2\)/);
  assert.match(php, /\.table \.btn\{[^}]*min-height:28px/);
});

test("PHP tables stack into labeled rows on mobile", () => {
  assert.match(php, /@media\(max-width:720px\)\{[^}]*\.table thead\{display:none\}/);
  assert.match(php, /\.table td::before\{[^}]*content:attr\(data-label\)[^}]*font-size:10\.5px/);
  assert.ok((php.match(/tableCell\(/g) ?? []).length >= 50);
  assert.ok((php.match(/data-label=/g) ?? []).length >= 1);
});
