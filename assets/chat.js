// Live support chat. Store pages call mountChat() for the floating chat button; the admin panel
// reuses chatLogHTML / chatComposer for its Support tab. Needs common.js.

const CHAT_SVG = '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3c5 0 9 3.4 9 7.7s-4 7.7-9 7.7c-.9 0-1.8-.1-2.6-.3L5 20.5c-.5.2-1-.2-.9-.7l.6-3.3C3.6 15.1 3 13 3 10.7 3 6.4 7 3 12 3z"/></svg>';
const SEND_SVG = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21 12.8a.9.9 0 0 0 0-1.6L3.4 3.6a.7.7 0 0 0-1 .8L4.6 11 14 12l-9.4 1-2.2 6.6a.7.7 0 0 0 1 .8z"/></svg>';
const CLOSE_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';

function chatTime(iso) {
  return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function chatDay(iso) {
  const d = new Date(iso);
  const days = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000);
  if (days === 0) return 'Today';
  if (days === 1) return 'Yesterday';
  return d.toLocaleDateString(undefined, { weekday: days < 7 ? 'long' : undefined, day: 'numeric', month: 'short',
    year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}

// Escaped text with web links made clickable.
function chatText(s) {
  return esc(s).replace(/\bhttps?:\/\/[^\s<]+[^\s<.,:;!?)'"]/g, u => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);
}

// Message bubbles with day separators. `mine` is 'user' or 'admin' (the side whose bubbles go right);
// `seen` is the highest id the other side has read, shown as "Seen" under your last message.
function chatLogHTML(messages, mine, seen) {
  let day = '';
  const lastMine = [...messages].reverse().find(m => m.from === mine);
  return messages.map((m, i) => {
    const d = chatDay(m.at);
    const sep = d !== day ? `<div class="chat-day">${esc(d)}</div>` : '';
    day = d;
    const next = messages[i + 1];
    const tail = !next || next.from !== m.from || chatDay(next.at) !== d;   // last bubble of a run shows the time
    const meta = tail ? `<div class="chat-meta">${esc(chatTime(m.at))}${m === lastMine && seen >= m.id ? ' · Seen' : ''}</div>` : '';
    return `${sep}<div class="chat-msg ${m.from === mine ? 'me' : 'them'}${tail ? ' tail' : ''}">
      <div class="chat-bubble">${chatText(m.body)}</div>${meta}</div>`;
  }).join('');
}

// Re-renders a log, staying pinned to the bottom if the reader was already there.
function chatPaint(log, html, forceBottom) {
  const atBottom = forceBottom || log.scrollHeight - log.scrollTop - log.clientHeight < 60;
  log.innerHTML = html;
  if (atBottom) log.scrollTop = log.scrollHeight;
}

// Wires a composer form: auto-growing textarea, Enter sends (Shift+Enter = new line) on devices with a keyboard.
function chatComposer(form, onSend) {
  const ta = form.querySelector('textarea');
  const btn = form.querySelector('button[type="submit"]');
  const grow = () => {
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight + 2, 140) + 'px';
    ta.style.overflowY = ta.scrollHeight + 2 > 140 ? 'auto' : 'hidden';   // scrollbar only once it stops growing
  };
  const sync = () => { btn.disabled = !ta.value.trim() || form.busy; };
  ta.addEventListener('input', () => { grow(); sync(); });
  ta.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && matchMedia('(hover: hover)').matches) {
      e.preventDefault();
      form.requestSubmit();
    }
  });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const text = ta.value.trim();
    if (!text || form.busy) return;
    form.busy = true;
    sync();
    try {
      await onSend(text);
      ta.value = '';
      grow();
    } catch (err) {
      toast(err.message, true);
    } finally {
      form.busy = false;
      sync();
      ta.focus();
    }
  });
  sync();
  return { reset: () => { ta.value = ''; grow(); sync(); }, focus: () => ta.focus() };
}

function composerHTML(placeholder) {
  return `<textarea rows="1" maxlength="2000" placeholder="${esc(placeholder)}" aria-label="Message"></textarea>
    <button type="submit" class="chat-send" aria-label="Send">${SEND_SVG}</button>`;
}

