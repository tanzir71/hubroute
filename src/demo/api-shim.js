import { STORAGE_KEY, seedState } from './data.js';

const COLLECTIONS = ['hubs', 'customers', 'riders', 'routes', 'parcels', 'events'];

function normalizeDemoState(stored){
  const seeded = seedState();
  const normalized = { ...seeded, ...stored, version: 5 };
  COLLECTIONS.forEach((key) => {
    normalized[key] = Array.isArray(stored?.[key]) ? stored[key] : [];
  });
  normalized.currentHubId = stored?.currentHubId || seeded.currentHubId;
  return normalized;
}

function storageErrorMessage(){
  return 'Browser storage is unavailable, so demo changes may not persist after refresh.';
}

export function loadDemoSnapshot(storage = localStorage){
  try {
    const stored = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
    if (stored && stored.version === 5) return { state: normalizeDemoState(stored), error: '' };
  } catch (_) {
    const seeded = seedState();
    try {
      storage.setItem(STORAGE_KEY, JSON.stringify(seeded));
    } catch (_) {
      return { state: seeded, error: storageErrorMessage() };
    }
    return { state: seeded, error: 'Stored demo data was unreadable, so HubRoute restored the seed dataset.' };
  }
  const seeded = seedState();
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(seeded));
  } catch (_) {
    return { state: seeded, error: storageErrorMessage() };
  }
  return { state: seeded, error: '' };
}

export function loadDemoState(storage = localStorage){
  return loadDemoSnapshot(storage).state;
}

export function persistDemoState(state, storage = localStorage){
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(state));
    return { ok: true, error: '' };
  } catch (_) {
    return { ok: false, error: storageErrorMessage() };
  }
}
