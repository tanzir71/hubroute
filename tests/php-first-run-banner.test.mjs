import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");

test("first-run seed marks an admin banner for the current session", () => {
  assert.match(php, /\$_SESSION\['fresh_install_seeded'\]\s*=\s*1/);
  assert.match(php, /function renderFreshInstallBanner/);
  assert.match(php, /function shouldShowFreshInstallBanner/);
  assert.match(php, /fresh_install_banner_dismissed/);
  assert.match(php, /dismiss_fresh_install/);
});

test("admin banner gives the three production-hardening steps", () => {
  assert.match(php, /\.fresh-install\{[^}]*background:var\(--brand-soft\)[^}]*border-left:2px solid var\(--brand\)/);
  assert.match(php, /Fresh install/);
  assert.match(php, /create your real admin/);
  assert.match(php, /\?r=admin&tab=users#create-admin/);
  assert.match(php, /disable seed accounts/);
  assert.match(php, /\?r=admin&tab=users/);
  assert.match(php, /php maintenance\.php run --apply/);
  assert.match(php, /renderFreshInstallBanner\(\) \. \$content/);
});
