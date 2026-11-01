// Live support chat. The account page calls mountChat() for its "Chat with Support" row; the admin panel
// reuses chatLogHTML / chatComposer for its Support tab. Needs common.js.

const SEND_SVG = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 21 12.8a.9.9 0 0 0 0-1.6L3.4 3.6a.7.7 0 0 0-1 .8L4.6 11 14 12l-9.4 1-2.2 6.6a.7.7 0 0 0 1 .8z"/></svg>';
const CLOSE_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
const ATTACH_SVG = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="4"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';

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

// Image zoom lightbox modal
function chatZoomImg(e, src) {
  if (e) e.preventDefault();
  let box = document.getElementById('chatLightbox');
  if (!box) {
    box = document.createElement('div');
    box.id = 'chatLightbox';
    box.className = 'chat-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', 'Image preview');
    box.innerHTML = `
      <div class="chat-lightbox-backdrop"></div>
      <div class="chat-lightbox-content">
        <img src="" alt="Full view">
        <button type="button" class="chat-lightbox-close" aria-label="Close">&times;</button>
        <a href="" target="_blank" rel="noopener noreferrer" class="chat-lightbox-down" title="Open full size" aria-label="Open original">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
        </a>
      </div>`;
    document.body.append(box);
    box.querySelector('.chat-lightbox-backdrop').addEventListener('click', () => box.remove());
    box.querySelector('.chat-lightbox-close').addEventListener('click', () => box.remove());
    document.addEventListener('keydown', function escHandler(ev) {
      if (ev.key === 'Escape') {
        const lb = document.getElementById('chatLightbox');
        if (lb) lb.remove();
        document.removeEventListener('keydown', escHandler);
      }
    });
  }
  box.querySelector('img').src = src;
  const link = box.querySelector('.chat-lightbox-down');
  if (link) link.href = src;
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
    const imgHtml = m.image ? `<a href="${esc(m.image)}" target="_blank" rel="noopener noreferrer" class="chat-bubble-img" onclick="chatZoomImg(event, this.href)"><img src="${esc(m.image)}" alt="Attachment" loading="lazy"></a>` : '';
    const textHtml = m.body ? `<div class="chat-text">${chatText(m.body)}</div>` : '';
    const bubbleClass = 'chat-bubble' + (m.image && !m.body ? ' has-only-img' : '') + (m.image && m.body ? ' has-img-text' : '');
    return `${sep}<div class="chat-msg ${m.from === mine ? 'me' : 'them'}${tail ? ' tail' : ''}">
      <div class="${bubbleClass}">${imgHtml}${textHtml}</div>${meta}</div>`;
  }).join('');
}

// Re-renders a log, staying pinned to the bottom if the reader was already there.
function chatPaint(log, html, forceBottom) {
  const atBottom = forceBottom || log.scrollHeight - log.scrollTop - log.clientHeight < 60;
  log.innerHTML = html;
  if (atBottom) log.scrollTop = log.scrollHeight;
}

