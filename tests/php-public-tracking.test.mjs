import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");
const publicTrack = php.slice(
  php.indexOf("function pagePublicTrack"),
  php.indexOf("function pageDashboard")
);

test("public tracking uses a storefront search layout without auth nav", () => {
  assert.match(php, /if \(\$currentRoute === 'track' && !\$user\)/);
  assert.match(php, /public-topbar/);
  assert.match(php, /\.track-shell\{/);
  assert.match(php, /\.track-search input\{[^}]*font:700 20px\/1\.2 var\(--mono\)/);
  assert.match(publicTrack, /class="track-shell"/);
  assert.match(publicTrack, /class="card track-search"/);
  assert.match(publicTrack, /<button class="btn primary" type="submit">Track<\/button>/);
});

test("public tracking renders a four-step progress stepper and hub chain", () => {
  assert.match(php, /function publicTrackingStage/);
  assert.match(php, /function renderPublicProgressStepper/);
  for (const label of ["Booked", "At hub", "Out for delivery", "Delivered"]) {
    assert.match(php, new RegExp(label));
  }
  assert.match(php, /\.public-stepper\{/);
  assert.match(php, /\.public-step\.complete \.step-marker\{[^}]*background:var\(--brand\)/);
  assert.match(php, /@media\(max-width:640px\)\{[^}]*\.public-stepper/);
  assert.match(publicTrack, /renderPublicProgressStepper\(\(string\)\$p\['status'\], \$evs\)/);
  assert.match(publicTrack, /class="public-hub-chain"/);
  assert.match(publicTrack, /&rarr;/);
});

test("public tracking keeps results redacted and friendly", () => {
  assert.match(publicTrack, /class="public-code mono"/);
  assert.match(publicTrack, /timeline-list public-timeline/);
  assert.match(publicTrack, /No parcel found for that code/);
  assert.match(publicTrack, /check the number and try again/);
  assert.doesNotMatch(publicTrack, /pickup_address|dropoff_address|phone/i);
});
