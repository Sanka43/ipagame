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
  try {
    data = await res.json();
  } catch {
    // No PHP (e.g. GitHub Pages): serve read-only actions straight from the JSON file.
    if (init.method === 'GET' && action in staticApi) return staticApi[action](opts.params || {});
    throw new Error(`Server error (${res.status})`);
  }
  if (!res.ok || data.error) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    throw err;
  }
  return data;
}

// Static-hosting fallback mirroring api.php's public 'list' and 'get' actions.
let staticData;
async function loadStaticData() {
  if (!staticData) {
    const res = await fetch(new URL(BASE + 'data/apps.json', location.href));
    if (!res.ok) throw new Error(`Could not load data (${res.status})`);
    staticData = await res.json();
  }
  return staticData;
}

const isPublished = i => (i.status || 'published') === 'published';

const LIST_FIELDS = ['id', 'slug', 'type', 'name', 'developer', 'category', 'icon', 'short_description', 'latest_version', 'featured'];
const listRow = i => Object.fromEntries(LIST_FIELDS.filter(k => k in i).map(k => [k, i[k]]));

function matchesFilters(i, type, cat, q) {
  if (type && i.type !== type) return false;
  if (cat && i.category !== cat) return false;
  if (!q) return true;
  return [i.name, i.developer, i.category, i.short_description, ...(i.tags || [])].join(' ').toLowerCase().includes(q);
}

const staticApi = {
  async list(params) {
    const d = await loadStaticData();
    const items = (d.items || []).filter(isPublished)
      .sort((a, b) => (b.updated_at || '').localeCompare(a.updated_at || ''));
    if (params.page == null) return { items, categories: d.categories || {} };

    const q = String(params.q || '').trim().toLowerCase();
    const filtered = items.filter(i => matchesFilters(i, params.type || '', params.category || '', q));
    const counts = {};
    items.forEach(i => {
      if (i.category && (!params.type || (i.type || 'game') === params.type)) counts[i.category] = (counts[i.category] || 0) + 1;
    });
    const shelves = {};
    if (params.shelves != null) {
      const n = Math.max(1, Math.min(30, parseInt(params.shelves, 10) || 1));
      filtered.forEach(i => {
        if (!i.category) return;
        const s = shelves[i.category] ||= { category: i.category, count: 0, items: [] };
        if (s.count++ < n) s.items.push(listRow(i));
      });
    }
    const per = Math.max(1, Math.min(100, parseInt(params.per_page, 10) || 24));
    const pages = Math.max(1, Math.ceil(filtered.length / per));
    const page = Math.max(1, Math.min(pages, parseInt(params.page, 10) || 1));
    return {
      items: filtered.slice((page - 1) * per, page * per).map(listRow),
      total: filtered.length, page, pages, per_page: per,
      facets: {
        types: [...new Set(items.map(i => i.type || 'game'))],
        categories: [...new Set(items.map(i => i.category).filter(Boolean))].sort(),
        category_counts: Object.fromEntries(Object.entries(counts).sort((a, b) => b[1] - a[1])),
      },
      featured: items.filter(i => i.featured?.popular || i.featured?.editors_choice).map(listRow),
      shelves: Object.values(shelves).sort((a, b) => b.count - a.count),
    };
  },
  async get(params) {
    const d = await loadStaticData();
    const item = (d.items || []).find(i =>
      (params.id != null && String(i.id) === String(params.id)) ||
      (params.slug != null && i.slug === params.slug));
    if (!item || !isPublished(item)) { const e = new Error('Not found'); e.status = 404; throw e; }
    return { item };
  },
  async me() { return { admin: false }; },
};

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

const CAT_ICONS = {
  action: '⚔️', adventure: '🧭', arcade: '👾', casual: '🎈', puzzle: '🧩', racing: '🏎️',
  'role-playing': '🐉', simulation: '🏗️', strategy: '♟️', sports: '⚽', board: '🎲', card: '🃏',
  casino: '🎰', family: '👨‍👩‍👧', music: '🎵', trivia: '❓', word: '🔤',
};
const catIcon = c => CAT_ICONS[c] || '🎮';

