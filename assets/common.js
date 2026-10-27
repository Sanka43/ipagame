// Shared helpers for the store and admin pages.
// Pages in sub-folders set window.API_BASE (e.g. '../') before loading this file.
const BASE = window.API_BASE || '';

const IN_APP = /IPAGameApp/.test(navigator.userAgent) || Boolean(window.webkit?.messageHandlers?.googleSignIn);
const ON_IOS = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const IS_STANDALONE = Boolean(window.navigator?.standalone || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches));
const IS_APP = IN_APP || IS_STANDALONE;

function isPublicPage() {
  const p = location.pathname.toLowerCase();
  return p.endsWith('account.html') ||
         p.endsWith('privacy.html') ||
         p.endsWith('terms.html') ||
         p.endsWith('install.html') ||
         Boolean(document.body?.classList.contains('auth-page')) ||
         Boolean(document.body?.classList.contains('admin-page'));
}

// Require authentication before accessing the store (Web & App).
function enforceAuth() {
  if (isPublicPage()) return;

  const authState = localStorage.getItem('me_active');
  if (authState === '0') {
    location.replace(loginUrl());
    return;
  }

  if (authState !== '1') {
    api('me').then(r => {
      if (r && r.user) {
        try { localStorage.setItem('me_active', '1'); } catch {}
        document.documentElement.classList.remove('auth-pending');
      } else {
        try { localStorage.setItem('me_active', '0'); } catch {}
        location.replace(loginUrl());
      }
    }).catch(err => {
      if (err && err.status === 401) {
        try { localStorage.setItem('me_active', '0'); } catch {}
        location.replace(loginUrl());
      } else {
        document.documentElement.classList.remove('auth-pending');
      }
    });
  }
}
enforceAuth();

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
  if (res.status === 401 && (document.body.dataset.auth === 'required' || !isPublicPage())) {
    try { localStorage.setItem('me_active', '0'); } catch {}
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

// Description text from the admin: "# / ## / ###" lines become bold headings, *text* bold, _text_ italic (symbols hidden).
function descHtml(s) {
  return String(s ?? '').split('\n').map(line => {
    const h = line.match(/^\s*#{1,3}\s+(.+?)\s*#*\s*$/);
    const inline = t => esc(t)
      .replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>')
      .replace(/(?<![\w*])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\w*])/g, '<strong>$1</strong>')
      .replace(/(?<![\w_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w_])/g, '<em>$1</em>');
    return h ? `<strong class="dh">${inline(h[1])}</strong>` : inline(line);
  }).join('\n');
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

// Name / emoji / colours set from the admin Categories tab (sent with the list response).
let CAT_META = {};
const setCatMeta = m => { CAT_META = m || {}; };

function catLabel(c) {
  if (CAT_META[c]?.label) return CAT_META[c].label;
  return (c || '').replace(/-/g, ' ').replace(/\b\w/g, m => m.toUpperCase());
}

const CAT_ICONS = {
  action: '⚔️', adventure: '🧭', arcade: '👾', casual: '🎈', puzzle: '🧩', racing: '🏎️',
  'role-playing': '🐉', simulation: '🏗️', strategy: '♟️', sports: '⚽', board: '🎲', card: '🃏',
  casino: '🎰', family: '👨‍👩‍👧', music: '🎵', trivia: '❓', word: '🔤',
  emulators: '🕹️', communication: '💬', entertainment: '🎬', 'graphics-design': '🎨', 'health-fitness': '💪',
  'photo-video': '📷', productivity: '📋', utilities: '🛠️', education: '🎓',
};
const catIcon = c => CAT_META[c]?.emoji || CAT_ICONS[c] || '🎮';

// Gradient pair per category for the Search page cards (dark enough for white text).
const CAT_COLORS = {
  action: ['#e11d48', '#f97316'], adventure: ['#059669', '#0284c7'], arcade: ['#7c3aed', '#db2777'],
  casual: ['#db2777', '#f43f5e'], puzzle: ['#16a34a', '#65a30d'], racing: ['#dc2626', '#ea580c'],
  'role-playing': ['#6d28d9', '#4338ca'], simulation: ['#d97706', '#b45309'], strategy: ['#0284c7', '#4f46e5'],
  sports: ['#15803d', '#0f766e'], board: ['#92400e', '#c2410c'], card: ['#be123c', '#7e22ce'],
  casino: ['#991b1b', '#d97706'], family: ['#0891b2', '#2563eb'], music: ['#c026d3', '#7c3aed'],
  trivia: ['#1d4ed8', '#0891b2'], word: ['#4338ca', '#0e7490'],
  emulators: ['#7c3aed', '#2563eb'], communication: ['#0284c7', '#0891b2'], entertainment: ['#db2777', '#7c3aed'],
  'graphics-design': ['#f97316', '#db2777'], 'health-fitness': ['#16a34a', '#0d9488'], 'photo-video': ['#0e7490', '#4f46e5'],
  productivity: ['#d97706', '#ea580c'], utilities: ['#475569', '#334155'], education: ['#1d4ed8', '#6d28d9'],
};
const catColors = c => {
  const m = CAT_META[c];
  return m?.c1 && m?.c2 ? [m.c1, m.c2] : CAT_COLORS[c] || ['#475569', '#1e293b'];
};

// Bottom tab bar shared by the store pages. `active` is one of the TABS keys (or '' for none).
const TABS = [
  ['home', 'Home', './', '<path fill="currentColor" fill-rule="evenodd" d="M7.5 2h9A3.5 3.5 0 0 1 20 5.5v13a3.5 3.5 0 0 1-3.5 3.5h-9A3.5 3.5 0 0 1 4 18.5v-13A3.5 3.5 0 0 1 7.5 2zM8.2 5.8a1 1 0 0 0-1 1v5.4a1 1 0 0 0 1 1h7.6a1 1 0 0 0 1-1V6.8a1 1 0 0 0-1-1zM8.1 15.8a.9.9 0 0 0 0 1.8h7.8a.9.9 0 0 0 0-1.8z"/>'],
  ['game', 'Games', './?type=game', '<path fill="currentColor" fill-rule="evenodd" d="M21 3c-4.9-.4-8.8 1.5-11.5 5.4L6 8.8a1 1 0 0 0-.8.5L3.4 12.6a.6.6 0 0 0 .6.9l3.2-.4 3.7 3.7-.4 3.2a.6.6 0 0 0 .9.6l3.3-1.8a1 1 0 0 0 .5-.8l.4-3.5C19.5 11.8 21.4 7.9 21 3zM15.4 10.4a1.9 1.9 0 1 0 0-3.8 1.9 1.9 0 0 0 0 3.8z"/><path fill="currentColor" d="M6.4 15.4c-1.7.4-2.8 2-3 4.6 2.6-.2 4.2-1.3 4.6-3z"/>'],
  ['app', 'Apps', './?type=app', '<path fill="currentColor" d="M12 2.5 3 7.2l9 4.7 9-4.7z"/><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m3 12 9 4.7 9-4.7M3 16.8l9 4.7 9-4.7"/>'],
  ['installed', 'Play', './?installed=1', '<path fill="currentColor" d="M7 4.5v15a1 1 0 0 0 1.5.86l12.5-7.5a1 1 0 0 0 0-1.72L8.5 3.64A1 1 0 0 0 7 4.5Z"/>'],
  ['search', 'Search', './?search=1', '<circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2.6"/><path d="m15.5 15.5 5.5 5.5" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>'],
];

// ---- Installed games (iPhone / iPad: the app, Safari, the home-screen icon): remembered on this device; games run through LiveContainer. ----
const INST_KEY = 'ipa-installed-list';

// lc: 2 marks a LiveContainer folder name ("<bundle id>.app") read the current way; older saved names were wrong, so they are dropped.
function installedList() {
  try {
    const l = JSON.parse(localStorage.getItem(INST_KEY) || '[]');
    return Array.isArray(l) ? l.map(x => x.lc === 2 ? x : { ...x, bundle: '' }) : [];
  } catch { return []; }
}
function installedHas(slug) { return installedList().some(x => x.slug === slug); }
function installedAdd(it, version, bundle) {
  const old = installedList().find(x => x.slug === it.slug);
  const l = installedList().filter(x => x.slug !== it.slug);
  const ver = String(version || it.latest_version || '');
  // Keep a known .app name when the same version is downloaded again.
  l.unshift({ slug: it.slug, name: it.name, icon: it.icon || '', version: ver, bundle: bundle || (old && old.version === ver ? old.bundle : '') || '', lc: 2, t: Date.now() });
  try { localStorage.setItem(INST_KEY, JSON.stringify(l)); } catch {}
}
function installedSetBundle(slug, bundle) {
  try { localStorage.setItem(INST_KEY, JSON.stringify(installedList().map(x => x.slug === slug ? { ...x, bundle, lc: 2 } : x))); } catch {}
}
// The .app folder name LiveContainer knows the game by (read from the IPA by the server).
function fetchBundleName(slug, version) {
  return api('ipa_app_name', { params: { slug, version } }).then(r => r.name || '').catch(() => '');
}
function installedRemove(slug) {
  try { localStorage.setItem(INST_KEY, JSON.stringify(installedList().filter(x => x.slug !== slug))); } catch {}
}
// Open a livecontainer:// link. A web page cannot ask whether LiveContainer is installed, but opening it sends this page to
// the background: if the page is still in front after a moment, LiveContainer is probably missing, so say where to get it.
function openLiveContainer(url) {
  let left = false;
  const gone = () => { left = true; };
  document.addEventListener('visibilitychange', gone, { once: true });
  window.addEventListener('pagehide', gone, { once: true });
  window.addEventListener('blur', gone, { once: true });
  location.href = url;
  setTimeout(() => {
    document.removeEventListener('visibilitychange', gone);
    window.removeEventListener('pagehide', gone);
    window.removeEventListener('blur', gone);
    if (!left && document.visibilityState === 'visible') lcMissingPopup();
  }, 2500);
}
function lcMissingPopup() {
  if (document.getElementById('lcMissing')) return;
  const box = document.createElement('div');
  box.id = 'lcMissing';
  box.setAttribute('role', 'alertdialog');
  box.style.cssText = 'position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:rgba(0,0,0,.55)';
  box.innerHTML = `<div style="position:relative;max-width:340px;width:100%;background:var(--surface);color:var(--text);border-radius:22px;padding:30px 22px 26px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)">
      <button type="button" data-close aria-label="Close" style="position:absolute;top:12px;right:12px;width:32px;height:32px;border:0;border-radius:50%;background:var(--surface-2);color:var(--text);display:grid;place-items:center;cursor:pointer">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
      </button>
      <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#f5a524" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block;margin:0 auto 12px"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
      <div style="font-size:20px;font-weight:700;margin-bottom:10px">LiveContainer needed</div>
      <p class="muted" style="margin:0;font-size:14.5px;line-height:1.55">If LiveContainer did not open, it is probably not installed. Get it from
        <a href="https://livecontainer.site" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:underline">livecontainer.site</a>, install it, then tap again.</p>
    </div>`;
  box.addEventListener('click', e => { if (e.target === box || e.target.closest('[data-close]')) box.remove(); });
  document.body.append(box);
}

// Launch the game in LiveContainer (its .app folder name is read from the IPA by the server).
async function playInstalled(slug, version) {
  const entry = installedList().find(x => x.slug === slug);
  let name = entry && entry.bundle;
  if (!name) {
    name = await fetchBundleName(slug, version);
    if (name) installedSetBundle(slug, name);
  }
  // The IPA couldn't be read: LiveContainer names the game after the app itself, so try the store name without the trailing "IPA".
  if (!name && entry && entry.name) {
    const guess = entry.name.replace(/\s*[(\[]?IPA[)\]]?\s*$/i, '').trim();
    if (guess) name = guess + '.app';
  }
  if (name) { openLiveContainer('livecontainer://livecontainer-launch?bundle-name=' + encodeURIComponent(name)); return; }
  alert('Could not find this game inside LiveContainer automatically. LiveContainer will open: tap the game there to play.');
  openLiveContainer('livecontainer://');
}

function tabBarHTML(active = '') {
  return TABS.filter(t => t[0] !== 'installed' || IN_APP || ON_IOS).map(([key, label, href, icon]) => `<a href="${href}" data-tab="${key}"${key === active ? ' aria-current="page"' : ''}>
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
      try {
        sessionStorage.removeItem('me');
        localStorage.setItem('me_active', '0');
      } catch {}
      if (!isPublicPage()) {
        location.replace(loginUrl());
        return;
      }
      return;
    }
    fill(r.user);
    try {
      localStorage.setItem('me_active', '1');
      sessionStorage.setItem('me', JSON.stringify({ username: r.user.username, avatar: r.user.avatar }));
    } catch {}
  }).catch(err => {
    if (err && err.status === 401 && !isPublicPage()) {
      try { localStorage.setItem('me_active', '0'); } catch {}
      location.replace(loginUrl());
    }
  });
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

// ---- Push notifications (new releases) ----
// Works in the Home Screen app (also the old Web Clip) on iOS 16.4+ and in desktop/Android browsers.
const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

async function pushCurrent() {
  const reg = pushSupported && await navigator.serviceWorker.getRegistration();
  return reg ? reg.pushManager.getSubscription() : null;
}

/** Asks for permission (must run inside a tap), subscribes this device and tells the server. */
async function pushSubscribe() {
  if (await Notification.requestPermission() !== 'granted') throw new Error('Notifications are blocked. Allow them in Settings → Notifications.');
  const reg = await navigator.serviceWorker.register(BASE + 'sw.js');
  await navigator.serviceWorker.ready;
  const k = await api('push_key');
  if (!k.ready) throw new Error('Notifications are not available yet.');
  const key = Uint8Array.from(atob(k.key.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  await api('push_subscribe', { body: sub.toJSON() });
  try { localStorage.removeItem('pushOff'); } catch {}
}

async function pushUnsubscribe() {
  try { localStorage.setItem('pushOff', '1'); } catch {}      // the member switched it off: do not turn it on again by itself
  const sub = await pushCurrent();
  if (sub) { await api('push_unsubscribe', { body: { endpoint: sub.endpoint } }); await sub.unsubscribe(); }
}

/**
 * Notifications are on by default: the first tap on a signed-in page opens the system "Allow notifications?" prompt
 * (iOS only allows that inside a tap). Devices that already allowed it just re-register quietly once per session,
 * so a different member signing in on the same phone gets the notifications too.
 */
function pushAutoEnable() {
  if (!pushSupported || document.body.dataset.auth !== 'required') return;
  let off = false;
  try { off = !!localStorage.getItem('pushOff'); } catch {}
  if (off || Notification.permission === 'denied') return;
  if (Notification.permission === 'granted') {
    let seen = false;
    try { seen = !!sessionStorage.getItem('pushSynced'); sessionStorage.setItem('pushSynced', '1'); } catch {}
    if (!seen) pushSubscribe().catch(() => {});
    return;
  }
  document.addEventListener('click', () => pushSubscribe().catch(() => {}), { once: true, capture: true });
}
document.addEventListener('DOMContentLoaded', pushAutoEnable);
