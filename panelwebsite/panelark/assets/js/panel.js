/* ================================================
   PANEL — Core JS v10
   ================================================ */

// ── TOAST ──────────────────────────────────────
function toast(msg, type='s', dur=3200) {
  const c = document.getElementById('toasts');
  if (!c) return;
  const cfg = {
    s: { color:'#6ee7b7', bg:'rgba(52,211,153,.18)',  icon:'✓' },
    e: { color:'#fdaab5', bg:'rgba(244,63,94,.22)',   icon:'✕' },
    w: { color:'#f6cf8b', bg:'rgba(240,178,74,.2)',   icon:'!' },
    i: { color:'#9cc2ff', bg:'rgba(110,168,255,.2)',  icon:'i' }
  };
  const { color, bg, icon } = cfg[type] || cfg.i;
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.cssText = `animation:toastIn .25s cubic-bezier(.34,1.3,.64,1)`;
  t.innerHTML = `
    <span style="width:24px;height:24px;border-radius:8px;background:${bg};color:${color};font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">${icon}</span>
    <span style="flex:1;min-width:0;font-size:13.5px">${msg}</span>
    <span style="color:rgba(255,255,255,.45);font-size:17px;cursor:pointer;line-height:1;flex-shrink:0" onclick="this.closest('.toast').remove()">×</span>
  `;
  c.appendChild(t);
  const remove = () => {
    if (!t.parentNode) return;
    t.style.transition = 'opacity .25s,transform .25s';
    t.style.opacity = '0';
    t.style.transform = 'translateX(20px) scale(.95)';
    setTimeout(() => t.remove(), 280);
  };
  t.addEventListener('click', remove);
  setTimeout(remove, dur);
}

// ── MODAL ──────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) { m.classList.remove('open'); document.body.style.overflow = ''; }
}
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-bg')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// ── CONFIRM ────────────────────────────────────
function confirmDo(msg, cb) { if (confirm(msg)) cb(); }

