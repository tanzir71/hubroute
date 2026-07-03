#!/usr/bin/env node

import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const sourceHtmlPath = path.join(root, "src", "demo", "index.html");
const entryPath = path.join(root, "src", "demo", "main.js");
const tokensPath = path.join(root, "styles", "tokens.css");
const outputPath = path.join(root, "public", "index.html");
const outputTokensPath = path.join(root, "public", "styles", "tokens.css");
const scriptTag = '  <script type="module" src="./main.js"></script>';
const checkOnly = process.argv.includes("--check");

const rel = (filePath) => path.relative(root, filePath);

const [sourceHtml, tokensCss, bundled] = await Promise.all([
  readFile(sourceHtmlPath, "utf8"),
  readFile(tokensPath, "utf8"),
  build({
    entryPoints: [entryPath],
    bundle: true,
    format: "iife",
    target: "es2020",
    write: false,
    legalComments: "none",
    logLevel: "silent"
  })
]);

if (!sourceHtml.includes(scriptTag)) {
  throw new Error(`${rel(sourceHtmlPath)} must include ${scriptTag}`);
}

const script = bundled.outputFiles[0].text.trimEnd();
const generatedHtml = sourceHtml.replace(
  scriptTag,
  `  <script>\n${script.split("\n").map((line) => (line ? `    ${line}` : "")).join("\n")}\n  </script>`
);

if (checkOnly) {
  const [currentOutput, currentTokens] = await Promise.all([
    readFile(outputPath, "utf8"),
    readFile(outputTokensPath, "utf8").catch((error) => {
      if (error.code === "ENOENT") return null;
      throw error;
    })
  ]);
  if (currentOutput !== generatedHtml) {
    console.error(`[M0-02] ${rel(outputPath)} is out of date. Run npm run build:demo.`);
    process.exit(1);
  }
  if (currentTokens !== tokensCss) {
    console.error(`[M0-03] ${rel(outputTokensPath)} is out of date. Run npm run build:demo.`);
    process.exit(1);
  }
  console.log(`[M0-02/M0-03] ${rel(outputPath)} and ${rel(outputTokensPath)} match source.`);
  process.exit(0);
}

await mkdir(path.dirname(outputTokensPath), { recursive: true });
await writeFile(outputPath, generatedHtml);
await writeFile(outputTokensPath, tokensCss);
console.log(`[M0-02/M0-03] Built ${rel(outputPath)} and copied ${rel(outputTokensPath)}.`);
