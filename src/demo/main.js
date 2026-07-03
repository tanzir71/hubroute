import { loadDemoSnapshot, persistDemoState } from './api-shim.js';
import { ACTIVE_STATUSES, DEMO_ACCOUNTS, EVENT_TYPES, STATUSES, seedState } from './data.js';
import { $, esc, filterCard, fmtDate, matchesQuery, money, normalized, options, pill, publicArea, redact, renderEmptyState, renderErrorState, renderLoadingState, selectOptions, statusClass, tableStateRow, uid } from './components.js';
import { createFilters, createSessionState } from './state.js';
import { renderCurrentView } from './views.js';

document.documentElement.classList.add('js');

const snapshot = loadDemoSnapshot();
let state = snapshot.state;
let appError = snapshot.error;
let appLoading = false;
const session = createSessionState({ state, accounts: DEMO_ACCOUNTS, location });
let { currentView, signedIn, selectedParcelId, selectedAccountEmail, trackingLookup } = session;
const filters = createFilters();

function saveState(){
  const result = persistDemoState(state);
  appError = result.ok ? '' : result.error;
  updateChrome();
}

function renderAppStatus(){
  if (appLoading) return renderLoadingState('Loading operations data', 'Preparing the console from the demo data source.');
  if (appError) return renderErrorState('Demo data warning', appError, '<button class="btn ghost slim" data-action="reset-demo" type="button">Restore seed data</button>');
  return '';
}

function updateChrome(){
  const hub = getHub(state.currentHubId);
  const account = currentAccount();
  $('currentHubName').textContent = hub?.name || 'Current hub';
  $('currentHubMeta').textContent = `${account?.role || 'Hub operator'} / ${state.riders.filter(r => r.hubId === state.currentHubId).length} riders / ${state.routes.filter(r => r.hubId === state.currentHubId).length} routes`;
  $('currentAccountMeta').innerHTML = `${esc(account?.email || 'demo hub account')}<br>Password: hub1234`;
  $('dataSummary').textContent = `${state.parcels.length} parcels, ${state.customers.length} customers`;
  const custodyPanel = $('custodyPanel');
  if (custodyPanel) custodyPanel.innerHTML = renderSidebarCustody();
}

