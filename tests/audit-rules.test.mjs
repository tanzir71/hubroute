import test from "node:test";
import assert from "node:assert/strict";
import { CAPABILITIES } from "../src/domain/authorization-rules.mjs";
import {
  AUDIT_ACTIONS,
  createAuditEntry,
  requiresAudit,
  validateAuditEntry,
  validateAuditRegistry
} from "../src/domain/audit-rules.mjs";

const validCapabilities = new Set(Object.keys(CAPABILITIES));

test("audit registry references known authorization capabilities", () => {
  assert.deepEqual(validateAuditRegistry(validCapabilities), []);
  assert.deepEqual(Object.keys(AUDIT_ACTIONS).sort(), [
    "cod_ledger_adjustment",
    "network_config_changed",
    "parcel_event_correction",
    "parcel_metadata_update",
    "remittance_created",
    "remittance_mark_paid",
    "route_run_update",
    "user_role_changed"
  ]);
});

test("parcel event corrections require before, after, and reason", () => {
  assert.deepEqual(
    validateAuditEntry({
      actorId: "admin_1",
      action: "parcel_event_correction",
      entityType: "parcel_event",
      entityId: "event_1",
      before: { status: "delivered" },
      after: { status: "out_for_delivery" },
      reason: "incorrect terminal scan"
    }),
    []
  );

  assert.deepEqual(
    validateAuditEntry({
      actorId: "admin_1",
      action: "parcel_event_correction",
      entityType: "parcel_event",
      entityId: "event_1",
      before: { status: "delivered" },
      after: { status: "out_for_delivery" }
    }),
    ["parcel_event_correction requires a reason"]
  );
});

test("remittance creation requires the after snapshot but not a before snapshot", () => {
  assert.deepEqual(
    validateAuditEntry({
      actorId: "ops_1",
      action: "remittance_created",
      entityType: "remittance",
      entityId: "remit_1",
      after: { merchant_id: "merchant_1", net_payable: 41200 }
    }),
    []
  );
});

test("network configuration changes are restricted to known config entities", () => {
  assert.deepEqual(
    validateAuditEntry({
      actorId: "admin_1",
      action: "network_config_changed",
      entityType: "rate_card",
      entityId: "rate_1",
      before: { charge: 80 },
      after: { charge: 90 },
      reason: "approved monthly rate update"
    }),
    []
  );

  assert.deepEqual(
    validateAuditEntry({
      actorId: "admin_1",
      action: "network_config_changed",
      entityType: "parcel",
      entityId: "parcel_1",
      before: { charge: 80 },
      after: { charge: 90 },
      reason: "wrong target"
    }),
    ["network_config_changed must target one of: hub, zone, coverage_area, rate_card, sla_policy"]
  );
});

test("unknown audit actions fail closed", () => {
  assert.equal(requiresAudit("user_role_changed"), true);
  assert.equal(requiresAudit("unknown_action"), false);
  assert.deepEqual(
    validateAuditEntry({
      actorId: "admin_1",
      action: "unknown_action",
      entityType: "user",
      entityId: "user_1"
    }),
    ["unknown audit action: unknown_action"]
  );
});

test("createAuditEntry builds a validated timestamped entry", () => {
  const entry = createAuditEntry({
    actorId: "admin_1",
    action: "user_role_changed",
    entityType: "user",
    entityId: "user_1",
    before: { role: "hub_staff" },
    after: { role: "ops" },
    reason: "ops lead promotion",
    createdAt: "2026-07-04T10:15:00.000Z"
  });

  assert.equal(entry.createdAt, "2026-07-04T10:15:00.000Z");
  assert.equal(entry.metadata.constructor, Object);
});

test("invalid audit timestamps are rejected", () => {
  assert.deepEqual(
    validateAuditEntry({
      actorId: "ops_1",
      action: "route_run_update",
      entityType: "run",
      entityId: "run_1",
      before: { status: "planned" },
      after: { status: "dispatched" },
      createdAt: "not a timestamp"
    }),
    ["createdAt must be an ISO-compatible timestamp"]
  );
});
