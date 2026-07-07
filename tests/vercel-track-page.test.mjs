import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { JSDOM } from "jsdom";

const track = readFileSync("vercel-app/track.html", "utf8");

test("vercel tracking page renders API payloads through inert DOM nodes", () => {
  assert.doesNotMatch(track, /innerHTML\s*=/);
  assert.match(track, /function renderTrackingPayload/);
  assert.match(track, /function renderProgressStepper/);
  assert.match(track, /document\.createElement/);
  assert.match(track, /\.textContent\s*=/);
  assert.match(track, /replaceChildren/);
});

test("vercel tracking page includes the public tracking design states", () => {
  for (const label of ["Booked", "At hub", "Out for delivery", "Delivered"]) {
    assert.match(track, new RegExp(label));
  }

  assert.match(track, /public-stepper/);
  assert.match(track, /public-hub-chain/);
  assert.match(track, /timeline/);
  assert.match(track, /skeleton-bar/);
  assert.match(track, /No parcel found for that code - check the number and try again\./);
});

test("vercel tracking renderer treats malicious API values as text", () => {
  const dom = new JSDOM(track, {
    runScripts: "dangerously",
    url: "https://hubroute.test/track.html",
  });
  const malicious = "<img src=x onerror=alert(1)>";
  dom.window.renderTrackingPayload({
    ok: true,
    parcel: {
      trackingCode: malicious,
      status: "in_transit",
      pickupArea: "<b>Dhaka</b>",
      dropoffArea: "Chattogram",
      currentHub: malicious,
      paymentMode: "cod",
      updatedAt: "2026-07-03 10:15:00",
    },
    events: [{
      type: "picked_up",
      hub: malicious,
      at: "2026-07-03 10:15:00",
      note: "<svg onload=alert(1)>",
    }],
  }, malicious);

  const result = dom.window.document.querySelector("#result");
  assert.equal(result.querySelectorAll("img,script,svg").length, 0);
  assert.match(result.textContent, /<img src=x onerror=alert\(1\)>/);
  assert.match(result.textContent, /<b>Dhaka<\/b>/);
  assert.match(result.textContent, /<svg onload=alert\(1\)>/);

  dom.window.showNotFound(malicious);
  assert.equal(result.querySelectorAll("img,script,svg").length, 0);
  assert.match(result.textContent, /<img src=x onerror=alert\(1\)>/);
});
