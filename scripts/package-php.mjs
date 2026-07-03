#!/usr/bin/env node

import { cp, mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { spawn } from "node:child_process";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const distDir = path.join(root, "dist");
const packageName = "hubroute-php-sqlite";
const stageDir = path.join(distDir, packageName);
const zipPath = path.join(distDir, `${packageName}.zip`);

const runtimeFiles = [
  "hubroute.php",
  "maintenance.php",
  "index.php",
  "health.php",
  ".htaccess",
  ".env.example"
];

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
await mkdir(path.join(stageDir, "data"), { recursive: true });

for (const file of runtimeFiles) {
  await cp(path.join(root, file), path.join(stageDir, file), { recursive: true });
}

await writeFile(path.join(stageDir, "data", ".htaccess"), "Require all denied\nDeny from all\n");
await writeFile(path.join(stageDir, "data", "index.html"), "");
await writeFile(
  path.join(stageDir, "README.txt"),
  [
    "HubRoute PHP + SQLite",
    "",
    "1. Extract this zip into your PHP 8.1+ hosting folder.",
    "2. Enable pdo_sqlite and sqlite3.",
    "3. Open /health.php.",
    "4. Open / or /hubroute.php?r=login&demo=hub.",
    "5. Rotate seeded passwords before real operations.",
    "6. Optional cron: php maintenance.php run --apply",
    "",
    "Default first login:",
    "pickuphub@hubroute.local / hub1234",
    ""
  ].join("\n")
);

await run("zip", ["-qr", zipPath, "."], { cwd: stageDir });

console.log(`[php-package] Created ${rel(zipPath)}`);
console.log(`[php-package] Staged runtime files in ${rel(stageDir)}`);