// Gradient pair per category for the Search page cards (dark enough for white text).
const CAT_COLORS = {
  action: ['#e11d48', '#f97316'], adventure: ['#059669', '#0284c7'], arcade: ['#7c3aed', '#db2777'],
  casual: ['#db2777', '#f43f5e'], puzzle: ['#16a34a', '#65a30d'], racing: ['#dc2626', '#ea580c'],
  'role-playing': ['#6d28d9', '#4338ca'], simulation: ['#d97706', '#b45309'], strategy: ['#0284c7', '#4f46e5'],
  sports: ['#15803d', '#0f766e'], board: ['#92400e', '#c2410c'], card: ['#be123c', '#7e22ce'],
  casino: ['#991b1b', '#d97706'], family: ['#0891b2', '#2563eb'], music: ['#c026d3', '#7c3aed'],
  trivia: ['#1d4ed8', '#0891b2'], word: ['#4338ca', '#0e7490'],
};
const catColors = c => CAT_COLORS[c] || ['#475569', '#1e293b'];

// Bottom tab bar shared by the store pages. `active` is one of the TABS keys (or '' for none).
const TABS = [
  ['home', 'Home', './', '<path fill="currentColor" fill-rule="evenodd" d="M7.5 2h9A3.5 3.5 0 0 1 20 5.5v13a3.5 3.5 0 0 1-3.5 3.5h-9A3.5 3.5 0 0 1 4 18.5v-13A3.5 3.5 0 0 1 7.5 2zM8.2 5.8a1 1 0 0 0-1 1v5.4a1 1 0 0 0 1 1h7.6a1 1 0 0 0 1-1V6.8a1 1 0 0 0-1-1zM8.1 15.8a.9.9 0 0 0 0 1.8h7.8a.9.9 0 0 0 0-1.8z"/>'],
  ['game', 'Games', './?type=game', '<path fill="currentColor" fill-rule="evenodd" d="M21 3c-4.9-.4-8.8 1.5-11.5 5.4L6 8.8a1 1 0 0 0-.8.5L3.4 12.6a.6.6 0 0 0 .6.9l3.2-.4 3.7 3.7-.4 3.2a.6.6 0 0 0 .9.6l3.3-1.8a1 1 0 0 0 .5-.8l.4-3.5C19.5 11.8 21.4 7.9 21 3zM15.4 10.4a1.9 1.9 0 1 0 0-3.8 1.9 1.9 0 0 0 0 3.8z"/><path fill="currentColor" d="M6.4 15.4c-1.7.4-2.8 2-3 4.6 2.6-.2 4.2-1.3 4.6-3z"/>'],
  ['app', 'Apps', './?type=app', '<path fill="currentColor" d="M11.4 2.3a1.3 1.3 0 0 1 1.2 0l7.6 3.8a.7.7 0 0 1 0 1.3l-7.6 3.8a1.3 1.3 0 0 1-1.2 0L3.8 7.4a.7.7 0 0 1 0-1.3z"/><path fill="currentColor" d="M4.1 10.1 12 14.1l7.9-4 1.3.7a.7.7 0 0 1 0 1.3l-8.6 4.3a1.3 1.3 0 0 1-1.2 0l-8.6-4.3a.7.7 0 0 1 0-1.3z"/><path fill="currentColor" d="M4.1 14.4 12 18.4l7.9-4 1.3.7a.7.7 0 0 1 0 1.3l-8.6 4.3a1.3 1.3 0 0 1-1.2 0l-8.6-4.3a.7.7 0 0 1 0-1.3z"/>'],
  ['search', 'Search', './?search=1', '<circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2.6"/><path d="m15.5 15.5 5.5 5.5" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>'],
];

function tabBarHTML(active = '') {
  return TABS.map(([key, label, href, icon]) => `<a href="${href}" data-tab="${key}"${key === active ? ' aria-current="page"' : ''}>
      <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true">${icon}</svg>
      <span>${label}</span>
    </a>`).join('');
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
