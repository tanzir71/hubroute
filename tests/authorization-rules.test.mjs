import test from "node:test";
import assert from "node:assert/strict";
import {
  CAPABILITIES,
  ROLES,
  canPerform,
  getAuthorizationDecision,
  validateAuthorizationMatrix
} from "../src/domain/authorization-rules.mjs";

test("authorization matrix has every role for every capability", () => {
  assert.deepEqual(validateAuthorizationMatrix(), []);
});

test("admin and ops can see all parcels while scoped roles cannot", () => {
  assert.equal(getAuthorizationDecision("admin", "view_all_parcels"), "allow");
  assert.equal(getAuthorizationDecision("ops", "view_all_parcels"), "allow");
  assert.equal(getAuthorizationDecision("hub_staff", "view_all_parcels"), "deny");
  assert.equal(getAuthorizationDecision("merchant", "view_all_parcels"), "deny");
});

test("hub and rider operational access remains scoped", () => {
  assert.deepEqual(canPerform("hub_staff", "view_hub_scoped_parcels"), {
    allowed: true,
    decision: "scoped",
    scopeRequired: true
  });
  assert.deepEqual(canPerform("agent", "capture_parcel_event"), {
    allowed: true,
    decision: "scoped",
    scopeRequired: true
  });
  assert.equal(getAuthorizationDecision("agent", "create_route_run"), "deny");
});

test("merchant and customer access is constrained to merchant parcel and public tracking scopes", () => {
  assert.equal(getAuthorizationDecision("merchant", "view_merchant_parcels"), "scoped");
  assert.equal(getAuthorizationDecision("customer", "view_merchant_parcels"), "scoped");
  assert.equal(getAuthorizationDecision("merchant", "capture_parcel_event"), "deny");
  assert.equal(getAuthorizationDecision("customer", "update_parcel_metadata"), "deny");
  assert.equal(getAuthorizationDecision("public", "public_tracking_by_code"), "scoped");
});

test("privileged finance, admin, and audit capabilities are restricted", () => {
  assert.equal(getAuthorizationDecision("admin", "manage_users_roles"), "allow");
  assert.equal(getAuthorizationDecision("ops", "manage_users_roles"), "deny");
  assert.equal(getAuthorizationDecision("ops", "create_remittance"), "allow");
  assert.equal(getAuthorizationDecision("hub_staff", "create_remittance"), "deny");
  assert.equal(getAuthorizationDecision("ops", "view_audit_log"), "allow");
  assert.equal(getAuthorizationDecision("merchant", "view_audit_log"), "deny");
});

test("unknown roles and capabilities fail closed", () => {
  assert.equal(getAuthorizationDecision("unknown", "view_all_parcels"), "deny");
  assert.equal(getAuthorizationDecision("admin", "unknown_capability"), "deny");
  assert.deepEqual(canPerform("public", "unknown_capability"), {
    allowed: false,
    decision: "deny",
    scopeRequired: false
  });
});

test("matrix contains the expected role and capability count", () => {
  assert.equal(ROLES.length, 7);
  assert.equal(Object.keys(CAPABILITIES).length, 16);
});
