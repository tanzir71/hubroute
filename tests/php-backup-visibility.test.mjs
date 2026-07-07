import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");
const maintenance = readFileSync("maintenance.php", "utf8");
const envExample = readFileSync(".env.example", "utf8");
const docs = readFileSync("docs.html", "utf8");
const setup = readFileSync("SETUP.md", "utf8");

test("environment example explains local sync backup directories safely", () => {
  assert.match(envExample, /BACKUP_DIR defaults to data\/backups/);
  assert.match(envExample, /BACKUP_DIR=C:\\Users\\you\\OneDrive\\hubroute-backups/);
  assert.match(envExample, /BACKUP_DIR=\/home\/you\/GoogleDrive\/hubroute-backups/);
  assert.match(envExample, /SYNC THE BACKUP FOLDER ONLY/);
  assert.match(envExample, /never place DATA_DIR or the live hubroute\.sqlite inside a sync folder/);
});

test("maintenance notices external backup dirs without requiring web-root deny files", () => {
  assert.match(maintenance, /function maintIsExternalPath/);
  assert.match(maintenance, /\$backupDirExternal = maintIsExternalPath\(\$backupDir, __DIR__\)/);
  assert.match(maintenance, /maintBackupSqlite\(\$pdo, \$dbPath, \$backupDir, \$backupDirExternal\)/);
  assert.match(maintenance, /if \(!\$externalBackupDir\) \{\s*maintWriteDenyFiles\(\$backupDir\);/s);
  assert.match(maintenance, /backup_dir=' \. \$backupDir \. ' \(external\)/);
});

test("admin overview surfaces backup age and stale/never-run states", () => {
  assert.match(php, /define\('BACKUP_DIR'/);
  assert.match(php, /function latestBackupInfo/);
  assert.match(php, /glob\(\$dir \. DIRECTORY_SEPARATOR \. 'hubroute-\*\.sqlite'\)/);
  assert.match(php, /function renderBackupFactsCard/);
  assert.match(php, /php maintenance\.php run --apply/);
  assert.match(php, /never run/);
  assert.match(php, /stale/);
  assert.match(php, /renderBackupFactsCard\(\)/);
});

test("operator docs describe bring-your-own cloud sync backups", () => {
  for (const content of [docs, setup]) {
    assert.match(content, /Automatic offsite backups \(no vendor lock-in\)/);
    assert.match(content, /OneDrive/);
    assert.match(content, /Google Drive/);
    assert.match(content, /BACKUP_DIR/);
    assert.match(content, /HubRoute only writes local files/);
  }
});
