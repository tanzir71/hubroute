import test from "node:test";
import assert from "node:assert/strict";
import {
  EVENT_TRANSITIONS,
  PARCEL_STATUSES,
  nextParcelStatus,
  validateEventInput
} from "../src/domain/status-rules.mjs";

test("creates the initial requested status", () => {
  assert.equal(nextParcelStatus({ eventKey: "created" }), "requested");
});

test("moves through the happy-path delivery chain", () => {
  let status = nextParcelStatus({ eventKey: "created" });
  status = nextParcelStatus({ eventKey: "assigned", currentStatus: status });
  status = nextParcelStatus({ eventKey: "picked_up", currentStatus: status });
  status = nextParcelStatus({ eventKey: "arrived_hub", currentStatus: status });
  status = nextParcelStatus({ eventKey: "sorted", currentStatus: status });
  status = nextParcelStatus({ eventKey: "departed_hub", currentStatus: status });
  status = nextParcelStatus({ eventKey: "arrived_hub", currentStatus: status });
  status = nextParcelStatus({ eventKey: "out_for_delivery", currentStatus: status });
  status = nextParcelStatus({ eventKey: "delivered", currentStatus: status });

  assert.equal(status, "delivered");
});

test("rejects skipped delivery transitions", () => {
  assert.throws(
    () => nextParcelStatus({ eventKey: "delivered", currentStatus: "picked_up" }),
    /delivered is not allowed from picked_up/
  );
});

test("requires reasons for failed attempts and holds", () => {
  assert.deepEqual(
    validateEventInput({ eventKey: "failed_attempt", currentStatus: "out_for_delivery" }),
    { ok: false, error: "failed_attempt requires reason" }
  );
  assert.equal(
    nextParcelStatus({
      eventKey: "failed_attempt",
      currentStatus: "out_for_delivery",
      payload: { reason: "recipient_unreachable" }
    }),
    "failed_attempt"
  );

  assert.throws(
    () => nextParcelStatus({ eventKey: "hold", currentStatus: "in_transit" }),
    /hold requires reason/
  );
});

test("blocks non-correction changes to terminal parcels", () => {
  assert.throws(
    () => nextParcelStatus({ eventKey: "departed_hub", currentStatus: "delivered" }),
    /Terminal status delivered can only be changed by correction/
  );

  assert.equal(
    nextParcelStatus({
      eventKey: "correction",
      currentStatus: "delivered",
      targetStatus: "out_for_delivery"
    }),
    "out_for_delivery"
  );
});

test("allows COD collection only after delivery without changing status", () => {
  assert.equal(
    nextParcelStatus({ eventKey: "cod_collected", currentStatus: "delivered" }),
    "delivered"
  );
  assert.throws(
    () => nextParcelStatus({ eventKey: "cod_collected", currentStatus: "out_for_delivery" }),
    /cod_collected is not allowed from out_for_delivery/
  );
});

test("keeps all resulting statuses within the known status set", () => {
  const known = new Set(PARCEL_STATUSES);

  for (const [eventKey, transition] of Object.entries(EVENT_TRANSITIONS)) {
    assert.ok(transition.allowedFrom.length > 0, `${eventKey} has allowed sources`);
    if (transition.resultingStatus && transition.resultingStatus !== "target") {
      assert.ok(known.has(transition.resultingStatus), `${eventKey} has a known resulting status`);
    }
  }
});
