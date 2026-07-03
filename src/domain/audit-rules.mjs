export const AUDIT_ACTIONS = {
  parcel_event_correction: {
    entityTypes: ["parcel", "parcel_event"],
    capability: "correct_terminal_custody_event",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: true
  },
  parcel_metadata_update: {
    entityTypes: ["parcel"],
    capability: "update_parcel_metadata",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: false
  },
  route_run_update: {
    entityTypes: ["run"],
    capability: "dispatch_close_run",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: false
  },
  cod_ledger_adjustment: {
    entityTypes: ["cod_ledger"],
    capability: "collect_cod",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: true
  },
  remittance_created: {
    entityTypes: ["remittance"],
    capability: "create_remittance",
    requiresBefore: false,
    requiresAfter: true,
    requiresReason: false
  },
  remittance_mark_paid: {
    entityTypes: ["remittance"],
    capability: "create_remittance",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: false
  },
  user_role_changed: {
    entityTypes: ["user"],
    capability: "manage_users_roles",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: true
  },
  network_config_changed: {
    entityTypes: ["hub", "zone", "coverage_area", "rate_card", "sla_policy"],
    capability: "manage_hubs_zones_rates_sla",
    requiresBefore: true,
    requiresAfter: true,
    requiresReason: true
  }
};

function isRecord(value){
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

export function getAuditAction(action){
  return AUDIT_ACTIONS[action] || null;
}

export function requiresAudit(action){
  return Boolean(getAuditAction(action));
}

export function validateAuditRegistry(validCapabilities){
  const errors = [];

  for (const [action, contract] of Object.entries(AUDIT_ACTIONS)) {
    if (!Array.isArray(contract.entityTypes) || contract.entityTypes.length === 0) {
      errors.push(`${action} must declare at least one entity type`);
    }
    if (!contract.capability) {
      errors.push(`${action} must declare a capability`);
    }
    if (validCapabilities && !validCapabilities.has(contract.capability)) {
      errors.push(`${action} references unknown capability ${contract.capability}`);
    }
  }

  return errors;
}

export function validateAuditEntry(entry){
  const errors = [];

  if (!isRecord(entry)) return ["audit entry must be an object"];

  const contract = getAuditAction(entry.action);
  if (!contract) {
    errors.push(`unknown audit action: ${entry.action}`);
    return errors;
  }

  if (!entry.actorId || typeof entry.actorId !== "string") {
    errors.push("actorId is required");
  }

  if (!entry.entityType || !contract.entityTypes.includes(entry.entityType)) {
    errors.push(`${entry.action} must target one of: ${contract.entityTypes.join(", ")}`);
  }

  if (!entry.entityId || typeof entry.entityId !== "string") {
    errors.push("entityId is required");
  }

  if (contract.requiresBefore && !isRecord(entry.before)) {
    errors.push(`${entry.action} requires a before snapshot`);
  }

  if (contract.requiresAfter && !isRecord(entry.after)) {
    errors.push(`${entry.action} requires an after snapshot`);
  }

  if (contract.requiresReason && !entry.reason) {
    errors.push(`${entry.action} requires a reason`);
  }

  if (entry.createdAt && Number.isNaN(Date.parse(entry.createdAt))) {
    errors.push("createdAt must be an ISO-compatible timestamp");
  }

  return errors;
}

export function createAuditEntry({
  actorId,
  action,
  entityType,
  entityId,
  before = null,
  after = null,
  reason = "",
  metadata = {},
  createdAt = new Date().toISOString()
}){
  const entry = {
    actorId,
    action,
    entityType,
    entityId,
    before,
    after,
    reason,
    metadata,
    createdAt
  };
  const errors = validateAuditEntry(entry);

  if (errors.length > 0) {
    throw new Error(errors.join("; "));
  }

  return entry;
}
