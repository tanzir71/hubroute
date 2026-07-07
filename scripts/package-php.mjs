#!/usr/bin/env node

import { cp, mkdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { archiveDirectory } from "./package-archive.mjs";

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
    "No build tools, Composer, or database setup are required for this zip.",
    "",
    "Beginner shared-hosting steps:",
    "If you are reading this after extracting the zip on your server, start at step 6.",
    "",
    "1. Log in to cPanel or your hosting control panel.",
    "2. Open File Manager.",
    "3. Turn on Show Hidden Files if your panel has that option.",
    "4. Open public_html or the folder for your subdomain.",
    "5. Upload hubroute-php-sqlite.zip and click Extract.",
    "6. Make sure index.php, hubroute.php, health.php, maintenance.php, .htaccess, .env.example, and data/ are directly in the website folder.",
    "7. If those files are inside a hubroute-php-sqlite folder, move them up into public_html unless you want the app at /hubroute-php-sqlite/.",
    "8. Delete hubroute-php-sqlite.zip after extraction.",
    "9. Set PHP to 8.1+ and enable pdo_sqlite and sqlite3.",
    "10. Open https://YOUR-DOMAIN/health.php.",
    "11. Open https://YOUR-DOMAIN/.",
    "12. Open Admin -> Users, create a production admin, then rotate or disable seeded accounts before real operations.",
    "",
    "Optional cron after first setup:",
    "php maintenance.php run --apply",
    "",
    "Default first login:",
    "pickuphub@hubroute.local / hub1234",
    ""
  ].join("\n")
);

await archiveDirectory({ cwd: stageDir, zipPath });

console.log(`[php-package] Created ${rel(zipPath)}`);
console.log(`[php-package] Staged runtime files in ${rel(stageDir)}`);