// Floating "Support" button + chat panel for signed-in members. Returns a function that removes it again.
function mountChat() {
  const POLL_OPEN = 4000, POLL_CLOSED = 30000;
  let messages = [], seen = 0, open = false, loaded = false, timer = 0, busy = false, gone = false;

  const fab = document.createElement('button');
  fab.type = 'button';
  fab.className = 'chat-fab';
  fab.setAttribute('aria-label', 'Chat with support');
  fab.innerHTML = CHAT_SVG + '<span class="chat-badge" hidden></span>';

  const panel = document.createElement('section');
  panel.className = 'chat-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Support chat');
  panel.hidden = true;
  panel.innerHTML = `
    <header class="chat-head">
      <span class="chat-logo"><img src="${esc(asset('assets/icons/logo-mark.png'))}" alt=""></span>
      <div class="chat-title"><strong>Support</strong><span>We usually reply within a few hours</span></div>
      <button type="button" class="icon-btn" data-close aria-label="Close chat">${CLOSE_SVG}</button>
    </header>
    <div class="chat-log" aria-live="polite"></div>
    <form class="chat-form">${composerHTML('Message')}</form>`;
  document.body.append(fab, panel);

  const log = panel.querySelector('.chat-log');
  const badge = fab.querySelector('.chat-badge');
  const composer = chatComposer(panel.querySelector('.chat-form'), async text => {
    const r = await api('chat_send', { body: { body: text } });
    add([r.message]);
    paint(true);
  });

  const lastId = () => messages.length ? messages[messages.length - 1].id : 0;
  function add(list) {
    const have = new Set(messages.map(m => m.id));
    messages.push(...list.filter(m => !have.has(m.id)));
    messages.sort((a, b) => a.id - b.id);
  }
  function paint(forceBottom) {
    chatPaint(log, messages.length ? chatLogHTML(messages, 'user', seen)
      : `<div class="chat-hello"><span>👋</span><strong>Hi! How can we help?</strong>
          <p>Ask about a game, a download that won't install or your account. We'll answer right here.</p></div>`, forceBottom);
  }
  function setBadge(n) {
    badge.hidden = !n;
    badge.textContent = n > 9 ? '9+' : n;
    fab.setAttribute('aria-label', n ? `Chat with support (${n} unread)` : 'Chat with support');
  }

  async function poll() {
    clearTimeout(timer);
    if (gone) return;
    if (!document.hidden && !busy) {
      busy = true;
      try {
        const r = await api('chat', { params: open ? { after: lastId() } : { peek: 1 } });
        if (open) {
          add(r.messages);
          seen = r.seen;
          const first = !loaded;
          loaded = true;
          paint(first);
          setBadge(0);
        } else {
          setBadge(r.unread);
        }
      } catch (err) {
        if (err.status === 403 || err.status === 401) { unmount(); return; }
      } finally {
        busy = false;
      }
    }
    timer = setTimeout(poll, open ? POLL_OPEN : POLL_CLOSED);
  }

  function setOpen(v, focus = true) {
    open = v;
    panel.hidden = !v;
    fab.classList.toggle('is-open', v);
    document.documentElement.classList.toggle('chat-open', v);
    try { v ? sessionStorage.setItem('chatOpen', '1') : sessionStorage.removeItem('chatOpen'); } catch {}
    if (v) {
      if (!loaded) log.innerHTML = '<div class="chat-loading" role="status" aria-label="Loading"></div>';
      if (focus) composer.focus();
    }
    poll();
  }

  fab.addEventListener('click', () => setOpen(!open));
  panel.querySelector('[data-close]').addEventListener('click', () => { setOpen(false); fab.focus(); });
  panel.addEventListener('keydown', e => { if (e.key === 'Escape') { setOpen(false); fab.focus(); } });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  // Stay open across page loads on wide screens (on phones the panel covers the page, so start closed).
  let reopen = false;
  try { reopen = sessionStorage.getItem('chatOpen') === '1' && matchMedia('(min-width: 600px)').matches; } catch {}
  setOpen(reopen, false);

  function unmount() {
    gone = true;
    clearTimeout(timer);
    fab.remove();
    panel.remove();
    document.documentElement.classList.remove('chat-open');
  }
  return unmount;
}
