import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");
const start = php.indexOf("function pageParcelDetail");
const end = php.indexOf("function actionAgentStep");
const detail = php.slice(start, end);

test("parcel detail uses a timeline-led hero layout", () => {
  assert.match(php, /\.parcel-layout\{[^}]*display:grid/);
  assert.match(php, /@media\(min-width:980px\)\{\.parcel-layout\{grid-template-columns:minmax\(0,2fr\) minmax\(280px,1fr\)/);
  assert.match(php, /\.timeline-line\{[^}]*border-left:2px solid var\(--line-strong\)/);
  assert.match(php, /\.timeline-item\.latest\{background:var\(--brand-soft\)/);
  assert.match(php, /\.timeline-time\{[^}]*font:11px\/1\.3 var\(--mono\)[^}]*text-align:right/);
  assert.match(detail, /class="parcel-layout"/);
  assert.match(detail, /timeline-item.*latest/);
});

test("parcel detail side facts include copyable tracking and COD state", () => {
  assert.match(detail, /parcel-facts/);
  assert.match(detail, /navigator\.clipboard/);
  assert.match(detail, /Copy/);
  assert.match(detail, /font-size:18px/);
  assert.match(detail, /COD amount/);
  assert.match(detail, /outstanding/);
  assert.doesNotMatch(detail, /<table class="table"><thead><tr><th>Time<\/th><th>Event/);
});
