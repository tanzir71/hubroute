import test from "node:test";
import assert from "node:assert/strict";
import {
  IDEMPOTENCY_DECISIONS,
  IDEMPOTENCY_RECORD_STATUSES,
  IDEMPOTENT_OPERATIONS,
  canonicalJson,
  createIdempotencyScope,
  createRequestFingerprint,
  decideIdempotency,
  validateIdempotencyKey,
  validateIdempotencyRegistry
} from "../src/domain/idempotency-rules.mjs";

const actorId = "user_ops_001";
const key = "scan-01HZX7Y9M3Q2";
const body = {
  parcel_id: "parcel_001",
  event_key: "arrived_hub",
  hub_id: "hub_dac_sort"
};

test("idempotency registry covers every backend mutation marked idempotent", () => {
  assert.deepEqual(validateIdempotencyRegistry(), []);
  assert.deepEqual(Object.keys(IDEMPOTENT_OPERATIONS).sort(), [
    "admin_create_agent",
    "admin_create_customer",
    "admin_create_hub",
    "agent_step",
    "bulk_import_parcels",
    "capture_parcel_event",
    "collect_cod",
    "configure_webhook",
    "create_parcel",
    "create_remittance",
    "create_run",
    "customer_confirm",
    "customer_create",
    "hub_assign",
    "hub_create_route",
    "record_event",
    "settle",
    "update_parcel_metadata",
    "update_run"
  ]);
  assert.equal(IDEMPOTENT_OPERATIONS.capture_parcel_event.offlineReplay, true);
  assert.equal(IDEMPOTENT_OPERATIONS.record_event.offlineReplay, true);
});

test("validates idempotency key syntax", () => {
  assert.deepEqual(validateIdempotencyKey(key), { ok: true, error: "" });
  assert.equal(validateIdempotencyKey("short").ok, false);
  assert.equal(validateIdempotencyKey("has spaces 01HZX7Y9").ok, false);
  assert.equal(validateIdempotencyKey(" scan-01HZX7Y9M3Q2").ok, false);
});

test("canonical fingerprints are stable across object key order", () => {
  const first = createRequestFingerprint({
    operation: "capture_parcel_event",
    body: { event_key: "arrived_hub", hub_id: "hub_dac_sort", parcel_id: "parcel_001" }
  });
  const second = createRequestFingerprint({ operation: "capture_parcel_event", body });

  assert.equal(first, second);
  assert.equal(
    canonicalJson({ b: 2, a: { d: 4, c: 3 } }),
    "{\"a\":{\"c\":3,\"d\":4},\"b\":2}"
  );
});

test("new idempotent request proceeds with an actor and operation scoped storage key", () => {
  const decision = decideIdempotency({
    actorId,
    operation: "capture_parcel_event",
    key,
    body
  });

  assert.equal(decision.decision, IDEMPOTENCY_DECISIONS.proceed);
  assert.equal(decision.storageKey, "user_ops_001:capture_parcel_event:scan-01HZX7Y9M3Q2");
  assert.match(decision.fingerprint, /^[a-f0-9]{64}$/);
});

test("completed duplicate request replays the stored response", () => {
  const fingerprint = createRequestFingerprint({ operation: "capture_parcel_event", body });
  const decision = decideIdempotency({
    actorId,
    operation: "capture_parcel_event",
    key,
    body,
    existingRecord: {
      fingerprint,
      status: IDEMPOTENCY_RECORD_STATUSES.completed,
      statusCode: 201,
      response: { parcel_status: "arrived_hub" }
    }
  });

  assert.equal(decision.decision, IDEMPOTENCY_DECISIONS.replay);
  assert.equal(decision.statusCode, 201);
  assert.deepEqual(decision.response, { parcel_status: "arrived_hub" });
});

test("processing duplicate request asks the client to retry later", () => {
  const fingerprint = createRequestFingerprint({ operation: "capture_parcel_event", body });
  const decision = decideIdempotency({
    actorId,
    operation: "capture_parcel_event",
    key,
    body,
    existingRecord: {
      fingerprint,
      status: IDEMPOTENCY_RECORD_STATUSES.processing
    }
  });

  assert.equal(decision.decision, IDEMPOTENCY_DECISIONS.retry_later);
});

test("same key with a different request fingerprint is rejected as a conflict", () => {
  const fingerprint = createRequestFingerprint({ operation: "capture_parcel_event", body });
  const decision = decideIdempotency({
    actorId,
    operation: "capture_parcel_event",
    key,
    body: { ...body, hub_id: "hub_ctg_delivery" },
    existingRecord: {
      fingerprint,
      status: IDEMPOTENCY_RECORD_STATUSES.completed
    }
  });

  assert.equal(decision.decision, IDEMPOTENCY_DECISIONS.conflict);
});

test("retryable failure can proceed with the same request fingerprint", () => {
  const fingerprint = createRequestFingerprint({ operation: "collect_cod", body: { parcel_id: "p1", amount: 1250 } });
  const decision = decideIdempotency({
    actorId,
    operation: "collect_cod",
    key: "cod-01HZX7Y9M3Q2",
    body: { amount: 1250, parcel_id: "p1" },
    existingRecord: {
      fingerprint,
      status: IDEMPOTENCY_RECORD_STATUSES.failed_retryable
    }
  });

  assert.equal(decision.decision, IDEMPOTENCY_DECISIONS.proceed);
});

test("same key is isolated by actor and operation scope", () => {
  assert.notEqual(
    createIdempotencyScope({ actorId: "agent_1", operation: "collect_cod", key: "shared-01HZX7Y9" }).storageKey,
    createIdempotencyScope({ actorId: "agent_2", operation: "collect_cod", key: "shared-01HZX7Y9" }).storageKey
  );
  assert.notEqual(
    createIdempotencyScope({ actorId: "agent_1", operation: "collect_cod", key: "shared-01HZX7Y9" }).storageKey,
    createIdempotencyScope({ actorId: "agent_1", operation: "update_run", key: "shared-01HZX7Y9" }).storageKey
  );
});

test("unknown operations and missing actor scopes fail closed", () => {
  assert.equal(
    decideIdempotency({ actorId, operation: "delete_everything", key, body }).decision,
    IDEMPOTENCY_DECISIONS.reject
  );
  assert.equal(
    decideIdempotency({ actorId: "", operation: "create_parcel", key, body }).decision,
    IDEMPOTENCY_DECISIONS.reject
  );
});
