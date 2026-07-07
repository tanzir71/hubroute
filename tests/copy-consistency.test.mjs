import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const TAGLINE = "self-hosted courier & parcel operations console";
const DATE_PATTERN = /\b\d{4}-\d{2}-\d{2} \d{2}:\d{2}\b/;

const read = (path) => readFileSync(path, "utf8");
const normalize = (value) => value.replaceAll("&amp;", "&").replaceAll("&mdash;", "—");

const sources = {
  readme: read("README.md"),
  landing: read("index.html"),
  docs: read("docs.html"),
  php: read("hubroute.php"),
  demoHtml: read("src/demo/index.html"),
  demoMain: read("src/demo/main.js"),
  demoComponents: read("src/demo/components.js"),
  publicDemo: read("public/index.html"),
  vercelDemo: read("vercel-demo/index.html"),
  vercelHome: read("vercel-app/index.html"),
  vercelTrack: read("vercel-app/track.html"),
  vercelSetup: read("vercel-app/setup.html"),
  plan: read("CODEX_REFINEMENT_LOOP.md"),
};

const demoHelpers = await import(
  `data:text/javascript;base64,${Buffer.from(sources.demoComponents).toString("base64")}`
);

test("public copy uses the canonical product name and tagline", () => {
  const visibleCopy = Object.values(sources).map(normalize).join("\n");
  assert.doesNotMatch(visibleCopy, /Hubroute|Hub-Route|HubRoute MVP/);

  for (const [name, source] of Object.entries({
    landing: sources.landing,
    docs: sources.docs,
    php: sources.php,
    demoHtml: sources.demoHtml,
    publicDemo: sources.publicDemo,
    vercelDemo: sources.vercelDemo,
    vercelHome: sources.vercelHome,
    vercelTrack: sources.vercelTrack,
    vercelSetup: sources.vercelSetup,
  })) {
    assert.ok(normalize(source).includes(TAGLINE), `${name} includes canonical tagline`);
  }
});

test("status labels match the shared public vocabulary", () => {
  assert.equal(demoHelpers.statusLabel("requested"), "requested");
  assert.equal(demoHelpers.statusLabel("assigned"), "assigned");
  assert.equal(demoHelpers.statusLabel("picked_up"), "picked up");
  assert.equal(demoHelpers.statusLabel("in_transit"), "in transit");
  assert.equal(demoHelpers.statusLabel("in_warehouse"), "at hub");
  assert.equal(demoHelpers.statusLabel("out_for_delivery"), "out for delivery");
  assert.equal(demoHelpers.statusLabel("delivered"), "delivered");
  assert.equal(demoHelpers.statusLabel("failed"), "failed attempt");
  assert.equal(demoHelpers.statusLabel("cod_settled"), "cod settled");

  assert.match(sources.php, /function statusLabel/);
  assert.match(sources.php, /'en_route', 'in_transit' => 'in transit'/);
  assert.match(sources.vercelTrack, /function statusLabel/);
  assert.match(sources.vercelTrack, /in_warehouse: "at hub"/);
});

test("date displays use Asia/Dhaka YYYY-MM-DD HH:MM formatting", () => {
  assert.equal(demoHelpers.fmtDate("2026-07-03T10:15:00.000Z"), "2026-07-03 16:15");
  assert.match(demoHelpers.fmtDate("2026-07-03T10:15:00.000Z"), DATE_PATTERN);

  assert.match(sources.php, /new DateTimeZone\(APP_TIMEZONE\)/);
  assert.match(sources.php, /format\('Y-m-d H:i'\)/);
  assert.match(sources.demoComponents, /timeZone: 'Asia\/Dhaka'/);
  assert.match(sources.vercelTrack, /timeZone: "Asia\/Dhaka"/);

  for (const rawDisplay of [
    "Updated ' . e((string)$p['updated_at'])",
    `timeline-time">' . e((string)$ev['ts'])`,
    "monoValue((string)$p['updated_at'])",
    "monoValue((string)$p['created_at'])",
    "monoValue((string)$row['created_at'])",
  ]) {
    assert.equal(sources.php.includes(rawDisplay), false, `raw display remains: ${rawDisplay}`);
  }
});

test("currency copy uses taka with mono digits", () => {
  assert.equal(demoHelpers.money(129900), '<span class="mono">৳1,299</span>');
  assert.match(sources.php, /return \$sign \. '৳'/);
  assert.match(sources.php, /Amount to collect \(৳\)/);
  assert.match(sources.demoMain, /COD amount \(৳\)/);

  const visibleCopy = [
    sources.readme,
    sources.landing,
    sources.docs,
    sources.php,
    sources.demoHtml,
    sources.demoMain,
    sources.demoComponents,
    sources.publicDemo,
    sources.vercelDemo,
    sources.vercelHome,
    sources.vercelTrack,
    sources.vercelSetup,
  ].join("\n");
  assert.doesNotMatch(visibleCopy, /\b(?:USD|BDT|Tk)\b/);
});

test("E-03 spot-check matrix is documented in the loop plan", () => {
  assert.match(sources.plan, /\| Surface \| Product\/tagline \| Status labels \| Date format \| Currency \|/);
  assert.match(sources.plan, /HubRoute \/ self-hosted courier & parcel operations console/);
});