// ── REDUCED MOTION ─────────────────────────────
function prefersReducedMotion() {
  return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

// ── BUTTON RIPPLE ──────────────────────────────
document.addEventListener('click', e => {
  const btn = e.target.closest('.btn');
  if (!btn || prefersReducedMotion()) return;
  const r = btn.getBoundingClientRect();
  const d = Math.max(r.width, r.height);
  const s = document.createElement('span');
  s.className = 'rpl';
  s.style.width = s.style.height = d + 'px';
  s.style.left = (e.clientX - r.left - d / 2) + 'px';
  s.style.top  = (e.clientY - r.top  - d / 2) + 'px';
  btn.appendChild(s);
  setTimeout(() => s.remove(), 600);
});

// ── DARK MODE — GPU circular reveal (View Transitions API: smooth, never stuck) ──
function toggleTheme(btn) {
  const root = document.documentElement;
  const goingDark = root.getAttribute('data-theme') !== 'dark';
  const apply = () => {
    root.setAttribute('data-theme', goingDark ? 'dark' : 'light');
    try { localStorage.setItem('hb_theme', goingDark ? 'dark' : 'light'); } catch (e) {}
  };

  // No View Transitions support (Firefox/old) or reduced motion → instant flip.
  if (prefersReducedMotion() || typeof document.startViewTransition !== 'function') { apply(); return; }

  // Reveal origin = centre of the toggle button.
  let x = window.innerWidth - 44, y = 46;
  if (btn && btn.getBoundingClientRect) {
    const r = btn.getBoundingClientRect();
    x = r.left + r.width / 2;
    y = r.top + r.height / 2;
  }
  const end = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));

  const vt = document.startViewTransition(apply);
  vt.ready.then(() => {
    document.documentElement.animate(
      { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${end}px at ${x}px ${y}px)`] },
      { duration: 520, easing: 'cubic-bezier(.4,0,.2,1)', pseudoElement: '::view-transition-new(root)' }
    );
  }).catch(() => {});
}

// ── UI ENHANCERS ───────────────────────────────
// <select data-ui="tiles">  → tappable option tiles (the select stays in the form, hidden, and in sync)
// <input type="number" data-ui="stepper"> → − / + stepper
const ICO_MINUS = '<svg class="ph" viewBox="0 -960 960 960" fill="currentColor"><path d="M237.37-434.5q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17h485.26q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17H237.37Z"/></svg>';
const ICO_PLUS  = '<svg class="ph" viewBox="0 -960 960 960" fill="currentColor"><path d="M434.5-434.5H237.37q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17H434.5v-197.13q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v197.13h197.13q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17H525.5v197.13q0 19.15-13.17 32.33-13.18 13.17-32.33 13.17t-32.33-13.17q-13.17-13.18-13.17-32.33V-434.5Z"/></svg>';
function enhanceUI(root) {
  (root || document).querySelectorAll('select[data-ui="tiles"]:not([data-enh])').forEach(sel => {
    sel.setAttribute('data-enh', '1');
    const wrap = document.createElement('div');
    wrap.className = 'tiles' + (sel.dataset.wide ? ' tiles-wide' : '');
    wrap.setAttribute('role', 'radiogroup');
    const sync = () => wrap.querySelectorAll('.tile').forEach(t => {
      const on = t.dataset.v === sel.value; t.classList.toggle('on', on); t.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    Array.from(sel.options).forEach(o => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'tile'; b.dataset.v = o.value; b.setAttribute('role', 'radio');
      const t = document.createElement('b'); t.textContent = o.dataset.t || o.textContent.trim(); b.appendChild(t);
      if (o.dataset.s) { const sub = document.createElement('span'); sub.textContent = o.dataset.s; b.appendChild(sub); }
      b.addEventListener('click', () => { sel.value = o.value; sel.dispatchEvent(new Event('change', { bubbles: true })); sync(); });
      wrap.appendChild(b);
    });
    sel.classList.add('ui-hidden'); sel.setAttribute('tabindex', '-1');
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    sel.addEventListener('change', sync); sync();
  });
  (root || document).querySelectorAll('input[data-ui="stepper"]:not([data-enh])').forEach(inp => {
    inp.setAttribute('data-enh', '1');
    const box = document.createElement('div'); box.className = 'stepper';
    inp.parentNode.insertBefore(box, inp);
    const mk = (html, d, label) => { const b = document.createElement('button'); b.type = 'button'; b.innerHTML = html; b.setAttribute('aria-label', label);
      b.addEventListener('click', () => {
        if (inp.readOnly || inp.disabled) return;
        const min = inp.min !== '' ? +inp.min : -Infinity, max = inp.max !== '' ? +inp.max : Infinity;
        inp.value = Math.min(max, Math.max(min, (parseInt(inp.value, 10) || 0) + d));
        inp.dispatchEvent(new Event('input', { bubbles: true })); inp.dispatchEvent(new Event('change', { bubbles: true }));
      }); return b; };
    const minus = mk(ICO_MINUS, -1, 'Decrease'), plus = mk(ICO_PLUS, 1, 'Increase');
    inp.classList.remove('fi');
    box.appendChild(minus); box.appendChild(inp); box.appendChild(plus);
    if (inp.readOnly || inp.disabled) { minus.disabled = plus.disabled = true; }
  });
}
document.addEventListener('DOMContentLoaded', () => enhanceUI());

// ── NUMBER COUNT-UP (dashboard stats / KPI values) ──
function animateCounts() {
  if (prefersReducedMotion()) return;
  document.querySelectorAll('.count, .kpi-val').forEach(el => {
    const raw = el.textContent.trim();
    if (!/^[\d,]+$/.test(raw)) return;                 // only plain numbers
    const target = parseInt(raw.replace(/,/g, ''), 10);
    if (!isFinite(target) || target <= 0) return;
    const dur = 900, t0 = performance.now();
    const fmt = n => n.toLocaleString('en-US');
    el.textContent = '0';
    (function step(now) {
      const p = Math.min(1, (now - t0) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = fmt(Math.round(target * eased));
      if (p < 1) requestAnimationFrame(step);
      else el.textContent = fmt(target);
    })(t0);
  });
}

// ── SIDEBAR (drawer on phones · hover/pin rail on desktop) ──
function isPhoneNav() { return window.matchMedia('(max-width: 860px)').matches; }
function toggleSidebar() {
  const sb = document.querySelector('.sidebar');
  const ov = document.getElementById('mobOverlay');
  if (!sb) return;
  if (isPhoneNav()) {
    const open = sb.classList.toggle('open');
    if (ov) ov.classList.toggle('show', open);
    document.body.style.overflow = open ? 'hidden' : '';
    return;
  }
  const pinned = document.documentElement.classList.toggle('sb-pin');
  try { localStorage.setItem('hb_rail', pinned ? 'pin' : ''); } catch (e) {}
}
document.addEventListener('DOMContentLoaded', () => {
  const sb = document.querySelector('.sidebar');
  const ov = document.getElementById('mobOverlay');
  const close = () => { if (sb) sb.classList.remove('open'); if (ov) ov.classList.remove('show'); document.body.style.overflow = ''; };
  if (ov) ov.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  if (!sb) return;
  // hover-expand (pointer devices only), with a small intent delay
  let t;
  sb.addEventListener('pointerenter', e => { if (e.pointerType === 'mouse' && !isPhoneNav()) { clearTimeout(t); t = setTimeout(() => sb.classList.add('is-open'), 90); } });
  sb.addEventListener('pointerleave', () => { clearTimeout(t); sb.classList.remove('is-open'); });
  sb.addEventListener('focusin', () => { if (!isPhoneNav()) sb.classList.add('is-open'); });
  sb.addEventListener('focusout', e => { if (!sb.contains(e.relatedTarget)) sb.classList.remove('is-open'); });
  // keep the active item in view inside the rail
  const on = sb.querySelector('.ni.on'); if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest' });
});

// ── PASSWORD TOGGLE ────────────────────────────
function togglePass(inputId, btn) {
  const inp = document.getElementById(inputId);
  if (!inp) return;
  const isPass = inp.type === 'password';
  inp.type = isPass ? 'text' : 'password';
  if (btn && btn.querySelector) {
    const svgs = btn.querySelectorAll('svg');
    svgs.forEach(s => s.style.display = 'none');
  }
}

// ── COPY ───────────────────────────────────────
function copyText(text, label='') {
  navigator.clipboard.writeText(text)
    .then(() => toast(label ? `${label} copied!` : 'Copied!', 's', 2000))
    .catch(() => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove();
      toast('Copied!', 's', 2000);
    });
}

// ── AJAX HELPERS ───────────────────────────────
async function apiPost(url, data = {}) {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ ...data, csrf })
    });
    return await r.json();
  } catch(e) { return { ok: false, msg: 'Network error' }; }
}

async function formPost(url, data = {}) {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const body = new URLSearchParams({ ...data, csrf });
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    });
    const text = await r.text();
    try { return JSON.parse(text); }
    catch(e) {
      console.error('Non-JSON response from', url, ':', text.substring(0, 200));
      return { ok: false, msg: 'Server error. Check console.' };
    }
  } catch(e) {
    return { ok: false, msg: 'Connection error: ' + e.message };
  }
}

// ── TABLE SEARCH (live client-side + server-side) ──
function initTableSearch(inputId, tableId, serverUrl) {
  const inp = document.getElementById(inputId);
  if (!inp) return;

  // Client-side live filter (for tables already loaded)
  const tbody = document.querySelector(`#${tableId} tbody`);
  if (tbody) {
    let debounce;
    inp.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => {
        const q = inp.value.toLowerCase().trim();
        let visible = 0;
        tbody.querySelectorAll('tr').forEach(row => {
          const match = !q || row.textContent.toLowerCase().includes(q);
          row.style.display = match ? '' : 'none';
          if (match) visible++;
        });
        // Update count if present
        const countEl = document.getElementById(tableId + 'Count');
        if (countEl) countEl.textContent = q ? visible : (tbody.querySelectorAll('tr').length);
      }, 120);
    });
  }

  // Server-side search on Enter or after 600ms debounce (for paginated results)
  if (serverUrl) {
    let serverDebounce;
    inp.addEventListener('input', () => {
      clearTimeout(serverDebounce);
      serverDebounce = setTimeout(() => {
        if (inp.value.length >= 2 || inp.value.length === 0) {
          const url = new URL(serverUrl, location.origin);
          url.searchParams.set('q', inp.value);
          url.searchParams.set('p', '1');
          location.href = url.toString();
        }
      }, 600);
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        clearTimeout(serverDebounce);
        const url = new URL(serverUrl, location.origin);
        url.searchParams.set('q', inp.value);
        url.searchParams.set('p', '1');
        location.href = url.toString();
      }
    });
  }
}

