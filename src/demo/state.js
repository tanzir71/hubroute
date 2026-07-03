export function createFilters(){
  return {
  dashboard: { q: '', status: 'all' },
  parcels: { q: '', status: 'all', flow: 'all', routeId: 'all', riderId: 'all', customerId: 'all', cod: 'all', hubId: 'current' },
  routes: { q: '', type: 'all', status: 'all', riderId: 'all', capacity: 'all' },
  riders: { q: '', status: 'all', vehicle: 'all', load: 'all' },
  customers: { q: '', status: 'all', parcel: 'all' },
  scan: { q: '', status: 'all', eventType: 'all' },
  track: { q: '', status: 'all', customerId: 'all' }
  };
}

export function createSessionState({ state, accounts, location }){
  return {
    currentView: location.pathname.includes('/track') ? 'track' : 'login',
    signedIn: location.pathname.includes('/track'),
    selectedParcelId: state.parcels[0]?.id || '',
    selectedAccountEmail: accounts[0]?.email || '',
    trackingLookup: new URLSearchParams(location.search).get('code') || ''
  };
}
