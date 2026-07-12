#!/usr/bin/env node

import { cp, mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const stageDir = path.join(root, "dist", "hubroute-site");
const files = ["index.html", "docs.html", "llms.txt", ".nojekyll"];

await rm(stageDir, { recursive: true, force: true });
await mkdir(stageDir, { recursive: true });

for (const file of files) {
  await cp(path.join(root, file), path.join(stageDir, file));
}
await cp(path.join(root, "styles"), path.join(stageDir, "styles"), { recursive: true });
await cp(path.join(root, "brand"), path.join(stageDir, "brand"), { recursive: true });

await writeFile(
  path.join(stageDir, "vercel.json"),
  JSON.stringify(
    {
      $schema: "https://openapi.vercel.sh/vercel.json",
      framework: null,
      outputDirectory: ".",
      cleanUrls: true,
      headers: [
        {
          source: "/(.*)",
          headers: [
            { key: "X-Content-Type-Options", value: "nosniff" },
            { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" }
          ]
        }
      ]
    },
    null,
    2
  ) + "\n"
);

console.log("[site-package] Staged landing page in dist/hubroute-site");
