#!/usr/bin/env node

import { cp, mkdir, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { spawn } from "node:child_process";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const sourceDir = path.join(root, "vercel-app");
const distDir = path.join(root, "dist");
const packageName = "hubroute-vercel";
const stageDir = path.join(distDir, packageName);
const zipPath = path.join(distDir, `${packageName}.zip`);

const rel = (filePath) => path.relative(root, filePath);

function run(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      stdio: "inherit",
      ...options
    });
    child.on("error", reject);
    child.on("close", (code) => {
      if (code === 0) {
        resolve();
        return;
      }
      reject(new Error(`${command} ${args.join(" ")} exited with ${code}`));
    });
  });
}

await rm(stageDir, { recursive: true, force: true });
await rm(zipPath, { force: true });
await mkdir(distDir, { recursive: true });
await cp(sourceDir, stageDir, {
  recursive: true,
  filter: (src) => !src.includes(`${path.sep}node_modules${path.sep}`) && !src.endsWith(`${path.sep}.env.local`)
});

await run("zip", ["-qr", zipPath, "."], { cwd: stageDir });

console.log(`[vercel-package] Created ${rel(zipPath)}`);
console.log(`[vercel-package] Staged Vercel files in ${rel(stageDir)}`);
