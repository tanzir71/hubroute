#!/usr/bin/env node

import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tokensPath = path.join(root, "styles", "tokens.css");
const phpPath = path.join(root, "hubroute.php");

const normalize = (value) => value.replace(/\s+/g, "");
const rel = (filePath) => path.relative(root, filePath);

const [tokensCss, php] = await Promise.all([
  readFile(tokensPath, "utf8"),
  readFile(phpPath, "utf8")
]);

const tokenEntries = [...tokensCss.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)]
  .map((match) => [match[1], match[2]]);
const tokenMap = new Map(tokenEntries);
const declarations = tokenEntries.map(([name, value]) => `${name}:${value};`);

if (declarations.length === 0) {
  throw new Error(`No CSS tokens found in ${rel(tokensPath)}`);
}

const normalizedPhp = normalize(php);
const missing = declarations.filter((declaration) => !normalizedPhp.includes(normalize(declaration)));
const mismatched = [...php.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)]
  .filter((match) => tokenMap.has(match[1]) && normalize(match[2]) !== normalize(tokenMap.get(match[1])));

if (missing.length > 0 || mismatched.length > 0) {
  if (missing.length > 0) {
    console.error(`[tokens] ${rel(phpPath)} is missing token declarations from ${rel(tokensPath)}:`);
  }
  for (const declaration of missing) {
    console.error(`  ${declaration}`);
  }
  if (mismatched.length > 0) {
    console.error(`[tokens] ${rel(phpPath)} has token declarations that drift from ${rel(tokensPath)}:`);
  }
  for (const match of mismatched) {
    console.error(`  ${match[1]}:${match[2]}; expected ${match[1]}:${tokenMap.get(match[1])};`);
  }
  process.exit(1);
}

console.log(`[tokens] ${rel(phpPath)} contains all ${declarations.length} token declarations from ${rel(tokensPath)}.`);
