import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("dashboard KPI grid uses the collapsed-border design contract", () => {
  assert.match(php, /function renderKpiGrid/);
  assert.match(php, /\.kpis\{[^}]*border:1px solid var\(--line\)[^}]*gap:1px/);
  assert.match(php, /\.kpi\{[^}]*border:0/);
  assert.match(php, /\.kpi\.attention\{[^}]*border-top:2px solid var\(--brand\)/);
  assert.match(php, /\.kpi \.n\{[^}]*font:700 22px\/1 var\(--mono\)/);
  assert.match(php, /\.kpi \.l\{[^}]*font-size:10\.5px[^}]*text-transform:uppercase/);
});

test("all role landing screens render KPI grids and queue headers", () => {
  assert.ok((php.match(/renderKpiGrid\(/g) ?? []).length >= 4);
  assert.ok((php.match(/renderQueueHeader\(/g) ?? []).length >= 4);
  assert.match(php, /function renderQueueHeader/);
  assert.match(php, /class="queue-head"/);
  assert.match(php, /class="btn primary"/);
});
