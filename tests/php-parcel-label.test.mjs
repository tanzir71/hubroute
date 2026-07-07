import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("parcel detail exposes a print label route and action", () => {
  assert.match(php, /function pageParcelLabel/);
  assert.match(php, /if \(\$route === 'parcel_label'\)/);
  assert.match(php, /\?r=parcel_label&id=/);
  assert.match(php, /Print label/);
});

test("parcel label is print-ready and keeps zero barcode dependencies", () => {
  assert.match(php, /canAccessParcel\(\$u, \$p\)/);
  assert.match(php, /\.label-sheet\{/);
  assert.match(php, /@page\{size:A6/);
  assert.match(php, /@media print\{/);
  assert.match(php, /window\.print\(\)/);
  assert.match(php, /scan = type the code/);
  assert.match(php, /formatMoney\(\(int\)\$p\['amount_cents'\]\)/);
  assert.doesNotMatch(php, /JsBarcode|barcode library|<canvas/i);
});
