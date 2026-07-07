import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");
const tokens = Object.fromEntries(
  [...readFileSync("styles/tokens.css", "utf8").matchAll(/--([a-z-]+):([^;]+);/g)].map(
    ([, name, value]) => [name, value.trim()],
  ),
);

function hexRgb(value) {
  return value
    .slice(1)
    .match(/../g)
    .map((channel) => Number.parseInt(channel, 16));
}

function mixHex(foreground, percent, background) {
  const fg = hexRgb(foreground);
  const bg = hexRgb(background);
  return fg.map((channel, index) => Math.round(channel * percent + bg[index] * (1 - percent)));
}

function relativeLuminance(rgb) {
  return rgb
    .map((channel) => {
      const sRgb = channel / 255;
      return sRgb <= 0.03928 ? sRgb / 12.92 : ((sRgb + 0.055) / 1.055) ** 2.4;
    })
    .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
}

function contrastRatio(foreground, background) {
  const fgLum = relativeLuminance(hexRgb(foreground));
  const bgLum = relativeLuminance(Array.isArray(background) ? background : hexRgb(background));
  const [lighter, darker] = [fgLum, bgLum].sort((a, b) => b - a);
  return (lighter + 0.05) / (darker + 0.05);
}

test("PHP status pills include a visible LED and text label", () => {
  assert.match(php, /function uiPill/);
  assert.match(php, /<span class="led" aria-hidden="true"><\/span>/);
  assert.match(php, /function statusPill/);
});

test("PHP status pill color groups follow the design contract", () => {
  assert.match(php, /\.pill\{[^}]*font:600 10\.5px\/1\.2 var\(--mono\)/);
  assert.match(php, /\.pill\.bg-blue,\.pill\.bg-indigo,\.pill\.bg-purple\{[^}]*color:var\(--brand\)/);
  assert.match(php, /\.pill\.bg-amber,\.pill\.bg-orange,\.pill\.bg-teal\{[^}]*color:var\(--warn\)/);
  assert.match(php, /\.pill\.bg-green\{[^}]*color:var\(--ok\)/);
  assert.match(php, /\.pill\.bg-red\{[^}]*color:var\(--danger\)/);
  assert.match(php, /\.led\{[^}]*width:8px;height:8px;border-radius:999px/);
});

test("PHP status chip foregrounds meet AA contrast on their tinted backgrounds", () => {
  const panel = tokens.panel;
  const checks = [
    ["brand", tokens.brand, tokens["brand-soft"]],
    ["ok", tokens.ok, mixHex(tokens.ok, 0.08, panel)],
    ["warn", tokens.warn, mixHex(tokens.warn, 0.08, panel)],
    ["danger", tokens.danger, mixHex(tokens.danger, 0.08, panel)],
  ];

  for (const [name, foreground, background] of checks) {
    assert.ok(contrastRatio(foreground, background) >= 4.5, `${name} chip contrast is AA`);
  }
});
