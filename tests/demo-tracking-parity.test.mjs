import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const main = readFileSync("src/demo/main.js", "utf8");
const html = readFileSync("src/demo/index.html", "utf8");
const generated = readFileSync("public/index.html", "utf8");
const publicParcel = main.slice(
  main.indexOf("function renderPublicParcel"),
  main.indexOf("function renderTracking")
);

test("demo public tracking mirrors the PHP four-step tracker", () => {
  assert.match(main, /function publicTrackingStage/);
  assert.match(main, /function renderPublicProgressStepper/);
  for (const label of ["Booked", "At hub", "Out for delivery", "Delivered"]) {
    assert.match(main, new RegExp(label));
  }
  assert.match(publicParcel, /class="public-code mono"/);
  assert.match(publicParcel, /renderPublicProgressStepper\(parcel\)/);
  assert.match(publicParcel, /class="public-hub-chain"/);
  assert.match(publicParcel, /&rarr;/);
  assert.match(publicParcel, /renderPublicTimeline\(parcel\.id\)/);
  assert.doesNotMatch(publicParcel, /pickupAddress|dropoffAddress|customerName|amountCents/);
});

test("demo tracking CSS has the C-01 stepper and hub path classes", () => {
  assert.match(html, /\.public-stepper\{/);
  assert.match(html, /\.public-step\.complete \.step-marker\{[^}]*background:var\(--brand\)/);
  assert.match(html, /\.public-hub-chain\{/);
  assert.match(html, /@media\(max-width:640px\)\{[^}]*\.public-stepper/);
});

test("generated demo output is synchronized with tracking parity", () => {
  assert.match(generated, /public-stepper/);
  assert.match(generated, /renderPublicProgressStepper/);
  assert.match(generated, /public-hub-chain/);
  assert.match(generated, /No parcel found for that code/);
});