function currentAccount(){ return DEMO_ACCOUNTS.find(account => account.hubId === state.currentHubId) || DEMO_ACCOUNTS[0]; }
function accountForHub(hubId){ return DEMO_ACCOUNTS.find(account => account.hubId === hubId); }
function getHub(id){ return state.hubs.find(h => h.id === id); }
function getCustomer(id){ return state.customers.find(c => c.id === id); }
function getRoute(id){ return state.routes.find(r => r.id === id); }
function getRider(id){ return state.riders.find(r => r.id === id); }
function getParcel(id){ return state.parcels.find(p => p.id === id); }
function hubName(id){ return getHub(id)?.name || 'Unknown hub'; }
function customerName(id){ return getCustomer(id)?.name || 'Archived customer'; }
function routeName(id){ return id ? (getRoute(id)?.name || 'Removed route') : 'Unassigned'; }
function riderName(id){ return id ? (getRider(id)?.name || 'Removed rider') : 'Unassigned'; }
function hubPath(parcel){
  return [parcel.pickupHubId, parcel.warehouseHubId, parcel.deliveryHubId].filter(Boolean).filter((hubId, index, rows) => index === 0 || hubId !== rows[index - 1]);
}
function hubTouchpoints(parcel){
  return [parcel.currentHubId, parcel.pendingToHubId, ...hubPath(parcel)].filter(Boolean);
}
function pathIndex(parcel){
  const path = hubPath(parcel);
  return Math.max(0, Math.min(Number(parcel.pathIndex || 0), Math.max(0, path.length - 1)));
}
function nextHubFor(parcel){
  if (!parcel || parcel.pendingToHubId || ['delivered','failed'].includes(parcel.status)) return null;
  const path = hubPath(parcel);
  return path[pathIndex(parcel) + 1] || null;
}
function flowState(parcel){
  if (parcel.pendingToHubId === state.currentHubId) return 'incoming';
  if (parcel.pendingToHubId && parcel.currentHubId === state.currentHubId) return 'sent';
  if (parcel.pendingToHubId) return 'in_transit';
  if (['delivered','failed'].includes(parcel.status)) return 'completed';
  if (parcel.currentHubId === state.currentHubId) return 'at_hub';
  return 'touchpoint';
}
function flowLabel(parcel){
  const flow = flowState(parcel);
  if (flow === 'incoming') return `Incoming from ${hubName(parcel.currentHubId)}`;
  if (flow === 'sent') return `Sent to ${hubName(parcel.pendingToHubId)}`;
  if (flow === 'in_transit') return `${hubName(parcel.currentHubId)} to ${hubName(parcel.pendingToHubId)}`;
  if (flow === 'completed') return 'Completed';
  if (flow === 'at_hub') return 'At current hub';
  return 'Network touchpoint';
}
function flowClass(parcel){
  const flow = flowState(parcel);
  if (flow === 'incoming') return 'green';
  if (flow === 'sent' || flow === 'in_transit') return 'amber';
  if (flow === 'completed') return statusClass(parcel.status);
  return 'blue';
}
function flowPill(parcel){ return `<span class="pill ${flowClass(parcel)}">${esc(flowLabel(parcel))}</span>`; }
function activeSidebarParcel(){
  const selected = getParcel(selectedParcelId);
  if (selected && hubTouchpoints(selected).includes(state.currentHubId)) return selected;
  const visible = visibleParcels();
  const incoming = visible.find(p => p.pendingToHubId === state.currentHubId);
  const local = visible.find(p => p.currentHubId === state.currentHubId && !p.pendingToHubId && !['delivered','failed'].includes(p.status));
  const parcel = incoming || local || visible[0] || state.parcels[0];
  if (parcel) selectedParcelId = parcel.id;
  return parcel || null;
}
function pathStepState(parcel, hubId, index){
  const currentIndex = pathIndex(parcel);
  if (parcel.pendingToHubId === hubId) return 'incoming';
  if (!parcel.pendingToHubId && index === currentIndex) return 'active';
  if (index < currentIndex || parcel.currentHubId === hubId && parcel.pendingToHubId) return 'done';
  return 'future';
}
function pathStepMeta(parcel, hubId, index){
  if (hubId === state.currentHubId) return 'You are operating this hub';
  if (parcel.pendingToHubId === hubId) return `Waiting to be received at ${hubName(hubId)}`;
  if (parcel.currentHubId === hubId && parcel.pendingToHubId) return `Departed for ${hubName(parcel.pendingToHubId)}`;
  if (index < pathIndex(parcel)) return 'Completed handoff';
  return accountForHub(hubId) ? 'Click to operate this hub' : 'No demo account for this hub';
}
function renderSidebarCustody(){
  const parcel = activeSidebarParcel();
  if (!parcel) {
    return `<div class="custody-panel">
      <p class="eyebrow">Active custody path</p>
      ${renderEmptyState('No parcel selected', 'Create or receive a parcel to see its hub custody path here.', '<button class="btn ghost slim" data-action="new-parcel" type="button">New parcel</button>')}
    </div>`;
  }
  const path = hubPath(parcel);
  return `<div class="custody-panel">
    <div>
      <p class="eyebrow">Active custody path</p>
      <h3><button class="text-action" data-action="focus-parcel" data-id="${esc(parcel.id)}" type="button">${esc(parcel.code)}</button></h3>
      <p class="muted small">${esc(customerName(parcel.customerId))} / ${esc(parcel.status.replaceAll('_',' '))}</p>
    </div>
    <div class="path-list" aria-label="Selected parcel hub path">
      ${path.map((hubId, index) => {
        const stepState = pathStepState(parcel, hubId, index);
        const active = hubId === state.currentHubId ? ' active' : '';
        return `<button class="path-step ${stepState}${active}" data-action="operate-hub" data-hub-id="${esc(hubId)}" type="button">
          <span class="path-dot" aria-hidden="true"></span>
          <span class="path-main">
            <strong>${index + 1}. ${esc(hubName(hubId))}</strong>
            <span class="path-meta">${esc(pathStepMeta(parcel, hubId, index))}</span>
          </span>
        </button>`;
      }).join('')}
    </div>
    ${flowPill(parcel)}
    <div class="actions">
      ${canReceive(parcel) ? `<button class="btn primary slim" data-action="receive-parcel" data-id="${esc(parcel.id)}" type="button">Receive here</button>` : ''}
      ${canSend(parcel) ? `<button class="btn slim" data-action="send-parcel" data-id="${esc(parcel.id)}" type="button">Send next</button>` : ''}
      <button class="btn ghost slim" data-action="assign-parcel" data-id="${esc(parcel.id)}" type="button">Assign</button>
      <button class="btn ghost slim" data-action="track-parcel" data-id="${esc(parcel.id)}" type="button">Track</button>
    </div>
  </div>`;
}
function hubCounts(hubId = state.currentHubId){
  const rows = visibleParcelsForHub(hubId);
  return {
    visible: rows.length,
    atHub: rows.filter(p => !p.pendingToHubId && p.currentHubId === hubId && !['delivered','failed'].includes(p.status)).length,
    incoming: rows.filter(p => p.pendingToHubId === hubId).length,
    sent: rows.filter(p => p.currentHubId === hubId && p.pendingToHubId).length,
    completed: rows.filter(p => ['delivered','failed'].includes(p.status) && hubTouchpoints(p).includes(hubId)).length
  };
}
function findParcelByCode(code){
  const wanted = normalized(code).replace(/\s+/g, '');
  if (!wanted) return null;
  return state.parcels.find(parcel => normalized(parcel.code).replace(/\s+/g, '') === wanted) || null;
}
function setTrackingLookup(code){
  trackingLookup = String(code || '').trim().toUpperCase();
  const parcel = findParcelByCode(trackingLookup);
  if (parcel) selectedParcelId = parcel.id;
  if ((currentView === 'track' || location.pathname.includes('/track')) && typeof history !== 'undefined' && history.replaceState) {
    const suffix = trackingLookup ? `?code=${encodeURIComponent(trackingLookup)}` : '';
    history.replaceState(null, '', `/track${suffix}`);
  }
}
function activeParcels(){ return state.parcels.filter(p => ACTIVE_STATUSES.includes(p.status)); }
function routeLoad(routeId, excludeParcelId = ''){ return activeParcels().filter(p => p.routeId === routeId && p.id !== excludeParcelId).length; }
function riderLoad(riderId, excludeParcelId = ''){ return activeParcels().filter(p => p.riderId === riderId && p.id !== excludeParcelId).length; }
function visibleParcelsForHub(hubId){
  return state.parcels.filter((p) => {
    const route = getRoute(p.routeId);
    const rider = getRider(p.riderId);
    return hubTouchpoints(p).includes(hubId) ||
      route?.hubId === hubId ||
      rider?.hubId === hubId;
  });
}
function visibleParcels(){ return visibleParcelsForHub(state.currentHubId); }
function toast(msg){
  const el = $('toast');
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => el.classList.remove('show'), 2400);
}
function newTrackingCode(){
  const existingCodes = new Set(state.parcels.map(p => p.code));
  const d = new Date();
  const y = String(d.getFullYear()).slice(2);
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for (let attempt = 0; attempt < 20; attempt++) {
    let suffix = '';
    for (let i = 0; i < 8; i++) {
      suffix += alphabet[Math.floor(Math.random() * alphabet.length)];
    }
    const code = 'HR' + y + m + day + suffix;
    if (!existingCodes.has(code)) return code;
  }
  return 'HR' + y + m + day + uid('trk').replace(/[^A-Z0-9]/gi, '').slice(0, 8).toUpperCase();
}
function addEvent(parcelId, type, hubId, note){
  state.events.unshift({ id: uid('evt'), parcelId, type, hubId, note, at: new Date().toISOString() });
}
function filterValue(scope, key){ return filters[scope]?.[key] ?? ''; }
function filterInput(scope, key, label, placeholder){
  return `<label>${esc(label)}<input data-filter-scope="${esc(scope)}" data-filter-key="${esc(key)}" value="${esc(filterValue(scope, key))}" placeholder="${esc(placeholder)}"></label>`;
}
function filterSelect(scope, key, label, optionsHtml){
  return `<label>${esc(label)}<select data-filter-scope="${esc(scope)}" data-filter-key="${esc(key)}">${optionsHtml}</select></label>`;
}
function statusOptions(selected, label = 'All statuses'){
  return selectOptions(STATUSES.map(s => ({ value: s, label: s.replaceAll('_',' ') })), selected, label);
}
function hubOptions(selected, label = 'All hubs'){
  return selectOptions(state.hubs.map(h => ({ value: h.id, label: h.name })), selected, label);
}
function routeOptions(selected, rows = state.routes, label = 'All routes'){
  return selectOptions(rows.map(r => ({ value: r.id, label: r.name })), selected, label);
}
function riderOptions(selected, rows = state.riders, label = 'All riders'){
  return selectOptions(rows.map(r => ({ value: r.id, label: r.name })), selected, label);
}
function customerOptions(selected, rows = state.customers, label = 'All customers'){
  return selectOptions(rows.map(c => ({ value: c.id, label: c.name })), selected, label);
}
function vehicleOptions(selected, rows = state.riders){
  const vehicles = [...new Set(rows.map(r => r.vehicle).filter(Boolean))].sort();
  return selectOptions(vehicles.map(v => ({ value: v, label: v })), selected, 'All vehicles');
}
function parcelSearchText(p){
  return [
    p.code, p.status, customerName(p.customerId), getCustomer(p.customerId)?.phone, getCustomer(p.customerId)?.email,
    p.pickupAddress, p.dropoffAddress, flowLabel(p), hubName(p.currentHubId), hubName(p.pendingToHubId), hubName(p.pickupHubId), hubName(p.warehouseHubId), hubName(p.deliveryHubId),
    routeName(p.routeId), riderName(p.riderId), p.weightKg, p.notes, p.cod ? 'cod cash collection' : 'prepaid no cod'
  ].join(' ');
}
function routeSearchText(route){
  return [route.name, route.type, route.status, route.areas, hubName(route.hubId), riderName(route.riderId), route.capacity].join(' ');
}
function riderSearchText(rider){
  return [rider.name, rider.phone, rider.vehicle, rider.status, hubName(rider.hubId), rider.capacity, `${riderLoad(rider.id)} active rides`].join(' ');
}
function customerSearchText(customer){
  return [customer.name, customer.phone, customer.email, customer.address, customer.status, `${state.parcels.filter(p => p.customerId === customer.id).length} parcels`].join(' ');
}
function eventSearchText(event){
  const parcel = getParcel(event.parcelId);
  return [event.type, event.note, hubName(event.hubId), parcel?.code, parcel ? customerName(parcel.customerId) : '', parcel?.pickupAddress, parcel?.dropoffAddress].join(' ');
}
function filterParcels(rows, scope = 'parcels'){
  const f = filters[scope];
  const status = f.status || 'all';
  const routeId = f.routeId || 'all';
  const riderId = f.riderId || 'all';
  const customerId = f.customerId || 'all';
  const cod = f.cod || 'all';
  const hubId = f.hubId || 'all';
  const flow = f.flow || 'all';
  return rows.filter(p => {
    if (!matchesQuery(parcelSearchText(p), f.q)) return false;
    if (status !== 'all' && p.status !== status) return false;
    if (flow !== 'all' && flowState(p) !== flow) return false;
    if (routeId !== 'all' && p.routeId !== routeId) return false;
    if (riderId !== 'all' && p.riderId !== riderId) return false;
    if (customerId !== 'all' && p.customerId !== customerId) return false;
    if (cod === 'cod' && !p.cod) return false;
    if (cod === 'prepaid' && p.cod) return false;
    if (hubId === 'current' && !hubTouchpoints(p).includes(state.currentHubId)) return false;
    if (hubId && hubId !== 'all' && hubId !== 'current' && !hubTouchpoints(p).includes(hubId)) return false;
    return true;
  });
}
function filterRoutes(rows, scope = 'routes'){
  const f = filters[scope];
  const type = f.type || 'all';
  const status = f.status || 'all';
  const riderId = f.riderId || 'all';
  const capacity = f.capacity || 'all';
  return rows.filter(route => {
    const load = routeLoad(route.id);
    if (!matchesQuery(routeSearchText(route), f.q)) return false;
    if (type !== 'all' && route.type !== type) return false;
    if (status !== 'all' && route.status !== status) return false;
    if (riderId !== 'all' && route.riderId !== riderId) return false;
    if (capacity === 'available' && load >= Number(route.capacity || 0)) return false;
    if (capacity === 'busy' && load < Math.max(1, Number(route.capacity || 0) * .7)) return false;
    return true;
  });
}
function filterRiders(rows, scope = 'riders'){
  const f = filters[scope];
  return rows.filter(rider => {
    const load = riderLoad(rider.id);
    if (!matchesQuery(riderSearchText(rider), f.q)) return false;
    if (f.status !== 'all' && rider.status !== f.status) return false;
    if (f.vehicle !== 'all' && rider.vehicle !== f.vehicle) return false;
    if (f.load === 'free' && load !== 0) return false;
    if (f.load === 'available' && load >= Number(rider.capacity || 0)) return false;
    if (f.load === 'busy' && load < Math.max(1, Number(rider.capacity || 0) * .7)) return false;
    return true;
  });
}
function filterCustomers(rows, scope = 'customers'){
  const f = filters[scope];
  return rows.filter(customer => {
    const count = state.parcels.filter(p => p.customerId === customer.id).length;
    if (!matchesQuery(customerSearchText(customer), f.q)) return false;
    if (f.status !== 'all' && customer.status !== f.status) return false;
    if (f.parcel === 'with_parcels' && count === 0) return false;
    if (f.parcel === 'no_parcels' && count > 0) return false;
    if (f.parcel === 'cod' && !state.parcels.some(p => p.customerId === customer.id && p.cod)) return false;
    return true;
  });
}
function filterEvents(rows, scope = 'scan'){
  const f = filters[scope];
  const status = f.status || 'all';
  const eventType = f.eventType || 'all';
  return rows.filter(event => {
    const parcel = getParcel(event.parcelId);
    if (!matchesQuery(eventSearchText(event), f.q)) return false;
    if (eventType !== 'all' && event.type !== eventType) return false;
    if (status !== 'all' && parcel?.status !== status) return false;
    return true;
  });
}
function resetFilters(scope){
  Object.keys(filters[scope]).forEach(key => { filters[scope][key] = key === 'hubId' ? 'current' : 'all'; });
  if ('q' in filters[scope]) filters[scope].q = '';
  if (scope === 'dashboard') filters.dashboard.status = 'all';
  render();
}
function filterElement(scope, key){
  return Array.from(document.querySelectorAll('[data-filter-scope][data-filter-key]')).find(el => el.dataset.filterScope === scope && el.dataset.filterKey === key);
}
function updateFilterFromElement(el, restoreFocus = false){
  const scope = el.dataset.filterScope;
  const key = el.dataset.filterKey;
  if (!filters[scope] || !(key in filters[scope])) return;
  const cursor = typeof el.selectionStart === 'number' ? el.selectionStart : null;
  filters[scope][key] = el.value;
  render();
  if (restoreFocus) {
    const next = filterElement(scope, key);
    next?.focus();
    if (next && cursor !== null && typeof next.setSelectionRange === 'function') {
      next.setSelectionRange(cursor, cursor);
    }
  }
}

