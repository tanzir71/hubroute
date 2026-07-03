#!/usr/bin/env node

import { readFile } from "node:fs/promises";
import assert from "node:assert/strict";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { JSDOM } from "jsdom";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const [htmlSource, tokensCss] = await Promise.all([
  readFile(path.join(root, "public", "index.html"), "utf8"),
  readFile(path.join(root, "public", "styles", "tokens.css"), "utf8")
]);
const html = htmlSource.replace(
  '<link rel="stylesheet" href="styles/tokens.css">',
  `<style>${tokensCss}</style>`
);

const STORAGE_KEY = "hubroute-demo-state-v5";

function createDom(seedState = null){
  return new JSDOM(html, {
    url: "https://hubroute.test/",
    runScripts: "dangerously",
    resources: "usable",
    pretendToBeVisual: true,
    beforeParse(window){
      window.confirm = () => true;
      if (seedState) window.localStorage.setItem(STORAGE_KEY, JSON.stringify(seedState));
    }
  });
}

const waitFrame = (window) => new Promise((resolve) => window.requestAnimationFrame(resolve));

let dom = createDom();
let { document } = dom.window;

await waitFrame(dom.window);

assert.equal(document.querySelectorAll("#accountCards .account-card").length, 4, "renders seeded account cards");
assert.match(document.body.textContent, /Bangladesh hubs and move parcels through custody/, "renders login copy");

document.querySelector("#signIn").click();
await waitFrame(dom.window);
assert.equal(document.querySelector("#login").classList.contains("hidden"), true, "hides login after sign-in");
assert.equal(document.querySelector("#app").classList.contains("hidden"), false, "shows app after sign-in");
assert.match(document.querySelector("#view").textContent, /Hub-and-spoke custody/, "renders dashboard content");

document.querySelector('[data-action="new-parcel"]').click();
await waitFrame(dom.window);
assert.match(document.querySelector("#modalRoot").textContent, /Auto-generated on save/, "new parcel form shows system-generated tracking number");
assert.equal(document.querySelector('#parcelForm input[name="code"]'), null, "new parcel form does not allow manual tracking number entry");
document.querySelector('[data-action="close-modal"]').click();
await waitFrame(dom.window);

document.querySelector('[data-view="track"]').click();
await waitFrame(dom.window);
assert.match(document.querySelector("#view").textContent, /Public tracking/, "renders public tracking view");
assert.equal(document.querySelectorAll("#view .sample-codes button").length > 0, true, "renders sample tracking codes");

dom.window.close();

dom = createDom({
  version: 5,
  currentHubId: "hub-north",
  hubs: [],
  customers: [],
  riders: [],
  routes: [],
  parcels: [],
  events: []
});
document = dom.window.document;
await waitFrame(dom.window);

document.querySelector("#signIn").click();
await waitFrame(dom.window);
assert.match(document.querySelector("#view").textContent, /No hubs configured/, "renders empty hub network state");
assert.match(document.querySelector("#view").textContent, /No parcels in this queue|No events yet/, "renders empty operations states");

document.querySelector('[data-view="scan"]').click();
await waitFrame(dom.window);
assert.match(document.querySelector("#view").textContent, /No parcels available for scan/, "renders empty scan queue state");
assert.equal(document.querySelector('#scanForm button[type="submit"]').disabled, true, "disables scan submit with no parcels");

document.querySelector('[data-view="track"]').click();
await waitFrame(dom.window);
assert.match(document.querySelector("#view").textContent, /No sample tracking codes/, "renders empty public tracking samples state");

dom.window.close();
console.log("[M0-02/M0-03/M0-05] Demo render smoke passed.");
