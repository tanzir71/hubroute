#!/usr/bin/env node

import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const mirrors = [
  [path.join(root, "public", "index.html"), path.join(root, "vercel-demo", "index.html")],
  [path.join(root, "public", "styles", "tokens.css"), path.join(root, "vercel-demo", "styles", "tokens.css")]
];
const checkOnly = process.argv.includes("--check");

const rel = (filePath) => path.relative(root, filePath);

if (checkOnly) {
  for (const [sourcePath, mirrorPath] of mirrors) {
    const source = await readFile(sourcePath, "utf8");
    const mirror = await readFile(mirrorPath, "utf8").catch((error) => {
      if (error.code === "ENOENT") return null;
      throw error;
    });
    if (source !== mirror) {
      console.error(
        `[M0-01/M0-03] ${rel(mirrorPath)} is out of sync with ${rel(sourcePath)}. Run npm run sync:demo.`
      );
      process.exit(1);
    }
  }

  console.log("[M0-01/M0-03] Demo mirror files match generated public files.");
  process.exit(0);
}

let synced = 0;
for (const [sourcePath, mirrorPath] of mirrors) {
  const source = await readFile(sourcePath, "utf8");
  const mirror = await readFile(mirrorPath, "utf8").catch((error) => {
    if (error.code === "ENOENT") return null;
    throw error;
  });

  if (source !== mirror) {
    await mkdir(path.dirname(mirrorPath), { recursive: true });
    await writeFile(mirrorPath, source);
    synced += 1;
    console.log(`[M0-01/M0-03] Synced ${rel(mirrorPath)} from ${rel(sourcePath)}.`);
  }
}

if (!synced) {
  console.log("[M0-01/M0-03] Demo mirror files already match generated public files.");
}
