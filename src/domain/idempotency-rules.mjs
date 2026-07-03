import { createHash } from "node:crypto";

export const IDEMPOTENCY_HEADER = "Idempotency-Key";

export const IDEMPOTENT_OPERATIONS = {
  customer_create: {
    method: "POST",
    path: "form:customer_create",
    retentionHours: 24
  },
  customer_confirm: {
    method: "POST",
    path: "form:customer_confirm",
    retentionHours: 24
  },
  hub_assign: {
    method: "POST",
    path: "form:hub_assign",
    retentionHours: 24
  },
  hub_create_route: {
    method: "POST",
    path: "form:hub_create_route",
    retentionHours: 24
  },
  record_event: {
    method: "POST",
    path: "form:record_event",
    retentionHours: 72,
    offlineReplay: true
  },
  agent_step: {
    method: "POST",
    path: "form:agent_step",
    retentionHours: 72
  },
  settle: {
    method: "POST",
    path: "form:settle",
    retentionHours: 72
  },
  admin_create_hub: {
    method: "POST",
    path: "form:admin_create_hub",
    retentionHours: 24
  },
  admin_create_agent: {
    method: "POST",
    path: "form:admin_create_agent",
    retentionHours: 24
  },
  admin_create_customer: {
    method: "POST",
    path: "form:admin_create_customer",
    retentionHours: 24
  },
  create_parcel: {
    method: "POST",
    path: "/api/parcels",
    retentionHours: 24
  },
  update_parcel_metadata: {
    method: "PATCH",
    path: "/api/parcels/{id}",
    retentionHours: 24
  },
  bulk_import_parcels: {
    method: "POST",
    path: "/api/parcels/bulk-import",
    retentionHours: 72
  },
  capture_parcel_event: {
    method: "POST",
    path: "/api/events",
    retentionHours: 72,
    offlineReplay: true
  },
  create_run: {
    method: "POST",
    path: "/api/runs",
    retentionHours: 24
  },
  update_run: {
    method: "PATCH",
    path: "/api/runs/{id}",
    retentionHours: 24
  },
  collect_cod: {
    method: "POST",
    path: "/api/cod/collect",
    retentionHours: 72
  },
  create_remittance: {
    method: "POST",
    path: "/api/remittances",
    retentionHours: 168
  },
  configure_webhook: {
    method: "POST",
    path: "/api/webhooks",
    retentionHours: 24
  }
};

export const IDEMPOTENCY_RECORD_STATUSES = {
  processing: "processing",
  completed: "completed",
  failed_retryable: "failed_retryable",
  failed_final: "failed_final"
};

export const IDEMPOTENCY_DECISIONS = {
  proceed: "proceed",
  replay: "replay",
  retry_later: "retry_later",
  conflict: "conflict",
  reject: "reject"
};

const keyPattern = /^[A-Za-z0-9._:-]{12,128}$/;
const recordStatusSet = new Set(Object.values(IDEMPOTENCY_RECORD_STATUSES));

export function isIdempotentOperation(operation){
  return Object.hasOwn(IDEMPOTENT_OPERATIONS, operation);
}

export function validateIdempotencyKey(key){
  if (typeof key !== "string") {
    return { ok: false, error: `${IDEMPOTENCY_HEADER} must be a string` };
  }

  if (key.trim() !== key) {
    return { ok: false, error: `${IDEMPOTENCY_HEADER} must not include leading or trailing whitespace` };
  }

  if (!keyPattern.test(key)) {
    return {
      ok: false,
      error: `${IDEMPOTENCY_HEADER} must be 12-128 characters using letters, numbers, dot, underscore, colon, or dash`
    };
  }

  return { ok: true, error: "" };
}

export function validateIdempotencyRegistry(){
  const errors = [];

  for (const [operation, contract] of Object.entries(IDEMPOTENT_OPERATIONS)) {
    if (!contract.method) errors.push(`${operation} is missing method`);
    if (!contract.path) errors.push(`${operation} is missing path`);
    if (!Number.isInteger(contract.retentionHours) || contract.retentionHours < 24) {
      errors.push(`${operation} must retain idempotency records for at least 24 hours`);
    }
  }

  return errors;
}