function setView(view){
  currentView = view;
  if (view === 'login') {
    signedIn = false;
    $('app').classList.add('hidden');
    $('login').classList.remove('hidden');
  } else {
    $('login').classList.add('hidden');
    $('app').classList.remove('hidden');
  }
  $('app').classList.toggle('public-layout', view === 'track');
  document.querySelector('.topbar')?.classList.toggle('public-mode', view === 'track');
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.view === view));
  render();
}

function render(){
  updateChrome();
  if (currentView === 'login') {
    renderAccountCards();
    return;
  }
  renderCurrentView({
    root: $('view'),
    currentView,
    renderers: { renderDashboard, renderParcels, renderRoutes, renderRiders, renderCustomers, renderScan, renderTracking },
    enhanceSelects,
    prefixHtml: renderAppStatus()
  });
}

function accountSearchText(account){
  const hub = getHub(account.hubId);
  return [account.email, account.role, hub?.name, hub?.city, hub?.type].join(' ');
}
function renderAccountCards(){
  const status = $('loginStatus');
  if (status) status.innerHTML = renderAppStatus();
  const root = $('accountCards');
  if (!root) return;
  const query = $('accountSearch')?.value || '';
  const accounts = DEMO_ACCOUNTS.filter(account => matchesQuery(accountSearchText(account), query));
  root.innerHTML = accounts.map(account => {
    const hub = getHub(account.hubId);
    const counts = hubCounts(account.hubId);
    const active = account.email === selectedAccountEmail;
    return `<article class="account-card ${active ? 'active' : ''}">
      <div><strong>${esc(hub?.name || account.email)}</strong><span class="muted small">${esc(account.role)} / ${esc(hub?.city || 'Bangladesh')}</span></div>
      <p class="muted small">${counts.atHub} at hub / ${counts.incoming} incoming / ${counts.sent} sent</p>
      <button class="btn ghost slim" data-action="use-account" data-email="${esc(account.email)}" type="button">${active ? 'Selected' : 'Use account'}</button>
    </article>`;
      }).join('') || renderEmptyState('No demo accounts match', 'Clear the search to see the seeded hub operator accounts.');
}
function chooseAccount(email){
  const account = DEMO_ACCOUNTS.find(row => row.email === email);
  if (!account) return;
  selectedAccountEmail = account.email;
  $('email').value = account.email;
  $('password').value = account.password;
  renderAccountCards();
}
function operateHub(hubId){
  const account = accountForHub(hubId);
  if (!account) return toast('No demo account is seeded for that hub');
  selectedAccountEmail = account.email;
  signedIn = true;
  state.currentHubId = hubId;
  saveState();
  setView(currentView === 'login' || currentView === 'track' ? 'dashboard' : currentView);
  toast(`Operating ${hubName(hubId)}`);
}
function focusParcel(id){
  const parcel = getParcel(id);
  if (!parcel) return toast('Parcel not found');
  selectedParcelId = parcel.id;
  render();
  toast(`${parcel.code} path focused`);
}
function trackParcel(id){
  const parcel = getParcel(id);
  if (!parcel) return toast('Parcel not found');
  selectedParcelId = parcel.id;
  setView('track');
  setTrackingLookup(parcel.code);
  render();
}

function renderKpis(rows = visibleParcels()){
  const parcels = rows;
  const requested = parcels.filter(p => p.status === 'requested').length;
  const assigned = parcels.filter(p => p.status === 'assigned').length;
  const motion = parcels.filter(p => ['picked_up','in_transit','in_warehouse','out_for_delivery'].includes(p.status)).length;
  const cod = parcels.filter(p => p.cod).length;
  return `<div class="grid four">
    <div class="kpi"><strong>${requested}</strong><span class="muted">requested</span></div>
    <div class="kpi"><strong>${assigned}</strong><span class="muted">assigned</span></div>
    <div class="kpi"><strong>${motion}</strong><span class="muted">in motion</span></div>
    <div class="kpi"><strong>${cod}</strong><span class="muted">COD parcels</span></div>
  </div>`;
}

function actionButton(action, label, extra = ''){
  return `<button class="btn ghost slim" data-action="${esc(action)}" type="button" ${extra}>${esc(label)}</button>`;
}

function emptyTableRow(colspan, title, body, actions = ''){
  return tableStateRow(colspan, renderEmptyState(title, body, actions));
}

function renderHubNetwork(){
  if (!state.hubs.length) {
    return `<div class="card">
      <div class="card-head"><div><h2>Hub-and-spoke custody</h2><p class="muted small">Switch accounts to receive incoming parcels and send them onward through pickup, sortation, and delivery hubs.</p></div><button class="btn ghost slim" data-view-jump="login">Switch hub</button></div>
      ${renderEmptyState('No hubs configured', 'Add a hub before dispatch, receive, or route assignment can be demonstrated.', actionButton('new-route', 'Create route'))}
    </div>`;
  }
  return `<div class="card">
    <div class="card-head"><div><h2>Hub-and-spoke custody</h2><p class="muted small">Switch accounts to receive incoming parcels and send them onward through pickup, sortation, and delivery hubs.</p></div><button class="btn ghost slim" data-view-jump="login">Switch hub</button></div>
    <div class="hub-network">
      ${state.hubs.map(hub => {
        const counts = hubCounts(hub.id);
        return `<article class="hub-node-card ${hub.id === state.currentHubId ? 'active' : ''}">
          <strong>${esc(hub.name)}</strong>
          <span class="muted small">${esc(hub.city)} / ${esc(hub.type)}</span>
          <div class="metric-row">
            <div class="metric"><span>At hub</span><strong>${counts.atHub}</strong></div>
            <div class="metric"><span>Incoming</span><strong>${counts.incoming}</strong></div>
          </div>
          <p class="flow-note">${counts.sent} outbound handoffs / ${counts.completed} closed</p>
        </article>`;
      }).join('')}
    </div>
  </div>`;
}

function canSend(parcel){
  return parcel.currentHubId === state.currentHubId && !parcel.pendingToHubId && Boolean(nextHubFor(parcel));
}
function canReceive(parcel){
  return parcel.pendingToHubId === state.currentHubId;
}
function renderParcelFlow(parcel){
  const path = hubPath(parcel);
  const currentIndex = pathIndex(parcel);
  const labels = path.map((hubId, index) => {
    let marker = 'future';
    if (parcel.pendingToHubId === hubId) marker = 'incoming';
    else if (index === currentIndex) marker = 'current';
    else if (index < currentIndex) marker = 'done';
    return `${index + 1}. ${hubName(hubId)} (${marker})`;
  });
  const pending = parcel.pendingToHubId ? `<span class="muted small">Pending receive: ${esc(hubName(parcel.pendingToHubId))}</span>` : '';
  return `<div class="handoff-path">${flowPill(parcel)}<span class="muted small">${esc(labels.join(' -> '))}</span>${pending}</div>`;
}
function parcelActionButtons(parcel){
  const buttons = [];
  if (canReceive(parcel)) buttons.push(`<button class="btn primary slim" data-action="receive-parcel" data-id="${esc(parcel.id)}">Receive</button>`);
  if (canSend(parcel)) buttons.push(`<button class="btn slim" data-action="send-parcel" data-id="${esc(parcel.id)}">Send</button>`);
  buttons.push(`<button class="btn ghost slim" data-action="assign-parcel" data-id="${esc(parcel.id)}">Assign</button>`);
  buttons.push(`<button class="btn ghost slim" data-action="edit-parcel" data-id="${esc(parcel.id)}">Edit</button>`);
  buttons.push(`<button class="btn danger slim" data-action="delete-parcel" data-id="${esc(parcel.id)}">Delete</button>`);
  return buttons.join('');
}

function renderDashboard(){
  const q = filters.dashboard.q;
  const status = filters.dashboard.status;
  const dashboardParcels = visibleParcels().filter(p => matchesQuery(parcelSearchText(p), q) && (status === 'all' || p.status === status));
  const hubRoutes = state.routes.filter(r => r.hubId === state.currentHubId && matchesQuery(routeSearchText(r), q));
  const hubRiders = state.riders.filter(r => r.hubId === state.currentHubId && matchesQuery(riderSearchText(r), q));
  const recent = state.events.filter(event => {
    const parcel = getParcel(event.parcelId);
    return matchesQuery(eventSearchText(event), q) && (status === 'all' || parcel?.status === status);
  }).slice(0, 6);
  return `
    <div class="view-head">
      <div><p class="eyebrow">Operations</p><h1>${esc(hubName(state.currentHubId))}</h1></div>
      <div class="actions"><button class="btn primary" data-action="new-parcel">New parcel</button><button class="btn" data-action="new-route">New route</button></div>
    </div>
    ${filterCard('dashboard', 'Search operations', 'Search across parcel codes, Dhaka addresses, customers, riders, routes, and event notes.', [
      filterInput('dashboard', 'q', 'Search all ops data', 'Banani, COD, Amina, HR2607...'),
      filterSelect('dashboard', 'status', 'Parcel status', statusOptions(filters.dashboard.status))
    ].join(''), visibleParcels().length, dashboardParcels.length, 'two')}
    ${renderKpis(dashboardParcels)}
    ${renderHubNetwork()}
    <div class="grid two">
      <div class="card">
        <div class="card-head"><h2>Route capacity</h2><button class="btn ghost slim" data-view-jump="routes">Routes</button></div>
        <div class="grid">
          ${hubRoutes.map(route => `<div class="metric-row">
            <div class="metric"><span>${esc(route.name)}</span><strong>${routeLoad(route.id)} / ${Number(route.capacity || 0)}</strong></div>
            <div class="metric"><span>Rider</span><strong>${esc(riderName(route.riderId))}</strong></div>
          </div>`).join('') || renderEmptyState('No routes at this hub', 'Create a route to start assigning pickup, linehaul, or last-mile capacity.', actionButton('new-route', 'New route'))}
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2>Rider workload</h2><button class="btn ghost slim" data-view-jump="riders">Riders</button></div>
        <div class="grid">
          ${hubRiders.map(rider => `<div class="metric-row">
            <div class="metric"><span>${esc(rider.name)}</span><strong>${riderLoad(rider.id)} active rides</strong></div>
            <div class="metric"><span>Vehicle</span><strong>${esc(rider.vehicle)}</strong></div>
          </div>`).join('') || renderEmptyState('No riders at this hub', 'Create a rider before route workload can be dispatched.', actionButton('new-rider', 'New rider'))}
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Recent movement</h2><button class="btn ghost slim" data-view-jump="scan">Scan/Event</button></div>
      ${renderEventList(recent)}
    </div>`;
}

