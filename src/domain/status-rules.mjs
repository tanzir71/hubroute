export const PARCEL_STATUSES = [
  "requested",
  "assigned",
  "picked_up",
  "arrived_hub",
  "sorted",
  "in_transit",
  "out_for_delivery",
  "delivered",
  "failed_attempt",
  "hold",
  "rto_initiated",
  "returned"
];

export const TERMINAL_STATUSES = ["delivered", "returned"];

export const EVENT_TRANSITIONS = {
  created: {
    allowedFrom: [null],
    resultingStatus: "requested"
  },
  assigned: {
    allowedFrom: ["requested", "picked_up", "arrived_hub", "sorted", "failed_attempt", "hold"],
    resultingStatus: "assigned"
  },
  picked_up: {
    allowedFrom: ["requested", "assigned"],
    resultingStatus: "picked_up"
  },
  arrived_hub: {
    allowedFrom: ["picked_up", "in_transit", "departed_hub"],
    resultingStatus: "arrived_hub"
  },
  sorted: {
    allowedFrom: ["arrived_hub", "hold"],
    resultingStatus: "sorted"
  },
  departed_hub: {
    allowedFrom: ["sorted", "arrived_hub", "assigned"],
    resultingStatus: "in_transit"
  },
  out_for_delivery: {
    allowedFrom: ["arrived_hub", "sorted", "assigned"],
    resultingStatus: "out_for_delivery"
  },
  delivered: {
    allowedFrom: ["out_for_delivery"],
    resultingStatus: "delivered"
  },
  failed_attempt: {
    allowedFrom: ["out_for_delivery"],
    resultingStatus: "failed_attempt",
    requiredFields: ["reason"]
  },
  hold: {
    allowedFrom: ["*"],
    resultingStatus: "hold",
    requiredFields: ["reason"]
  },
  rto_initiated: {
    allowedFrom: ["failed_attempt", "hold"],
    resultingStatus: "rto_initiated"
  },
  returned: {
    allowedFrom: ["rto_initiated", "in_transit", "arrived_hub"],
    resultingStatus: "returned"
  },
  cod_collected: {
    allowedFrom: ["delivered"],
    resultingStatus: null
  },
  correction: {
    allowedFrom: ["*"],
    resultingStatus: "target"
  }
};

const statusSet = new Set(PARCEL_STATUSES);
const terminalSet = new Set(TERMINAL_STATUSES);

export function isKnownStatus(status){
  return statusSet.has(status);
}

export function isTerminalStatus(status){
  return terminalSet.has(status);
}

export function getTransition(eventKey){
  return EVENT_TRANSITIONS[eventKey] || null;
}

export function validateEventInput({ eventKey, currentStatus = null, targetStatus = null, payload = {} }){
  const transition = getTransition(eventKey);
  if (!transition) return { ok: false, error: `Unknown event: ${eventKey}` };

  if (currentStatus !== null && !isKnownStatus(currentStatus) && currentStatus !== "departed_hub") {
    return { ok: false, error: `Unknown current status: ${currentStatus}` };
  }

  if (eventKey !== "correction" && isTerminalStatus(currentStatus) && transition.resultingStatus !== null) {
    return { ok: false, error: `Terminal status ${currentStatus} can only be changed by correction` };
  }

  const isWildcard = transition.allowedFrom.includes("*");
  const isAllowed = isWildcard
    ? currentStatus !== null && (eventKey === "correction" || !isTerminalStatus(currentStatus))
    : transition.allowedFrom.includes(currentStatus);

  if (!isAllowed) {
    const from = currentStatus === null ? "none" : currentStatus;
    return { ok: false, error: `${eventKey} is not allowed from ${from}` };
  }

  for (const field of transition.requiredFields || []) {
    if (!payload[field]) return { ok: false, error: `${eventKey} requires ${field}` };
  }

  if (eventKey === "correction") {
    if (!targetStatus || !isKnownStatus(targetStatus)) {
      return { ok: false, error: "correction requires a known target status" };
    }
  }

  return { ok: true, error: "" };
}

export function nextParcelStatus({ eventKey, currentStatus = null, targetStatus = null, payload = {} }){
  const validation = validateEventInput({ eventKey, currentStatus, targetStatus, payload });
  if (!validation.ok) {
    throw new Error(validation.error);
  }

  const transition = getTransition(eventKey);
  if (transition.resultingStatus === null) return currentStatus;
  if (transition.resultingStatus === "target") return targetStatus;
  return transition.resultingStatus;
}