export function canonicalJson(value){
  const seen = new WeakSet();

  function normalize(input){
    if (input === undefined) return undefined;
    if (input === null || typeof input !== "object") return input;
    if (input instanceof Date) return input.toISOString();

    if (seen.has(input)) {
      throw new TypeError("Cannot create an idempotency fingerprint from circular input");
    }
    seen.add(input);

    if (Array.isArray(input)) {
      return input.map((item) => {
        const normalized = normalize(item);
        return normalized === undefined ? null : normalized;
      });
    }

    const output = {};
    for (const key of Object.keys(input).sort()) {
      const normalized = normalize(input[key]);
      if (normalized !== undefined) output[key] = normalized;
    }
    return output;
  }

  return JSON.stringify(normalize(value));
}

export function createRequestFingerprint({ operation, body = {} }){
  const contract = IDEMPOTENT_OPERATIONS[operation];
  if (!contract) throw new Error(`Unknown idempotent operation: ${operation}`);

  const canonical = canonicalJson({
    operation,
    method: contract.method,
    path: contract.path,
    body
  });

  return createHash("sha256").update(canonical).digest("hex");
}

export function createIdempotencyScope({ actorId, operation, key }){
  if (!isIdempotentOperation(operation)) {
    return { ok: false, error: `Unknown idempotent operation: ${operation}` };
  }

  if (!actorId || typeof actorId !== "string") {
    return { ok: false, error: "actorId is required for idempotency scoping" };
  }

  const keyValidation = validateIdempotencyKey(key);
  if (!keyValidation.ok) return keyValidation;

  return {
    ok: true,
    actorId,
    operation,
    key,
    storageKey: `${encodeURIComponent(actorId)}:${operation}:${key}`
  };
}

export function validateIdempotencyRequest({ actorId, operation, key, body = {} }){
  const scope = createIdempotencyScope({ actorId, operation, key });
  if (!scope.ok) return { ...scope, fingerprint: "" };

  return {
    ...scope,
    fingerprint: createRequestFingerprint({ operation, body })
  };
}

export function decideIdempotency({ actorId, operation, key, body = {}, existingRecord = null }){
  const request = validateIdempotencyRequest({ actorId, operation, key, body });
  if (!request.ok) {
    return {
      decision: IDEMPOTENCY_DECISIONS.reject,
      reason: request.error,
      ok: false
    };
  }

  if (!existingRecord) {
    return {
      ...request,
      decision: IDEMPOTENCY_DECISIONS.proceed,
      reason: "No prior request for this actor, operation, and key"
    };
  }

  if (existingRecord.fingerprint !== request.fingerprint) {
    return {
      ...request,
      decision: IDEMPOTENCY_DECISIONS.conflict,
      reason: "Idempotency key was reused with a different request fingerprint"
    };
  }

  if (!recordStatusSet.has(existingRecord.status)) {
    return {
      ...request,
      decision: IDEMPOTENCY_DECISIONS.reject,
      reason: `Unknown idempotency record status: ${existingRecord.status}`
    };
  }

  if (existingRecord.status === IDEMPOTENCY_RECORD_STATUSES.processing) {
    return {
      ...request,
      decision: IDEMPOTENCY_DECISIONS.retry_later,
      reason: "Matching request is still processing"
    };
  }

  if (existingRecord.status === IDEMPOTENCY_RECORD_STATUSES.failed_retryable) {
    return {
      ...request,
      decision: IDEMPOTENCY_DECISIONS.proceed,
      reason: "Matching request previously failed before committing and can be retried"
    };
  }

  return {
    ...request,
    decision: IDEMPOTENCY_DECISIONS.replay,
    reason: "Matching request already completed with a stored result",
    response: existingRecord.response ?? null,
    statusCode: existingRecord.statusCode ?? null
  };
}