// Wires a composer form: auto-growing textarea, image upload/preview, Enter sends (Shift+Enter = new line) on devices with a keyboard.
function chatComposer(form, onSend) {
  const ta = form.querySelector('textarea');
  const btn = form.querySelector('button[type="submit"]');
  const fileInput = form.querySelector('.chat-file-input');
  const preview = form.querySelector('.chat-preview');
  const previewThumb = form.querySelector('.chat-preview-thumb');
  const previewName = form.querySelector('.chat-preview-name');
  const previewRm = form.querySelector('.chat-preview-rm');
  let selectedFile = null;

  const grow = () => {
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight + 2, 140) + 'px';
    ta.style.overflowY = ta.scrollHeight + 2 > 140 ? 'auto' : 'hidden';   // scrollbar only once it stops growing
  };
  const sync = () => { btn.disabled = (!ta.value.trim() && !selectedFile) || form.busy; };

  function setFile(file) {
    if (file && !file.type.startsWith('image/')) {
      toast('Please select an image file (PNG, JPG, WEBP, GIF)', true);
      return;
    }
    if (file && file.size > 10 * 1024 * 1024) {
      toast('Image must be under 10 MB', true);
      return;
    }
    selectedFile = file || null;
    if (selectedFile) {
      const url = URL.createObjectURL(selectedFile);
      if (previewThumb) previewThumb.src = url;
      if (previewName) previewName.textContent = selectedFile.name;
      if (preview) preview.hidden = false;
    } else {
      if (previewThumb) previewThumb.src = '';
      if (previewName) previewName.textContent = '';
      if (preview) preview.hidden = true;
      if (fileInput) fileInput.value = '';
    }
    sync();
  }

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files[0]) {
        setFile(fileInput.files[0]);
      }
    });
  }

  if (previewRm) {
    previewRm.addEventListener('click', () => {
      setFile(null);
      if (fileInput) fileInput.value = '';
    });
  }

  // Paste image from clipboard
  ta.addEventListener('paste', e => {
    const items = e.clipboardData?.items;
    if (!items) return;
    for (const item of items) {
      if (item.type.startsWith('image/')) {
        const f = item.getAsFile();
        if (f) {
          setFile(f);
          break;
        }
      }
    }
  });

  // Drag and drop image
  form.addEventListener('dragover', e => {
    e.preventDefault();
    form.classList.add('drag-over');
  });
  form.addEventListener('dragleave', () => {
    form.classList.remove('drag-over');
  });
  form.addEventListener('drop', e => {
    e.preventDefault();
    form.classList.remove('drag-over');
    const f = e.dataTransfer?.files?.[0];
    if (f && f.type.startsWith('image/')) {
      setFile(f);
    }
  });

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
    if ((!text && !selectedFile) || form.busy) return;
    form.busy = true;
    sync();
    try {
      await onSend(text, selectedFile);
      ta.value = '';
      setFile(null);
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
  return { reset: () => { ta.value = ''; setFile(null); grow(); sync(); }, focus: () => ta.focus() };
}

function composerHTML(placeholder) {
  return `
    <div class="chat-preview" hidden>
      <div class="chat-preview-inner">
        <img class="chat-preview-thumb" src="" alt="Selected image">
        <button type="button" class="chat-preview-rm" aria-label="Remove image">&times;</button>
      </div>
      <span class="chat-preview-name"></span>
    </div>
    <div class="chat-input-row">
      <label class="chat-attach-btn" title="Attach image" aria-label="Attach image">
        <input type="file" class="chat-file-input" accept="image/png,image/jpeg,image/webp,image/gif,image/*" hidden>
        ${ATTACH_SVG}
      </label>
      <textarea rows="1" maxlength="2000" placeholder="${esc(placeholder)}" aria-label="Message"></textarea>
      <button type="submit" class="chat-send" aria-label="Send">${SEND_SVG}</button>
    </div>`;
}

// Support chat panel for signed-in members, opened by `trigger` (an element holding a .chat-badge
// for the unread count). Returns a function that removes it again.
function mountChat(trigger) {
  const POLL_OPEN = 4000, POLL_CLOSED = 30000;
  let messages = [], seen = 0, open = false, loaded = false, timer = 0, busy = false, gone = false;

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
  document.body.append(panel);

  const log = panel.querySelector('.chat-log');
  const badge = trigger.querySelector('.chat-badge');
  const composer = chatComposer(panel.querySelector('.chat-form'), async (text, file) => {
    let r;
    if (file) {
      const fd = new FormData();
      if (text) fd.append('body', text);
      fd.append('image', file);
      r = await api('chat_send', { form: fd });
    } else {
      r = await api('chat_send', { body: { body: text } });
    }
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
    if (!badge) return;
    badge.hidden = !n;
    badge.textContent = n > 9 ? '9+' : n;
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

  function setOpen(v) {
    open = v;
    panel.hidden = !v;
    document.documentElement.classList.toggle('chat-open', v);
    if (v) {
      if (!loaded) log.innerHTML = '<div class="chat-loading" role="status" aria-label="Loading"></div>';
      composer.focus();
    }
    poll();
  }

  const onTrigger = () => setOpen(!open);
  const onVisible = () => { if (!document.hidden) poll(); };
  trigger.addEventListener('click', onTrigger);
  panel.querySelector('[data-close]').addEventListener('click', () => { setOpen(false); trigger.focus(); });
  panel.addEventListener('keydown', e => { if (e.key === 'Escape') { setOpen(false); trigger.focus(); } });
  document.addEventListener('visibilitychange', onVisible);
  poll();

  function unmount() {
    gone = true;
    clearTimeout(timer);
    setBadge(0);
    trigger.removeEventListener('click', onTrigger);
    document.removeEventListener('visibilitychange', onVisible);
    panel.remove();
    document.documentElement.classList.remove('chat-open');
  }
  return unmount;
}