function renderParcels(){
  const baseParcels = visibleParcels();
  const parcels = filterParcels(baseParcels, 'parcels');
  const hubSelect = `<option value="current" ${filters.parcels.hubId === 'current' ? 'selected' : ''}>Current hub touchpoints</option><option value="all" ${filters.parcels.hubId === 'all' ? 'selected' : ''}>All hubs</option>` + state.hubs.map(h => `<option value="${esc(h.id)}" ${filters.parcels.hubId === h.id ? 'selected' : ''}>${esc(h.name)}</option>`).join('');
  const flowSelect = selectOptions([
    {value:'at_hub',label:'At current hub'},
    {value:'incoming',label:'Incoming to current hub'},
    {value:'sent',label:'Sent from current hub'},
    {value:'in_transit',label:'In transit elsewhere'},
    {value:'touchpoint',label:'Other network touchpoint'},
    {value:'completed',label:'Completed or failed'}
  ], filters.parcels.flow, 'All custody states');
  return `
    <div class="view-head">
      <div><p class="eyebrow">Current hub queue</p><h1>Parcels</h1></div>
      <div class="actions"><button class="btn primary" data-action="new-parcel">New parcel</button></div>
    </div>
    ${filterCard('parcels', 'Search and filter parcels', 'Filter by every operational field: tracking, customer, phone, address, hub, route, rider, COD, status, and notes.', [
      filterInput('parcels', 'q', 'Search all parcel fields', 'Dhanmondi, +88017, COD, route, rider...'),
      filterSelect('parcels', 'status', 'Status', statusOptions(filters.parcels.status)),
      filterSelect('parcels', 'flow', 'Custody flow', flowSelect),
      filterSelect('parcels', 'routeId', 'Route', routeOptions(filters.parcels.routeId, state.routes.filter(r => r.hubId === state.currentHubId), 'All current-hub routes')),
      filterSelect('parcels', 'riderId', 'Rider', riderOptions(filters.parcels.riderId, state.riders.filter(r => r.hubId === state.currentHubId), 'All current-hub riders')),
      filterSelect('parcels', 'customerId', 'Customer', customerOptions(filters.parcels.customerId)),
      filterSelect('parcels', 'cod', 'Payment', selectOptions([{value:'cod',label:'COD only'},{value:'prepaid',label:'Prepaid only'}], filters.parcels.cod, 'All payments')),
      filterSelect('parcels', 'hubId', 'Hub scope', hubSelect)
    ].join(''), baseParcels.length, parcels.length)}
    ${renderKpis(parcels)}
    <div class="card">
      <div class="toolbar">
        <div><h2>Hub parcel queue</h2><p class="muted small">Visible from pickup, warehouse, delivery, current hub, route, or rider ownership.</p></div>
      </div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tracking</th><th>Customer</th><th>Current hub</th><th>Flow</th><th>Route</th><th>Rider</th><th>COD</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${parcels.length ? parcels.map(p => `<tr>
            <td><div class="row-title"><button class="text-action" data-action="focus-parcel" data-id="${esc(p.id)}" type="button">${esc(p.code)}</button><span class="muted small">${esc(p.pickupAddress)} to ${esc(p.dropoffAddress)}</span></div></td>
            <td>${esc(customerName(p.customerId))}</td>
            <td>${esc(hubName(p.currentHubId))}</td>
            <td>${renderParcelFlow(p)}</td>
            <td>${esc(routeName(p.routeId))}</td>
            <td>${esc(riderName(p.riderId))}</td>
            <td>${p.cod ? money(p.amountCents) : money(0)}</td>
            <td>${pill(p.status)}</td>
            <td><div class="actions">${parcelActionButtons(p)}</div></td>
          </tr>`).join('') : emptyTableRow(9, baseParcels.length ? 'No parcels match these filters' : 'No parcels in this queue', baseParcels.length ? 'Adjust or reset filters to return parcels to the table.' : 'Create a parcel or switch hubs to populate this queue.', baseParcels.length ? '<button class="btn ghost slim" data-action="reset-filters" data-scope="parcels" type="button">Reset filters</button>' : actionButton('new-parcel', 'New parcel'))}
        </tbody>
      </table>
    </div>`;
}

function renderRoutes(){
  const baseRoutes = state.routes.filter(r => r.hubId === state.currentHubId);
  const routes = filterRoutes(baseRoutes, 'routes');
  const routeTypes = [...new Set(baseRoutes.map(r => r.type))].map(type => ({ value: type, label: type }));
  return `
    <div class="view-head">
      <div><p class="eyebrow">Current hub routes</p><h1>Routes</h1></div>
      <div class="actions"><button class="btn primary" data-action="new-route">New route</button></div>
    </div>
    ${filterCard('routes', 'Search and filter routes', 'Find routes by service area, rider, type, status, capacity, and hub coverage.', [
      filterInput('routes', 'q', 'Search route fields', 'Banani, Tejgaon, bulk, Karim...'),
      filterSelect('routes', 'type', 'Type', selectOptions(routeTypes, filters.routes.type, 'All route types')),
      filterSelect('routes', 'status', 'Status', selectOptions(['active','paused','inactive'].map(s => ({value:s,label:s})), filters.routes.status, 'All statuses')),
      filterSelect('routes', 'riderId', 'Default rider', riderOptions(filters.routes.riderId, state.riders.filter(r => r.hubId === state.currentHubId), 'All current-hub riders')),
      filterSelect('routes', 'capacity', 'Capacity', selectOptions([{value:'available',label:'Has capacity'},{value:'busy',label:'70%+ busy'}], filters.routes.capacity, 'All capacity'))
    ].join(''), baseRoutes.length, routes.length)}
    <div class="grid two">
      ${routes.map(route => `<article class="card">
        <div class="card-head"><h2>${esc(route.name)}</h2>${pill(route.status)}</div>
        <p class="muted">${esc(route.areas || 'No service areas set')}</p>
        <div class="metric-row">
          <div class="metric"><span>Load</span><strong>${routeLoad(route.id)} / ${Number(route.capacity || 0)}</strong></div>
          <div class="metric"><span>Assigned rider</span><strong>${esc(riderName(route.riderId))}</strong></div>
          <div class="metric"><span>Route type</span><strong>${esc(route.type)}</strong></div>
          <div class="metric"><span>Hub</span><strong>${esc(hubName(route.hubId))}</strong></div>
        </div>
        <div class="actions"><button class="btn ghost slim" data-action="edit-route" data-id="${esc(route.id)}">Edit</button><button class="btn danger slim" data-action="delete-route" data-id="${esc(route.id)}">Delete</button></div>
      </article>`).join('') || renderEmptyState(baseRoutes.length ? 'No routes match these filters' : 'No routes at this hub', baseRoutes.length ? 'Adjust or reset filters to see the current hub routes again.' : 'Create a route before parcels can be assigned to hub capacity.', baseRoutes.length ? '<button class="btn ghost slim" data-action="reset-filters" data-scope="routes" type="button">Reset filters</button>' : actionButton('new-route', 'New route'))}
    </div>`;
}

function renderRiders(){
  const baseRiders = state.riders.filter(r => r.hubId === state.currentHubId);
  const riders = filterRiders(baseRiders, 'riders');
  return `
    <div class="view-head">
      <div><p class="eyebrow">Current hub riders</p><h1>Riders</h1></div>
      <div class="actions"><button class="btn primary" data-action="new-rider">New rider</button></div>
    </div>
    ${filterCard('riders', 'Search and filter riders', 'Filter riders by name, +880 phone, vehicle, status, and active ride load.', [
      filterInput('riders', 'q', 'Search rider fields', 'Amina, +88018, van, free...'),
      filterSelect('riders', 'status', 'Status', selectOptions(['active','off_duty','inactive'].map(s => ({value:s,label:s.replaceAll('_',' ')})), filters.riders.status, 'All statuses')),
      filterSelect('riders', 'vehicle', 'Vehicle', vehicleOptions(filters.riders.vehicle, baseRiders)),
      filterSelect('riders', 'load', 'Ride load', selectOptions([{value:'free',label:'No active rides'},{value:'available',label:'Has capacity'},{value:'busy',label:'70%+ busy'}], filters.riders.load, 'All loads'))
    ].join(''), baseRiders.length, riders.length, 'three')}
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Phone</th><th>Vehicle</th><th>Capacity</th><th>Active rides</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${riders.length ? riders.map(r => `<tr>
            <td><strong>${esc(r.name)}</strong><br><span class="muted small">${esc(hubName(r.hubId))}</span></td>
            <td>${esc(r.phone)}</td>
            <td>${esc(r.vehicle)}</td>
            <td>${Number(r.capacity || 0)}</td>
            <td>${riderLoad(r.id)}</td>
            <td>${pill(r.status)}</td>
            <td><div class="actions"><button class="btn ghost slim" data-action="edit-rider" data-id="${esc(r.id)}">Edit</button><button class="btn danger slim" data-action="delete-rider" data-id="${esc(r.id)}">Delete</button></div></td>
          </tr>`).join('') : emptyTableRow(7, baseRiders.length ? 'No riders match these filters' : 'No riders at this hub', baseRiders.length ? 'Adjust or reset filters to return riders to the table.' : 'Create a rider before route assignments can show workload.', baseRiders.length ? '<button class="btn ghost slim" data-action="reset-filters" data-scope="riders" type="button">Reset filters</button>' : actionButton('new-rider', 'New rider'))}
        </tbody>
      </table>
    </div>`;
}