// ── LOGO UPLOAD ────────────────────────────────
function initLogoUpload() {
  const inp = document.getElementById('logoFile');
  const prev = document.getElementById('logoPrev');
  const wrap = document.getElementById('logoPrevWrap');
  if (!inp) return;
  inp.addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    if (!f.type.startsWith('image/')) { toast('Only image files allowed!', 'e'); return; }
    if (f.size > 2 * 1024 * 1024) { toast('Max file size is 2MB', 'e'); return; }
    const reader = new FileReader();
    reader.onload = ev => {
      if (prev) prev.src = ev.target.result;
      if (wrap) wrap.style.display = 'block';
    };
    reader.readAsDataURL(f);
  });
}

// ── REFERRAL CODE ──────────────────────────────
function refreshRefCode() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code = '';
  for (let i = 0; i < 6; i++) code += chars[Math.floor(Math.random() * chars.length)];
  const el = document.getElementById('refPreview');
  const inp = document.getElementById('refCodeInput');
  if (el) {
    el.style.opacity = '0';
    setTimeout(() => { el.textContent = code; el.style.opacity = '1'; }, 150);
    el.style.transition = 'opacity .15s';
  }
  if (inp) inp.value = code;
  return code;
}

// ── PRICE CALC ─────────────────────────────────
function updateEstimate(priceMap) {
  const devices = parseInt(document.getElementById('maxDevices')?.value) || 1;
  const durSel = document.getElementById('durationSel');
  const estInp = document.getElementById('estimation');
  if (!durSel || !estInp || !priceMap) return;
  const unit = parseFloat(priceMap[durSel.value] || 0);
  estInp.value = `₹ ${(devices * unit).toFixed(2)}`;
}

