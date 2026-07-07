import test from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

const maxHtmlBytes = 250 * 1024;
const scannedRoots = ["index.html", "docs.html", "hubroute.php", "src", "vercel-app"];
const scannedExts = new Set([".html", ".css", ".js", ".php"]);

function walk(path) {
  const stats = statSync(path);
  if (stats.isFile()) return [path];
  return readdirSync(path, { withFileTypes: true }).flatMap((entry) => walk(join(path, entry.name)));
}

function extname(path) {
  const dot = path.lastIndexOf(".");
  return dot === -1 ? "" : path.slice(dot);
}

const scannedFiles = scannedRoots
  .flatMap((root) => walk(root))
  .filter((file) => scannedExts.has(extname(file)));

test("primary HTML payloads stay under the 250 KB budget", () => {
  for (const file of ["index.html", "docs.html", "public/index.html"]) {
    assert.ok(statSync(file).size < maxHtmlBytes, `${file} exceeds 250 KB`);
  }
});

test("surfaces do not include CDN font/script dependencies", () => {
  for (const file of scannedFiles) {
    const source = readFileSync(file, "utf8");
    assert.doesNotMatch(source, /fonts\.googleapis|cdn\.jsdelivr|unpkg/i, file);
    assert.doesNotMatch(source, /@import\s+url\(\s*["']?https?:\/\//i, file);
    assert.doesNotMatch(source, /<link[^>]+rel=["']stylesheet["'][^>]+href=["']https?:\/\//i, file);
    assert.doesNotMatch(source, /<script[^>]+src=["']https?:\/\//i, file);
  }
});

test("landing page avoids eager raster media and layout-shifting images", () => {
  const landing = readFileSync("index.html", "utf8");
  const images = landing.match(/<img\b[^>]*>/gi) ?? [];
  for (const img of images) {
    assert.match(img, /loading="lazy"/i);
    assert.match(img, /\bwidth="/i);
    assert.match(img, /\bheight="/i);
  }
});

test("demo smoke stays on local generated assets", () => {
  const smoke = readFileSync("scripts/smoke-demo.mjs", "utf8");
  assert.match(smoke, /public", "index\.html"/);
  assert.match(smoke, /public", "styles", "tokens\.css"/);
  assert.doesNotMatch(smoke, /fetch\(/);
});
