/* ================================================
   ARKCHEAT PANEL — Core JS v7.0 (Marine UI)
   ================================================ */

// ── TOAST ──────────────────────────────────────
function toast(msg, type='s', dur=3200) {
  const c = document.getElementById('toasts');
  if (!c) return;
  const cfg = {
    s: { color:'#059669', bg:'rgba(16,185,129,.16)',  icon:'✓' },
    e: { color:'#b4263a', bg:'rgba(224,65,79,.16)',   icon:'✕' },
    w: { color:'#8a5e12', bg:'rgba(198,135,26,.18)',  icon:'!' },
    i: { color:'#2d5fb0', bg:'rgba(59,118,214,.16)',  icon:'i' }
  };
  const { color, bg, icon } = cfg[type] || cfg.i;
  const t = document.createElement('div');
  t.className = 'toast';
  t.style.cssText = `animation:toastIn .25s cubic-bezier(.34,1.3,.64,1)`;
  t.innerHTML = `
    <span style="width:22px;height:22px;border-radius:50%;background:${bg};color:${color};font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0">${icon}</span>
    <span style="flex:1;min-width:0;font-size:13.5px">${msg}</span>
    <span style="color:rgba(40,33,24,.35);font-size:16px;cursor:pointer;line-height:1;flex-shrink:0" onclick="this.closest('.toast').remove()">×</span>
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

// ── DARK MODE (eclipse / "grehan" transition) ──
function toggleTheme(btn) {
  const root = document.documentElement;
  const goingDark = root.getAttribute('data-theme') !== 'dark';
  const apply = () => {
    root.setAttribute('data-theme', goingDark ? 'dark' : 'light');
    try { localStorage.setItem('hb_theme', goingDark ? 'dark' : 'light'); } catch (e) {}
  };

  if (prefersReducedMotion()) { apply(); return; }

  // Origin = the toggle button (the eclipse grows from there)
  let ox = '50%', oy = '50%';
  if (btn && btn.getBoundingClientRect) {
    const r = btn.getBoundingClientRect();
    ox = (r.left + r.width / 2) + 'px';
    oy = (r.top + r.height / 2) + 'px';
  }
  let ecl = document.getElementById('eclipse');
  if (!ecl) { ecl = document.createElement('div'); ecl.id = 'eclipse'; document.body.appendChild(ecl); }
  ecl.style.left = ox; ecl.style.top = oy;
  ecl.classList.remove('go'); void ecl.offsetWidth; ecl.classList.add('go');

  // Slow, whole-page colour cross-fade (~2.2s) + flip theme as the shadow lands
  root.classList.add('theme-anim');
  setTimeout(apply, 160);
  setTimeout(() => root.classList.remove('theme-anim'), 2500);
}

// ── NUMBER COUNT-UP (dashboard stats / KPI values) ──
function animateCounts() {
  if (prefersReducedMotion()) return;
  document.querySelectorAll('.hero-v, .b-v, .kpi-val').forEach(el => {
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

// ── MOBILE SIDEBAR ─────────────────────────────
function toggleSidebar() {
  const sb = document.querySelector('.sidebar');
  const ov = document.getElementById('mobOverlay');
  if (sb) sb.classList.toggle('open');
  if (ov) ov.classList.toggle('show');
}
document.addEventListener('DOMContentLoaded', () => {
  const ov = document.getElementById('mobOverlay');
  if (ov) ov.addEventListener('click', () => {
    document.querySelector('.sidebar')?.classList.remove('open');
    ov.classList.remove('show');
  });
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
    if (el && stats[key] !== undefined) el.textContent = stats[key];
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
