import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const php = readFileSync("hubroute.php", "utf8");
const loginStart = php.indexOf("function pageLogin");
const loginEnd = php.indexOf("function actionLogin");
const login = php.slice(loginStart, loginEnd);

test("login screen uses the branded first-impression layout", () => {
  assert.match(php, /function logoMark/);
  assert.match(login, /class="login-shell"/);
  assert.match(login, /class="login-card"/);
  assert.match(login, /logoMark\(\)/);
  assert.match(login, /Parcel operations console/);
  assert.match(login, /Self-hosted &middot; PHP 8 \+ SQLite/);
  assert.match(php, /\.login-card\{[^}]*max-width:400px/);
  assert.match(php, /\.login-card \.btn\.primary\{[^}]*width:100%/);
});

test("login screen does not render seeded demo credentials", () => {
  assert.doesNotMatch(login, /demoEmail|Demo hub account|pickuphub@hubroute\.local|hub1234/);
  assert.match(login, /<input name="email" type="email" required>/);
  assert.match(login, /<input name="password" type="password" required>/);
  assert.match(login, /<button class="btn primary" type="submit">Sign in<\/button>/);
});