function renderCustomers(){
  const customers = filterCustomers(state.customers, 'customers');
  return `
    <div class="view-head">
      <div><p class="eyebrow">Customer directory</p><h1>Customers</h1></div>
      <div class="actions"><button class="btn primary" data-action="new-customer">New customer</button></div>
    </div>
    ${filterCard('customers', 'Search and filter customers', 'Search merchant name, +880 phone, email, address, parcel count, COD history, and status.', [
      filterInput('customers', 'q', 'Search customer fields', 'Gazipur, +88019, fashion, email...'),
      filterSelect('customers', 'status', 'Status', selectOptions(['active','inactive'].map(s => ({value:s,label:s})), filters.customers.status, 'All statuses')),
      filterSelect('customers', 'parcel', 'Parcel history', selectOptions([{value:'with_parcels',label:'Has parcels'},{value:'no_parcels',label:'No parcels'},{value:'cod',label:'Has COD parcels'}], filters.customers.parcel, 'All customers'))
    ].join(''), state.customers.length, customers.length, 'three')}
    <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Contact</th><th>Address</th><th>Parcels</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          ${customers.length ? customers.map(c => `<tr>
            <td><strong>${esc(c.name)}</strong></td>
            <td>${esc(c.phone)}<br><span class="muted small">${esc(c.email)}</span></td>
            <td>${esc(c.address)}</td>
            <td>${state.parcels.filter(p => p.customerId === c.id).length}</td>
            <td>${pill(c.status)}</td>
            <td><div class="actions"><button class="btn ghost slim" data-action="edit-customer" data-id="${esc(c.id)}">Edit</button><button class="btn danger slim" data-action="delete-customer" data-id="${esc(c.id)}">Delete</button></div></td>
          </tr>`).join('') : emptyTableRow(6, state.customers.length ? 'No customers match these filters' : 'No customers yet', state.customers.length ? 'Adjust or reset filters to return customers to the table.' : 'Create a customer before booking merchant parcels.', state.customers.length ? '<button class="btn ghost slim" data-action="reset-filters" data-scope="customers" type="button">Reset filters</button>' : actionButton('new-customer', 'New customer'))}
        </tbody>
      </table>
    </div>`;
}

function renderScan(){
  const baseParcels = visibleParcels();
  const parcels = filterParcels(baseParcels, 'scan');
  const parcel = parcels.find(p => p.id === selectedParcelId) || parcels[0];
  if (parcel) selectedParcelId = parcel.id;
  const filteredEvents = filterEvents(state.events, 'scan').slice(0, 10);
  const scanDisabled = parcel ? '' : 'disabled';
  return `
    <div class="view-head">
      <div><p class="eyebrow">Custody events</p><h1>Scan / Event</h1></div>
    </div>
    ${filterCard('scan', 'Search scan queue and events', 'Search by tracking code, Bangladesh address, hub, route, rider, customer, event type, and event note.', [
      filterInput('scan', 'q', 'Search scan fields', 'Tejgaon, picked up, HR2607, Narayanganj...'),
      filterSelect('scan', 'status', 'Parcel status', statusOptions(filters.scan.status)),
      filterSelect('scan', 'eventType', 'Event type', selectOptions(EVENT_TYPES.map(type => ({value:type,label:type.replaceAll('_',' ')})), filters.scan.eventType, 'All event types'))
    ].join(''), baseParcels.length, parcels.length, 'three')}
    <div class="grid two">
      <form id="scanForm" class="card">
        <h2>Record event</h2>
        ${parcel ? '' : renderEmptyState(baseParcels.length ? 'No parcels match the scan filters' : 'No parcels available for scan', baseParcels.length ? 'Reset filters to choose from the current hub queue.' : 'Create a parcel before recording custody events.', baseParcels.length ? '<button class="btn ghost slim" data-action="reset-filters" data-scope="scan" type="button">Reset filters</button>' : actionButton('new-parcel', 'New parcel'))}
        <label>Tracking code<select name="parcelId" ${scanDisabled}>${options(parcels, parcel?.id, p => p.code + ' - ' + customerName(p.customerId))}</select></label>
        <label>Event type<select name="type" ${scanDisabled}>${EVENT_TYPES.map(type => `<option value="${esc(type)}">${esc(type.replaceAll('_',' '))}</option>`).join('')}</select></label>
        <label>Hub<select name="hubId" ${state.hubs.length ? scanDisabled : 'disabled'}>${options(state.hubs, state.currentHubId, h => h.name)}</select></label>
        <label>Note<textarea name="note" placeholder="Optional event note" ${scanDisabled}></textarea></label>
        <div class="actions"><button class="btn primary" type="submit" ${scanDisabled}>Record event</button></div>
      </form>
      <div class="card">
        <h2>${esc(parcel?.code || 'No parcels')}</h2>
        ${parcel ? `<p>${pill(parcel.status)}</p><p class="muted">${esc(parcel.pickupAddress)} to ${esc(parcel.dropoffAddress)}</p>` : renderEmptyState('No selected parcel', 'A parcel preview appears here once the scan queue has at least one match.')}
      </div>
    </div>
    ${parcel ? renderTimeline(parcel.id) : ''}
    <div class="card"><h2>Filtered recent events</h2>${renderEventList(filteredEvents)}</div>`;
}

function publicProgressSteps(parcel){
  const steps = [
    {key:'requested', label:'Requested'},
    {key:'picked_up', label:'Picked up'},
    {key:'in_transit', label:'In transit'},
    {key:'out_for_delivery', label:'Out for delivery'},
    {key:'delivered', label:'Delivered'}
  ];
  const aliases = { assigned:'requested', in_warehouse:'in_transit', handoff_departed:'in_transit', handoff_received:'in_transit', failed:'out_for_delivery' };
  const currentKey = aliases[parcel.status] || parcel.status;
  const currentIndex = steps.findIndex(step => step.key === currentKey);
  return `<div class="progress-line" aria-label="Parcel progress">
    ${steps.map((step, index) => {
      let stateClass = currentIndex > index ? 'done' : currentIndex === index ? 'current' : '';
      if (parcel.status === 'failed' && index === Math.max(0, currentIndex)) stateClass = 'failed';
      return `<div class="progress-step ${stateClass}">
        <strong>${esc(step.label)}</strong>
        <span class="muted small">${stateClass === 'done' ? 'Complete' : stateClass === 'current' ? (parcel.status === 'failed' ? 'Needs attention' : 'Current') : 'Pending'}</span>
      </div>`;
    }).join('')}
  </div>`;
}

function renderPublicParcel(parcel){
  return `<div class="public-result">
    <section class="surface">
      <div class="status-strip">
        <div>
          <p class="eyebrow">Current status</p>
          <h2>${esc(parcel.status.replaceAll('_',' '))}</h2>
          <p class="muted small">Last updated from ${esc(hubName(parcel.currentHubId))}</p>
        </div>
        <div>
          <p class="eyebrow">Tracking code</p>
          <h2>${esc(parcel.code)}</h2>
          <p>${pill(parcel.status)}</p>
        </div>
      </div>
      ${publicProgressSteps(parcel)}
      <div class="detail-list" aria-label="Parcel details">
        <div class="detail-row"><span>Merchant</span><strong>${esc(customerName(parcel.customerId))}</strong></div>
        <div class="detail-row"><span>Pickup area</span><strong>${esc(publicArea(parcel.pickupAddress))}</strong></div>
        <div class="detail-row"><span>Dropoff area</span><strong>${esc(publicArea(parcel.dropoffAddress))}</strong></div>
        <div class="detail-row"><span>Payment</span><strong>${parcel.cod ? `${money(parcel.amountCents)} COD` : 'Prepaid'}</strong></div>
      </div>
    </section>
    ${renderTimeline(parcel.id, true, 'Public movement history')}
  </div>`;
}

function renderTracking(){
  const parcel = findParcelByCode(trackingLookup);
  const samples = state.parcels.slice(0, 4);
  return `
    <div class="view-head">
      <div><p class="eyebrow">Public tracking</p><h1>Track a parcel</h1></div>
    </div>
    <form id="trackingForm" class="tracking-lookup">
      <label>Tracking number<input name="trackingCode" value="${esc(trackingLookup)}" placeholder="HR260703DHK1A2" autocomplete="off" spellcheck="false"></label>
      <button class="btn primary" type="submit">Track</button>
    </form>
    <p class="muted small">For senders and receivers: enter the tracking number from your SMS, receipt, or merchant confirmation.</p>
    ${trackingLookup && !parcel ? renderEmptyState('No parcel found', `No public tracking record matches ${trackingLookup}. Check the code and try again.`) : ''}
    ${parcel ? renderPublicParcel(parcel) : `<section class="surface">
      <div><h2>Try a sample parcel</h2><p class="muted small">These sample codes demonstrate the public sender/receiver view without exposing internal hub controls.</p></div>
      ${samples.length ? `<div class="sample-codes">
        ${samples.map(p => `<button class="btn ghost slim" data-action="sample-track" data-code="${esc(p.code)}" type="button">${esc(p.code)}</button>`).join('')}
      </div>` : renderEmptyState('No sample tracking codes', 'Create a parcel to make public tracking samples available.', actionButton('new-parcel', 'New parcel'))}
    </section>`}`;
}