// ── LIVE STATS ─────────────────────────────────
function startStatsRefresh(interval = 30000) {
  setInterval(async () => {
    try {
      const r = await fetch('/api/stats.php');
      const d = await r.json();
      if (d.ok && d.stats) updateStatCards(d.stats);
    } catch(e) {}
  }, interval);
}
function updateStatCards(stats) {
  const map = { statKeys:'total_keys', statUsers:'total_users', statRefs:'total_refs', statBal:'total_bal' };
  for (const [id, key] of Object.entries(map)) {
    const el = document.getElementById(id);
    if (el && stats[key] !== undefined) el.textContent = isFinite(stats[key]) ? Number(stats[key]).toLocaleString('en-US') : stats[key];
  }
}

// ── PARTICLES ──────────────────────────────────
function spawnParticles(containerId, count = 22) {
  const c = document.getElementById(containerId);
  if (!c) return;
  for (let i = 0; i < count; i++) {
    const p = document.createElement('div');
    p.className = 'pt';
    const s = Math.random() * 2.5 + 1;
    // soft round motes drifting up, tinted across the palette
    const tint = ['16,185,129', '198,135,26', '20,184,166', '132,87,224'][Math.floor(Math.random() * 4)];
    p.style.cssText = `
      left:${Math.random()*100}%;
      width:${s}px;height:${s}px;
      background:rgba(${tint},${Math.random()*.35+.1});
      animation-duration:${Math.random()*14+10}s;
      animation-delay:${Math.random()*10}s
    `;
    c.appendChild(p);
  }
}

// ── INIT ───────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initLogoUpload();
  animateCounts();
  if (document.getElementById('statKeys')) startStatsRefresh();

  // Keys page search — server-side with debounce
  const keySearchInp = document.getElementById('keySearch') || document.getElementById('qs');
  if (keySearchInp && document.getElementById('keysTable')) {
    initTableSearch(keySearchInp.id, 'keysTable', '/keys.php');
  }

  // Users page search — client-side live filter
  initTableSearch('userSearch', 'usersTable');

  // Referral search — client-side
  initTableSearch('refSearch', 'refTable');

  // Logs search — client-side
  initTableSearch('logSearch', 'logsTable');
});
