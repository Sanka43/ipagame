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
    throw new Error(`Server error (${res.status})`);
  }
  if (res.status === 401 && document.body.dataset.auth === 'required') {
    location.replace(loginUrl());
    return new Promise(() => {});              // page is leaving; keep skeletons instead of an error
  }
  if (!res.ok || data.error) {
    const err = new Error(data.error || 'Request failed');
    err.status = res.status;
    throw err;
  }
  return data;
}

// Account page, remembering where to come back to after logging in.
function loginUrl() {
  return new URL(BASE + 'account.html?next=' + encodeURIComponent(location.pathname + location.search), location.href).href;
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

// Icon tile: shimmers over the store-logo watermark while loading, keeps the watermark if the image is missing or fails.
function iconHTML(item, cls = '') {
  const src = asset(item.icon);
  if (!src) return `<div class="icon ${cls}"></div>`;
  return `<div class="icon wait ${cls}"><img src="${esc(src)}" alt="" loading="lazy"
    onload="this.parentNode.classList.replace('wait','ok')" onerror="this.parentNode.classList.remove('wait');this.remove()"></div>`;
}

// Skeleton placeholders shown while data loads.
const skelLine = (w, h) => `<span class="skel skel-line" style="width:${w}${h ? `;height:${h}px` : ''}"></span>`;

function skelCardsHTML(n) {
  return `<div class="app-card skel-card" aria-hidden="true">
      <div class="icon wait"></div>
      <div class="row-info">${skelLine('55%', 15)}${skelLine('22%')}${skelLine('75%')}</div>
      <span class="get skel"></span>
    </div>`.repeat(n);
}

function skelShelfHTML() {
  return `<section class="shelf" aria-hidden="true">
      <div class="shelf-head">${skelLine('150px', 22)}</div>
      <div class="shelf-track" style="--rows:3">${skelCardsHTML(6)}</div>
    </section>`;
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

// Profile picture: the uploaded / Google photo or Gravatar, over the username's initial (shown if it fails).
function avatarHTML(user) {
  const initial = esc((user.username || '?').charAt(0).toUpperCase());
  if (!user.avatar) return `<span class="avatar-initial">${initial}</span>`;
  return `<span class="avatar-initial">${initial}</span><img src="${esc(asset(user.avatar))}" alt="" referrerpolicy="no-referrer"
    onload="this.classList.add('ok')" onerror="this.remove()">`;
}

// Round profile button in the top-right corner of the store pages (opens the account page).
function mountAccountButton() {
  const main = document.querySelector('main.wrap');
  if (!main) return;
  const btn = document.createElement('a');
  btn.className = 'me-btn';
  btn.href = BASE + 'account.html';
  btn.setAttribute('aria-label', 'Account');
  main.append(btn);
  const fill = u => { btn.innerHTML = avatarHTML(u); btn.title = u.username; };
  // Last known user from this tab, so the picture shows at once instead of popping in.
  try { const u = JSON.parse(sessionStorage.getItem('me') || 'null'); if (u) fill(u); } catch {}
  api('me').then(r => {
    if (!r.user) {                                // signed out elsewhere: drop the remembered picture
      btn.innerHTML = '';
      try { sessionStorage.removeItem('me'); } catch {}
      return;
    }
    fill(r.user);
    try { sessionStorage.setItem('me', JSON.stringify({ username: r.user.username, avatar: r.user.avatar })); } catch {}
  }).catch(() => {});
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