function renderEventList(events){
  if (!events.length) return renderEmptyState('No events yet', 'Custody events, scan notes, and handoff updates will appear here once activity is recorded.');
  return `<div class="timeline">${events.map(event => {
    const parcel = getParcel(event.parcelId);
    return `<div class="event"><strong>${esc(event.type.replaceAll('_',' '))}${parcel ? ' / ' + esc(parcel.code) : ''}</strong><span class="muted small">${esc(hubName(event.hubId))} / ${esc(event.note || 'No note')} / ${fmtDate(event.at)}</span></div>`;
  }).join('')}</div>`;
}

function renderTimeline(parcelId, publicOnly = false, title = 'Event timeline'){
  const rows = state.events.filter(e => e.parcelId === parcelId);
  return `<section class="surface"><h2>${esc(title)}</h2>${renderEventList(publicOnly ? rows.filter(e => e.type !== 'note') : rows)}</section>`;
}

function closeSelectSearches(except = null){
  document.querySelectorAll('.select-search.open').forEach(combo => {
    if (combo !== except) combo.classList.remove('open');
  });
}
function selectedText(select){
  return select.options[select.selectedIndex]?.text || '';
}
function openSelectSearch(combo, query = ''){
  closeSelectSearches(combo);
  combo.classList.add('open');
  renderSelectSearchOptions(combo, query);
}
function renderSelectSearchOptions(combo, query = ''){
  const select = combo.querySelector('select');
  const list = combo.querySelector('.select-search-list');
  const visible = Array.from(select.options).filter(option => matchesQuery(option.text, query) || matchesQuery(option.value, query));
  list.innerHTML = '';
  visible.forEach((option) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'select-search-option';
    button.dataset.value = option.value;
    button.textContent = option.text;
    if (option.selected) button.classList.add('active');
    list.appendChild(button);
  });
  if (!visible.length) {
    const empty = document.createElement('span');
    empty.className = 'select-search-option muted';
    empty.textContent = 'No matching options';
    list.appendChild(empty);
  }
}
function enhanceSelects(root = document){
  root.querySelectorAll('select:not([data-enhanced])').forEach(select => {
    select.dataset.enhanced = 'true';
    select.classList.add('select-search-native');
    const combo = document.createElement('span');
    combo.className = 'select-search';
    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'select-search-input';
    input.autocomplete = 'off';
    input.value = selectedText(select);
    input.setAttribute('aria-label', select.closest('label')?.textContent?.trim() || 'Search dropdown options');
    const list = document.createElement('span');
    list.className = 'select-search-list';
    select.parentNode.insertBefore(combo, select);
    combo.appendChild(input);
    combo.appendChild(list);
    combo.appendChild(select);
    renderSelectSearchOptions(combo);
    input.addEventListener('focus', () => {
      input.select();
      openSelectSearch(combo);
    });
    input.addEventListener('click', () => openSelectSearch(combo));
    input.addEventListener('input', () => {
      openSelectSearch(combo, input.value);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        combo.classList.remove('open');
        input.value = selectedText(select);
      }
      if (event.key === 'Enter') {
        const first = combo.querySelector('.select-search-option[data-value]');
        if (first) {
          event.preventDefault();
          first.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        }
      }
    });
    list.addEventListener('mousedown', (event) => {
      const option = event.target.closest('.select-search-option[data-value]');
      if (!option) return;
      event.preventDefault();
      select.value = option.dataset.value;
      input.value = selectedText(select);
      combo.classList.remove('open');
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    select.addEventListener('change', () => {
      input.value = selectedText(select);
      renderSelectSearchOptions(combo, input.value);
    });
  });
}

function openModal(title, body, footer = ''){
  const root = $('modalRoot');
  root.innerHTML = `<div class="modal">
    <div class="modal-header"><div><p class="eyebrow">HubRoute demo</p><h2>${esc(title)}</h2></div><button class="btn ghost slim" data-action="close-modal" type="button">Close</button></div>
    <div class="modal-body">${body}</div>
    ${footer}
  </div>`;
  root.classList.add('show');
  enhanceSelects(root);
  const first = root.querySelector('input,select,textarea,button');
  first?.focus();
}
function closeModal(){
  $('modalRoot').classList.remove('show');
  $('modalRoot').innerHTML = '';
}

function openParcelForm(id = ''){
  const p = id ? getParcel(id) : {
    id:'', code:'', customerId:state.customers.find(c => c.status === 'active')?.id || state.customers[0]?.id || '',
    pickupAddress:'', dropoffAddress:'', amountCents:0, cod:false, status:'requested',
    currentHubId:state.currentHubId, pickupHubId:state.currentHubId, warehouseHubId:'hub-central', deliveryHubId:'hub-east',
    pendingToHubId:'', pathIndex:0, routeId:'', riderId:'', weightKg:'1.0', notes:''
  };
  openModal(id ? 'Edit parcel' : 'New parcel', `
    <form id="parcelForm" class="stack">
      <input type="hidden" name="id" value="${esc(p.id)}">
      <div class="form-grid">
        <div class="readonly-field"><span>Tracking number</span><strong>${p.code ? esc(p.code) : 'Auto-generated on save'}</strong></div>
        <label>Customer<select name="customerId" required>${options(state.customers.filter(c => c.status !== 'inactive' || c.id === p.customerId), p.customerId, c => c.name)}</select></label>
        <label>Status<select name="status">${STATUSES.map(s => `<option value="${esc(s)}" ${s === p.status ? 'selected' : ''}>${esc(s.replaceAll('_',' '))}</option>`).join('')}</select></label>
        <label>Weight kg<input name="weightKg" value="${esc(p.weightKg)}"></label>
        <label>Current hub<select name="currentHubId">${options(state.hubs, p.currentHubId, h => h.name)}</select></label>
        <label>Pickup hub<select name="pickupHubId">${options(state.hubs, p.pickupHubId, h => h.name)}</select></label>
        <label>Warehouse hub<select name="warehouseHubId">${options(state.hubs, p.warehouseHubId, h => h.name)}</select></label>
        <label>Delivery hub<select name="deliveryHubId">${options(state.hubs, p.deliveryHubId, h => h.name)}</select></label>
        <label>Route<select name="routeId">${options(state.routes.filter(r => r.hubId === state.currentHubId || r.id === p.routeId), p.routeId, r => r.name, 'Unassigned')}</select></label>
        <label>Rider<select name="riderId">${options(state.riders.filter(r => r.hubId === state.currentHubId || r.id === p.riderId), p.riderId, r => r.name, 'Unassigned')}</select></label>
        <label>COD amount (BDT)<input name="amount" type="number" min="0" step="1" value="${Math.round(Number(p.amountCents || 0) / 100)}"></label>
        <label class="checkline"><input name="cod" type="checkbox" ${p.cod ? 'checked' : ''}> COD required</label>
        <label class="wide">Pickup address<textarea name="pickupAddress" required>${esc(p.pickupAddress)}</textarea></label>
        <label class="wide">Dropoff address<textarea name="dropoffAddress" required>${esc(p.dropoffAddress)}</textarea></label>
        <label class="wide">Notes<textarea name="notes">${esc(p.notes)}</textarea></label>
      </div>
    </form>`,
    `<div class="modal-actions"><button class="btn ghost" data-action="close-modal" type="button">Cancel</button><button class="btn primary" form="parcelForm" type="submit">Save parcel</button></div>`);
}

function saveParcel(form){
  const data = Object.fromEntries(new FormData(form).entries());
  const existing = data.id ? getParcel(data.id) : null;
  const trackingCode = existing?.code || newTrackingCode();
  const duplicate = state.parcels.find(p => p.code === trackingCode && p.id !== data.id);
  if (duplicate) return toast('Could not generate a unique tracking number');
  const route = data.routeId ? getRoute(data.routeId) : null;
  const rider = data.riderId ? getRider(data.riderId) : null;
  if (route && route.hubId !== data.currentHubId) return toast('Route must belong to the parcel current hub');
  if (rider && rider.hubId !== data.currentHubId) return toast('Rider must belong to the parcel current hub');
  const newPath = [data.pickupHubId, data.warehouseHubId, data.deliveryHubId].filter(Boolean).filter((hubId, index, rows) => index === 0 || hubId !== rows[index - 1]);
  let nextPathIndex = Number(existing?.pathIndex || 0);
  const matchedPathIndex = newPath.findIndex((hubId, index) => hubId === data.currentHubId && index >= nextPathIndex);
  if (matchedPathIndex >= 0) nextPathIndex = matchedPathIndex;
  const payload = {
    id: data.id || uid('parcel'),
    code: trackingCode,
    customerId: data.customerId,
    pickupAddress: data.pickupAddress.trim(),
    dropoffAddress: data.dropoffAddress.trim(),
    amountCents: Math.round(Number(data.amount || 0) * 100),
    cod: form.elements.cod.checked,
    status: data.status,
    currentHubId: data.currentHubId,
    pendingToHubId: existing?.pendingToHubId || '',
    pickupHubId: data.pickupHubId,
    warehouseHubId: data.warehouseHubId,
    deliveryHubId: data.deliveryHubId,
    pathIndex: nextPathIndex,
    routeId: data.routeId,
    riderId: data.riderId,
    weightKg: data.weightKg.trim(),
    notes: data.notes.trim()
  };
  const existingIndex = state.parcels.findIndex(p => p.id === payload.id);
  if (existingIndex >= 0) {
    const before = state.parcels[existingIndex];
    state.parcels[existingIndex] = payload;
    if (before.routeId !== payload.routeId || before.riderId !== payload.riderId) {
      addEvent(payload.id, 'assigned', state.currentHubId, `Assignment updated to ${routeName(payload.routeId)} / ${riderName(payload.riderId)}`);
    }
  } else {
    state.parcels.unshift(payload);
    addEvent(payload.id, 'requested', payload.currentHubId, 'Parcel created in demo');
  }
  selectedParcelId = payload.id;
  saveState();
  closeModal();
  render();
  toast('Parcel saved');
}

function openRouteForm(id = ''){
  const route = id ? getRoute(id) : {id:'', name:'', hubId:state.currentHubId, type:'pickup', areas:'', capacity:12, riderId:'', status:'active'};
  const hubRiders = state.riders.filter(r => r.hubId === route.hubId || r.id === route.riderId);
  openModal(id ? 'Edit route' : 'New route', `
    <form id="routeForm" class="stack">
      <input type="hidden" name="id" value="${esc(route.id)}">
      <div class="form-grid">
        <label>Name<input name="name" required value="${esc(route.name)}"></label>
        <label>Hub<select name="hubId">${options(state.hubs, route.hubId, h => h.name)}</select></label>
        <label>Type<select name="type">${['pickup','warehouse','delivery','return'].map(t => `<option value="${t}" ${route.type === t ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
        <label>Capacity<input name="capacity" type="number" min="1" value="${esc(route.capacity)}"></label>
        <label>Default rider<select name="riderId">${options(hubRiders, route.riderId, r => r.name, 'No default rider')}</select></label>
        <label>Status<select name="status">${['active','paused','inactive'].map(s => `<option value="${s}" ${route.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
        <label class="wide">Service areas<textarea name="areas">${esc(route.areas)}</textarea></label>
      </div>
    </form>`,
    `<div class="modal-actions"><button class="btn ghost" data-action="close-modal" type="button">Cancel</button><button class="btn primary" form="routeForm" type="submit">Save route</button></div>`);
}

function saveRoute(form){
  const data = Object.fromEntries(new FormData(form).entries());
  const route = {
    id: data.id || uid('route'),
    name: data.name.trim(),
    hubId: data.hubId,
    type: data.type,
    areas: data.areas.trim(),
    capacity: Math.max(1, Number(data.capacity || 1)),
    riderId: data.riderId,
    status: data.status
  };
  const rider = route.riderId ? getRider(route.riderId) : null;
  if (rider && rider.hubId !== route.hubId) return toast('Default rider must belong to the route hub');
  const idx = state.routes.findIndex(r => r.id === route.id);
  if (idx >= 0) state.routes[idx] = route;
  else state.routes.unshift(route);
  saveState();
  closeModal();
  render();
  toast('Route saved');
}

function openRiderForm(id = ''){
  const rider = id ? getRider(id) : {id:'', name:'', hubId:state.currentHubId, phone:'', vehicle:'Motorbike', capacity:12, status:'active'};
  openModal(id ? 'Edit rider' : 'New rider', `
    <form id="riderForm" class="stack">
      <input type="hidden" name="id" value="${esc(rider.id)}">
      <div class="form-grid">
        <label>Name<input name="name" required value="${esc(rider.name)}"></label>
        <label>Hub<select name="hubId">${options(state.hubs, rider.hubId, h => h.name)}</select></label>
        <label>Phone<input name="phone" value="${esc(rider.phone)}"></label>
        <label>Vehicle<input name="vehicle" value="${esc(rider.vehicle)}"></label>
        <label>Capacity<input name="capacity" type="number" min="1" value="${esc(rider.capacity)}"></label>
        <label>Status<select name="status">${['active','off_duty','inactive'].map(s => `<option value="${s}" ${rider.status === s ? 'selected' : ''}>${s.replaceAll('_',' ')}</option>`).join('')}</select></label>
      </div>
    </form>`,
    `<div class="modal-actions"><button class="btn ghost" data-action="close-modal" type="button">Cancel</button><button class="btn primary" form="riderForm" type="submit">Save rider</button></div>`);
}

function saveRider(form){
  const data = Object.fromEntries(new FormData(form).entries());
  const rider = {
    id: data.id || uid('rider'),
    name: data.name.trim(),
    hubId: data.hubId,
    phone: data.phone.trim(),
    vehicle: data.vehicle.trim(),
    capacity: Math.max(1, Number(data.capacity || 1)),
    status: data.status
  };
  const idx = state.riders.findIndex(r => r.id === rider.id);
  if (idx >= 0) state.riders[idx] = rider;
  else state.riders.unshift(rider);
  saveState();
  closeModal();
  render();
  toast('Rider saved');
}

function openCustomerForm(id = ''){
  const customer = id ? getCustomer(id) : {id:'', name:'', phone:'', email:'', address:'', status:'active'};
  openModal(id ? 'Edit customer' : 'New customer', `
    <form id="customerForm" class="stack">
      <input type="hidden" name="id" value="${esc(customer.id)}">
      <div class="form-grid">
        <label>Name<input name="name" required value="${esc(customer.name)}"></label>
        <label>Phone<input name="phone" value="${esc(customer.phone)}"></label>
        <label>Email<input name="email" type="email" value="${esc(customer.email)}"></label>
        <label>Status<select name="status">${['active','inactive'].map(s => `<option value="${s}" ${customer.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
        <label class="wide">Address<textarea name="address">${esc(customer.address)}</textarea></label>
      </div>
    </form>`,
    `<div class="modal-actions"><button class="btn ghost" data-action="close-modal" type="button">Cancel</button><button class="btn primary" form="customerForm" type="submit">Save customer</button></div>`);
}

function saveCustomer(form){
  const data = Object.fromEntries(new FormData(form).entries());
  const customer = {
    id: data.id || uid('cust'),
    name: data.name.trim(),
    phone: data.phone.trim(),
    email: data.email.trim(),
    address: data.address.trim(),
    status: data.status
  };
  const idx = state.customers.findIndex(c => c.id === customer.id);
  if (idx >= 0) state.customers[idx] = customer;
  else state.customers.unshift(customer);
  saveState();
  closeModal();
  render();
  toast('Customer saved');
}

function openAssignFlow(parcelId){
  const parcel = getParcel(parcelId);
  if (!parcel) return toast('Parcel not found');
  selectedParcelId = parcel.id;
  if (parcel.currentHubId !== state.currentHubId || parcel.pendingToHubId) return toast('Receive the parcel at this hub before assigning local route and rider');
  const routes = state.routes.filter(r => r.hubId === state.currentHubId && r.status === 'active');
  const riders = state.riders.filter(r => r.hubId === state.currentHubId && r.status === 'active');
  openModal('Assign parcel', `
    <form id="assignForm" class="stack">
      <input type="hidden" name="parcelId" value="${esc(parcel.id)}">
      <div class="assignment-summary">
        <h3>${esc(parcel.code)}</h3>
        <p class="muted small">${esc(customerName(parcel.customerId))} / ${esc(parcel.pickupAddress)} to ${esc(parcel.dropoffAddress)}</p>
        <p>${pill(parcel.status)}</p>
      </div>
      <div class="grid two">
        <section class="stack">
          <div><h3>Routes at ${esc(hubName(state.currentHubId))}</h3><p class="muted small">Capacity reflects active, undelivered parcels.</p></div>
          <div class="choice-list">
            ${routes.map((route, index) => {
              const load = routeLoad(route.id, parcel.id);
              const full = load >= Number(route.capacity || 0);
              return `<label class="choice-card">
                <input type="radio" name="routeId" value="${esc(route.id)}" ${route.id === parcel.routeId || (!parcel.routeId && index === 0) ? 'checked' : ''} ${full ? 'disabled' : ''}>
                <span class="choice-main">
                  <span class="choice-title"><span>${esc(route.name)}</span><span>${load} / ${Number(route.capacity || 0)}</span></span>
                  <span class="muted small">${esc(route.areas)} / default rider: ${esc(riderName(route.riderId))}${full ? ' / full' : ''}</span>
                </span>
              </label>`;
            }).join('') || '<div class="empty">No active routes at the current hub.</div>'}
          </div>
        </section>
        <section class="stack">
          <div><h3>Riders at ${esc(hubName(state.currentHubId))}</h3><p class="muted small">Workload is counted from active rides.</p></div>
          <div class="choice-list">
            ${riders.map((rider, index) => {
              const load = riderLoad(rider.id, parcel.id);
              const full = load >= Number(rider.capacity || 0);
              return `<label class="choice-card">
                <input type="radio" name="riderId" value="${esc(rider.id)}" ${rider.id === parcel.riderId || (!parcel.riderId && index === 0) ? 'checked' : ''} ${full ? 'disabled' : ''}>
                <span class="choice-main">
                  <span class="choice-title"><span>${esc(rider.name)}</span><span>${load} active</span></span>
                  <span class="muted small">${esc(rider.vehicle)} / capacity ${Number(rider.capacity || 0)}${full ? ' / full' : ''}</span>
                </span>
              </label>`;
            }).join('') || '<div class="empty">No active riders at the current hub.</div>'}
          </div>
        </section>
      </div>
      <label>Assignment note<textarea name="note">Assigned from ${esc(hubName(state.currentHubId))}</textarea></label>
    </form>`,
    `<div class="modal-actions"><button class="btn ghost" data-action="close-modal" type="button">Cancel</button><button class="btn primary" form="assignForm" type="submit">Save assignment</button></div>`);
}

function saveAssignment(form){
  const data = Object.fromEntries(new FormData(form).entries());
  const parcel = getParcel(data.parcelId);
  const route = getRoute(data.routeId);
  const rider = getRider(data.riderId);
  if (!parcel) return toast('Parcel not found');
  if (parcel.currentHubId !== state.currentHubId || parcel.pendingToHubId) return toast('Receive the parcel at this hub before assigning local route and rider');
  if (!route || route.hubId !== state.currentHubId || route.status !== 'active') return toast('Choose an active route at the current hub');
  if (!rider || rider.hubId !== state.currentHubId || rider.status !== 'active') return toast('Choose an active rider at the current hub');
  if (routeLoad(route.id, parcel.id) >= Number(route.capacity || 0)) return toast('Route is at capacity');
  if (riderLoad(rider.id, parcel.id) >= Number(rider.capacity || 0)) return toast('Rider is at capacity');
  parcel.routeId = route.id;
  parcel.riderId = rider.id;
  parcel.currentHubId = state.currentHubId;
  if (parcel.status === 'requested') parcel.status = 'assigned';
  addEvent(parcel.id, 'assigned', state.currentHubId, data.note?.trim() || `Assigned to ${route.name} and ${rider.name}`);
  saveState();
  closeModal();
  render();
  toast(`${parcel.code} assigned`);
}

function sendParcel(parcelId){
  const parcel = getParcel(parcelId);
  if (!parcel) return toast('Parcel not found');
  if (!canSend(parcel)) return toast('This parcel is not ready for outbound handoff from this hub');
  const targetHubId = nextHubFor(parcel);
  parcel.pendingToHubId = targetHubId;
  parcel.status = 'in_transit';
  addEvent(parcel.id, 'handoff_departed', state.currentHubId, `Departed ${hubName(state.currentHubId)} for ${hubName(targetHubId)}`);
  selectedParcelId = parcel.id;
  saveState();
  render();
  toast(`${parcel.code} sent to ${hubName(targetHubId)}`);
}

function receiveParcel(parcelId){
  const parcel = getParcel(parcelId);
  if (!parcel) return toast('Parcel not found');
  if (!canReceive(parcel)) return toast('Switch to the destination hub to receive this parcel');
  const path = hubPath(parcel);
  const receivedIndex = path.findIndex((hubId, index) => hubId === state.currentHubId && index >= pathIndex(parcel));
  parcel.currentHubId = state.currentHubId;
  parcel.pendingToHubId = '';
  if (receivedIndex >= 0) parcel.pathIndex = receivedIndex;
  const hub = getHub(state.currentHubId);
  if (hub?.type === 'warehouse') parcel.status = 'in_warehouse';
  else if (hub?.type === 'delivery') parcel.status = 'out_for_delivery';
  else if (hub?.type === 'return') parcel.status = parcel.status === 'failed' ? 'failed' : 'picked_up';
  else parcel.status = 'picked_up';
  if (parcel.routeId && getRoute(parcel.routeId)?.hubId !== state.currentHubId) parcel.routeId = '';
  if (parcel.riderId && getRider(parcel.riderId)?.hubId !== state.currentHubId) parcel.riderId = '';
  addEvent(parcel.id, 'handoff_received', state.currentHubId, `Received at ${hubName(state.currentHubId)}`);
  selectedParcelId = parcel.id;
  saveState();
  render();
  toast(`${parcel.code} received at ${hubName(state.currentHubId)}`);
}

function deleteParcel(id){
  const parcel = getParcel(id);
  if (!parcel || !confirm(`Delete parcel ${parcel.code}?`)) return;
  state.parcels = state.parcels.filter(p => p.id !== id);
  state.events = state.events.filter(e => e.parcelId !== id);
  selectedParcelId = state.parcels[0]?.id || '';
  saveState();
  render();
  toast('Parcel deleted');
}
function deleteRoute(id){
  const route = getRoute(id);
  if (!route) return;
  const assigned = state.parcels.filter(p => p.routeId === id);
  const message = assigned.length ? `Delete ${route.name} and clear it from ${assigned.length} parcels?` : `Delete route ${route.name}?`;
  if (!confirm(message)) return;
  assigned.forEach(p => {
    p.routeId = '';
    if (p.status === 'assigned') p.status = 'requested';
    addEvent(p.id, 'note', state.currentHubId, `Route ${route.name} removed from parcel`);
  });
  state.routes = state.routes.filter(r => r.id !== id);
  saveState();
  render();
  toast('Route deleted');
}
function deleteRider(id){
  const rider = getRider(id);
  if (!rider) return;
  const assigned = state.parcels.filter(p => p.riderId === id);
  const message = assigned.length ? `Delete ${rider.name} and clear rider from ${assigned.length} parcels?` : `Delete rider ${rider.name}?`;
  if (!confirm(message)) return;
  assigned.forEach(p => {
    p.riderId = '';
    addEvent(p.id, 'note', state.currentHubId, `Rider ${rider.name} removed from parcel`);
  });
  state.routes.filter(r => r.riderId === id).forEach(r => { r.riderId = ''; });
  state.riders = state.riders.filter(r => r.id !== id);
  saveState();
  render();
  toast('Rider deleted');
}
function deleteCustomer(id){
  const customer = getCustomer(id);
  if (!customer) return;
  const used = state.parcels.some(p => p.customerId === id);
  if (used) {
    if (!confirm(`${customer.name} has parcel history. Mark customer inactive instead?`)) return;
    customer.status = 'inactive';
    saveState();
    render();
    return toast('Customer archived');
  }
  if (!confirm(`Delete customer ${customer.name}?`)) return;
  state.customers = state.customers.filter(c => c.id !== id);
  saveState();
  render();
  toast('Customer deleted');
}
function resetDemo(){
  if (!confirm('Reset all demo data in this browser?')) return;
  state = seedState();
  selectedParcelId = state.parcels[0]?.id || '';
  saveState();
  render();
  toast('Demo data reset');
}

document.querySelectorAll('.tab').forEach(tab => tab.addEventListener('click', () => setView(tab.dataset.view)));
$('accountSearch').addEventListener('input', renderAccountCards);
$('signIn').addEventListener('click', () => {
  const account = DEMO_ACCOUNTS.find(row => row.email === $('email').value.trim() && row.password === $('password').value);
  if (!account) return toast('Use one of the seeded demo accounts');
  signedIn = true;
  selectedAccountEmail = account.email;
  state.currentHubId = account.hubId;
  saveState();
  setView('dashboard');
});
$('tryTracking').addEventListener('click', () => {
  setView('track');
  setTrackingLookup('');
  render();
});

document.addEventListener('click', (event) => {
  if (!event.target.closest('.select-search')) closeSelectSearches();
  const jump = event.target.closest('[data-view-jump]');
  if (jump) return setView(jump.dataset.viewJump);
  const button = event.target.closest('[data-action]');
  if (!button) return;
  const action = button.dataset.action;
  const id = button.dataset.id || '';
  const handlers = {
    'close-modal': closeModal,
    'use-account': () => chooseAccount(button.dataset.email),
    'operate-hub': () => operateHub(button.dataset.hubId),
    'focus-parcel': () => focusParcel(id),
    'track-parcel': () => trackParcel(id),
    'sample-track': () => {
      setView('track');
      setTrackingLookup(button.dataset.code);
      render();
    },
    'reset-demo': resetDemo,
    'reset-filters': () => resetFilters(button.dataset.scope),
    'new-parcel': () => openParcelForm(),
    'edit-parcel': () => openParcelForm(id),
    'delete-parcel': () => deleteParcel(id),
    'assign-parcel': () => openAssignFlow(id),
    'send-parcel': () => sendParcel(id),
    'receive-parcel': () => receiveParcel(id),
    'new-route': () => openRouteForm(),
    'edit-route': () => openRouteForm(id),
    'delete-route': () => deleteRoute(id),
    'new-rider': () => openRiderForm(),
    'edit-rider': () => openRiderForm(id),
    'delete-rider': () => deleteRider(id),
    'new-customer': () => openCustomerForm(),
    'edit-customer': () => openCustomerForm(id),
    'delete-customer': () => deleteCustomer(id)
  };
  handlers[action]?.();
});

document.addEventListener('input', (event) => {
  const filter = event.target.closest('[data-filter-scope][data-filter-key]');
  if (!filter || filter.tagName === 'SELECT') return;
  updateFilterFromElement(filter, true);
});

document.addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.target;
  if (form.id === 'parcelForm') saveParcel(form);
  if (form.id === 'routeForm') saveRoute(form);
  if (form.id === 'riderForm') saveRider(form);
  if (form.id === 'customerForm') saveCustomer(form);
  if (form.id === 'assignForm') saveAssignment(form);
  if (form.id === 'trackingForm') {
    const data = Object.fromEntries(new FormData(form).entries());
    setTrackingLookup(data.trackingCode);
    render();
  }
  if (form.id === 'scanForm') {
    const data = Object.fromEntries(new FormData(form).entries());
    const parcel = getParcel(data.parcelId);
    if (!parcel) return toast('Parcel not found');
    if (STATUSES.includes(data.type)) parcel.status = data.type;
    if (data.hubId) parcel.currentHubId = data.hubId;
    addEvent(parcel.id, data.type, data.hubId, data.note.trim() || 'Recorded in demo');
    selectedParcelId = parcel.id;
    saveState();
    render();
    toast('Event recorded');
  }
});

document.addEventListener('change', (event) => {
  const filter = event.target.closest('[data-filter-scope][data-filter-key]');
  if (filter) {
    updateFilterFromElement(filter);
    return;
  }
  if (event.target.name === 'parcelId' && event.target.closest('#scanForm')) {
    selectedParcelId = event.target.value;
    render();
  }
});

$('modalRoot').addEventListener('click', (event) => {
  if (event.target === $('modalRoot')) closeModal();
});

setView(currentView);
