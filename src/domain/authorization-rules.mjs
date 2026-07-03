export const ROLES = [
  "admin",
  "ops",
  "hub_staff",
  "agent",
  "merchant",
  "customer",
  "public"
];

export const AUTHZ_DECISIONS = {
  allow: "allow",
  scoped: "scoped",
  deny: "deny"
};

export const CAPABILITIES = {
  view_all_parcels: {
    admin: "allow",
    ops: "allow",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  view_hub_scoped_parcels: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  view_merchant_parcels: {
    admin: "allow",
    ops: "allow",
    hub_staff: "deny",
    agent: "deny",
    merchant: "scoped",
    customer: "scoped",
    public: "deny"
  },
  public_tracking_by_code: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "scoped",
    customer: "scoped",
    public: "scoped"
  },
  create_parcel: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "deny",
    merchant: "scoped",
    customer: "scoped",
    public: "deny"
  },
  update_parcel_metadata: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "deny",
    merchant: "scoped",
    customer: "deny",
    public: "deny"
  },
  capture_parcel_event: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  correct_terminal_custody_event: {
    admin: "allow",
    ops: "scoped",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  create_route_run: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  dispatch_close_run: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  collect_cod: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  create_remittance: {
    admin: "allow",
    ops: "allow",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  view_reports: {
    admin: "allow",
    ops: "allow",
    hub_staff: "scoped",
    agent: "scoped",
    merchant: "scoped",
    customer: "deny",
    public: "deny"
  },
  manage_users_roles: {
    admin: "allow",
    ops: "deny",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  manage_hubs_zones_rates_sla: {
    admin: "allow",
    ops: "scoped",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  },
  view_audit_log: {
    admin: "allow",
    ops: "allow",
    hub_staff: "deny",
    agent: "deny",
    merchant: "deny",
    customer: "deny",
    public: "deny"
  }
};

const roleSet = new Set(ROLES);
const decisionSet = new Set(Object.values(AUTHZ_DECISIONS));

export function getAuthorizationDecision(role, capability){
  if (!roleSet.has(role)) return AUTHZ_DECISIONS.deny;
  const decisions = CAPABILITIES[capability];
  if (!decisions) return AUTHZ_DECISIONS.deny;
  return decisions[role] || AUTHZ_DECISIONS.deny;
}

export function canPerform(role, capability){
  const decision = getAuthorizationDecision(role, capability);
  return {
    allowed: decision !== AUTHZ_DECISIONS.deny,
    decision,
    scopeRequired: decision === AUTHZ_DECISIONS.scoped
  };
}

export function validateAuthorizationMatrix(){
  const errors = [];

  for (const [capability, decisions] of Object.entries(CAPABILITIES)) {
    for (const role of ROLES) {
      if (!(role in decisions)) {
        errors.push(`${capability} is missing role ${role}`);
        continue;
      }
      if (!decisionSet.has(decisions[role])) {
        errors.push(`${capability}.${role} has invalid decision ${decisions[role]}`);
      }
    }
  }

  return errors;
}
