const VIEW_REGISTRY = {
  dashboard: 'renderDashboard',
  parcels: 'renderParcels',
  routes: 'renderRoutes',
  riders: 'renderRiders',
  customers: 'renderCustomers',
  scan: 'renderScan',
  track: 'renderTracking'
};

export function renderCurrentView({ root, currentView, renderers, enhanceSelects, prefixHtml = '' }){
  const renderer = renderers[VIEW_REGISTRY[currentView]] || renderers.renderDashboard;
  root.innerHTML = `${prefixHtml}${renderer()}`;
  enhanceSelects(root);
}
