export const $ = (id) => document.getElementById(id);

export const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

export const uid = (prefix) => prefix + '-' + Math.random().toString(36).slice(2, 9);

export function money(cents){
  const amount = (Number(cents || 0) / 100).toLocaleString('en-BD', { maximumFractionDigits: 0 });
  return `<span class="mono">৳${esc(amount)}</span>`;
}

export function fmtDate(iso){
  const raw = String(iso ?? '').trim();
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(raw) && !/(Z|[+-]\d{2}:?\d{2})$/i.test(raw)) {
    return raw.replace('T', ' ').slice(0, 16);
  }
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return 'Pending';
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Dhaka',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23'
  }).formatToParts(date).map(part => [part.type, part.value]));
  const hour = parts.hour === '24' ? '00' : parts.hour;
  return `${parts.year}-${parts.month}-${parts.day} ${hour}:${parts.minute}`;
}

export function redact(address){ return esc(address).replace(/[0-9]/g, '*'); }

export function publicArea(address){
  const parts = String(address || '').split(',').map(part => part.trim()).filter(Boolean);
  return parts.length > 1 ? parts.slice(-2).join(', ') : String(address || 'Area unavailable');
}

export function statusClass(status){
  if (status === 'delivered') return 'green';
  if (status === 'requested') return 'amber';
  if (status === 'failed' || status === 'inactive') return 'red';
  return 'blue';
}

export function statusLabel(status){
  const normalized = String(status ?? '');
  const labels = {
    picked_up: 'picked up',
    en_route: 'in transit',
    in_transit: 'in transit',
    in_warehouse: 'at hub',
    out_for_delivery: 'out for delivery',
    cod_settled: 'cod settled',
    failed: 'failed attempt',
    note_only: 'note'
  };
  return labels[normalized] || normalized.replaceAll('_',' ');
}

export function pill(status){ return `<span class="pill ${statusClass(status)}">${esc(statusLabel(status))}</span>`; }

export function options(rows, selected, labeler, blankText = ''){
  const blank = blankText ? `<option value="">${esc(blankText)}</option>` : '';
  return blank + rows.map(row => `<option value="${esc(row.id)}" ${row.id === selected ? 'selected' : ''}>${esc(labeler(row))}</option>`).join('');
}

export function normalized(value){ return String(value ?? '').toLowerCase().replace(/\s+/g, ' ').trim(); }

export function matchesQuery(text, query){
  const q = normalized(query);
  if (!q) return true;
  return q.split(' ').every(part => normalized(text).includes(part));
}

export function selectOptions(items, selected, allLabel = 'All'){
  return `<option value="all">${esc(allLabel)}</option>` + items.map(item => `<option value="${esc(item.value)}" ${String(item.value) === String(selected) ? 'selected' : ''}>${esc(item.label)}</option>`).join('');
}

export function filterCard(scope, title, note, controls, total, shown, columns = ''){
  return `<div class="card filter-card">
    <div class="card-head"><div><h2>${esc(title)}</h2><p class="muted small">${esc(note)}</p></div><div class="actions"><span class="result-count">${shown} of ${total}</span><button class="btn ghost slim" data-action="reset-filters" data-scope="${esc(scope)}">Reset filters</button></div></div>
    <div class="filter-grid ${esc(columns)}">${controls}</div>
  </div>`;
}

export function renderStatePanel(kind, title, body, actions = ''){
  const labels = { empty: 'Empty', loading: 'Loading', error: 'Error' };
  const role = kind === 'error' ? 'alert' : 'status';
  const loader = kind === 'loading' ? '<span class="loading-bar" aria-hidden="true"></span>' : '';
  return `<div class="state-panel ${esc(kind)}" role="${role}" aria-live="polite">
    <span class="state-label">${esc(labels[kind] || 'State')}</span>
    <strong>${esc(title)}</strong>
    <p>${esc(body)}</p>
    ${loader}
    ${actions ? `<div class="actions">${actions}</div>` : ''}
  </div>`;
}

export function renderEmptyState(title, body, actions = ''){
  return renderStatePanel('empty', title, body, actions);
}

export function renderLoadingState(title = 'Loading data', body = 'Preparing the latest operational view.'){
  return renderStatePanel('loading', title, body);
}

export function renderErrorState(title, body, actions = ''){
  return renderStatePanel('error', title, body, actions);
}

export function tableStateRow(colspan, stateHtml){
  return `<tr><td colspan="${Number(colspan)}">${stateHtml}</td></tr>`;
}
