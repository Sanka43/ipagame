// Shared helpers for the store and admin pages.
// Pages in sub-folders set window.API_BASE (e.g. '../') before loading this file.
const BASE = window.API_BASE || '';

async function api(action, opts = {}) {
  const url = new URL(BASE + 'api.php', location.href);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(opts.params || {})) url.searchParams.set(k, v);

  const init = { method: 'GET', headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' };
  if (opts.form) {
    init.method = 'POST';
    init.body = opts.form;
  } else if (opts.body !== undefined) {
    init.method = 'POST';
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(opts.body);
  }

  const res = await fetch(url, init);
  let data;
  try { data = await res.json(); } catch { throw new Error(`Server error (${res.status})`); }
  if (!res.ok || data.error) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    throw err;
  }
  return data;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Resolve stored paths ("uploads/icons/x.png") relative to the site root.
function asset(p) {
  if (!p) return '';
  if (/^(https?:)?\/\//i.test(p) || p.startsWith('/')) return p;
  return BASE + p;
}

// Icon with a letter fallback if the image is missing or fails to load.
function iconHTML(item, cls = '') {
  const letter = esc((item.name || '?').trim().charAt(0).toUpperCase());
  const src = asset(item.icon);
  const img = src ? `<img src="${esc(src)}" alt="" loading="lazy" onerror="this.remove()">` : '';
  return `<div class="icon ${cls}" data-l="${letter}">${img}</div>`;
}

function catLabel(c) {
  return (c || '').replace(/-/g, ' ').replace(/\b\w/g, m => m.toUpperCase());
}

function fmtSize(mb) {
  if (mb == null || mb === '') return '';
  return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Number(mb).toFixed(1).replace(/\.0$/, '') + ' MB';
}

function fmtDate(d) {
  if (!d) return '';
  const dt = new Date(d + (d.length === 10 ? 'T00:00:00' : ''));
  return isNaN(dt) ? d : dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function toast(msg, isError = false) {
  let el = document.querySelector('.toast');
  if (!el) {
    el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    document.body.append(el);
  }
  el.textContent = msg;
  el.classList.toggle('err', isError);
  el.classList.add('show');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove('show'), 2600);
}
