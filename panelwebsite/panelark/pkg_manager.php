<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLevel(1); // Owner only

// ── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token']); exit;
    }
    $action = $_POST['action'] ?? '';
    $kid    = (int)($_POST['kid'] ?? 0);

    switch ($action) {

        case 'reset_pkg':
            if ($kid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid key ID']); exit; }
            $before = db()->one("SELECT user_key, loader_pkg FROM keys_code WHERE id=?", [$kid]);
            if (!$before) { echo json_encode(['ok'=>false,'msg'=>'Key not found']); exit; }
            db()->q("UPDATE keys_code SET loader_pkg=NULL, is_used=0, first_used=NULL WHERE id=?", [$kid]);
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'RESET_PKG',
                'Key '.$before->user_key.' — unbound from '
                .(($before->loader_pkg ?? '') !== '' ? $before->loader_pkg : '(nothing)')
                .'; will re-bind on next heartbeat');
            echo json_encode(['ok'=>true,'msg'=>'Unbound. The next app to check in will claim this key.','id'=>$kid]);
            exit;

        case 'set_pkg':
            if ($kid <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid key ID']); exit; }
            $pkg = trim($_POST['pkg'] ?? '');
            if ($pkg === '') { echo json_encode(['ok'=>false,'msg'=>'Package name cannot be empty']); exit; }
            if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $pkg)) {
                echo json_encode(['ok'=>false,'msg'=>'Use a format like com.example.app']); exit;
            }
            $before = db()->one("SELECT user_key, loader_pkg FROM keys_code WHERE id=?", [$kid]);
            if (!$before) { echo json_encode(['ok'=>false,'msg'=>'Key not found']); exit; }
            db()->q(
                "UPDATE keys_code SET loader_pkg=?, is_used=1, first_used=COALESCE(first_used, NOW()) WHERE id=?",
                [$pkg, $kid]
            );
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'SET_PKG',
                'Key '.$before->user_key.' — package '
                .(($before->loader_pkg ?? '') !== '' ? $before->loader_pkg : '(unbound)').' -> '.$pkg);
            echo json_encode(['ok'=>true,'msg'=>"Bound to {$pkg}",'id'=>$kid,'pkg'=>$pkg]);
            exit;

        case 'reset_all_pkg':
            $n = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND loader_pkg IS NOT NULL AND loader_pkg!=''",[currentBranch()])->c ?? 0);
            db()->q("UPDATE keys_code SET loader_pkg=NULL, is_used=0, first_used=NULL WHERE branch=?",[currentBranch()]);
            logAct($_SESSION['uid'], $_SESSION['uname']??'', 'RESET_ALL_PKG',
                "Unbound every key — {$n} had a package bound");
            echo json_encode(['ok'=>true,'msg'=>"Unbound {$n} bound key(s)."]);
            exit;

        case 'list':
            $search = trim($_POST['search'] ?? '');
            $filter = $_POST['filter'] ?? 'all';
            $where  = '1=1';
            $params = [];
            if ($search !== '') {
                $where   .= " AND (user_key LIKE ? OR COALESCE(loader_pkg,'') LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            if ($filter === 'bound')   $where .= " AND loader_pkg IS NOT NULL AND loader_pkg != ''";
            if ($filter === 'unbound') $where .= " AND (loader_pkg IS NULL OR loader_pkg = '')";
            $rows = db()->all(
                "SELECT id, user_key, loader_pkg, is_used, status, expired_date, registrator
                 FROM keys_code WHERE $where ORDER BY id DESC LIMIT 300",
                $params
            ) ?? [];
            echo json_encode(['ok'=>true,'rows'=> array_map(fn($r)=>[
                'id'       => $r->id,
                'key_disp' => substr($r->user_key,0,22).'…',
                'full_key' => $r->user_key,
                'pkg'      => $r->loader_pkg ?? '',
                'is_used'  => (int)$r->is_used,
                'status'   => (int)$r->status,
                'expired'  => $r->expired_date ?? '',
                'reg'      => $r->registrator,
            ], $rows)]);
            exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Page render ────────────────────────────────────────────────────────────
$pageTitle = 'Package Manager';
$activeNav = 'pkg_manager';
require_once __DIR__.'/app/Views/Layout/header.php';

$csrf    = csrfToken();
$total   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[currentBranch()])->c ?? 0);
$bound   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND loader_pkg IS NOT NULL AND loader_pkg!=''",[currentBranch()])->c ?? 0);
$unbound = $total - $bound;
$topPkgs = db()->all(
    "SELECT loader_pkg, COUNT(*) cnt FROM keys_code
     WHERE branch=? AND loader_pkg IS NOT NULL AND loader_pkg!=''
     GROUP BY loader_pkg ORDER BY cnt DESC LIMIT 6", [currentBranch()]
) ?? [];
?>
<?php $s='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'; ?>
<style>
.pkg-chip{display:inline-flex;align-items:center;gap:8px;background:var(--acc-08);border:1px solid var(--acc-20);
  border-radius:8px;padding:5px 12px;font-size:12.5px;color:var(--acc);margin:0 6px 6px 0}
.pkg-cnt{background:var(--acc);color:var(--acc-tx);border-radius:8px;padding:1px 8px;font-size:11px;font-weight:600}
</style>

<div class="g3" style="margin-bottom:18px">
  <div class="kpi" style="--kc:var(--acc);--kb:var(--acc-13)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('key',15)?></div><div class="kpi-lbl">Total keys</div></div>
    <div class="kpi-val"><?=number_format($total)?></div>
    <div class="kpi-sub">In the database</div>
  </div>
  <div class="kpi" style="--kc:var(--blue);--kb:rgba(98,145,245,.14)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('lock',15)?></div><div class="kpi-lbl">Bound to an app</div></div>
    <div class="kpi-val"><?=number_format($bound)?></div>
    <div class="kpi-sub">Locked to one package</div>
  </div>
  <div class="kpi" style="--kc:var(--gold);--kb:rgba(252,211,77,.14)">
    <div class="kpi-top"><div class="kpi-ico"><?=svgIcon('shield',15)?></div><div class="kpi-lbl">Not bound yet</div></div>
    <div class="kpi-val"><?=number_format($unbound)?></div>
    <div class="kpi-sub">Free for any app</div>
  </div>
</div>

<div class="alert a-info" style="margin-bottom:16px">
  <?=svgIcon('info',14)?>
  <span>A key locks itself to the first app that checks in. If a customer reinstalls with a
  different package name, <strong>unbind</strong> the key so the new app can claim it.</span>
</div>

<?php if($topPkgs): ?>
<div class="card">
  <div class="card-h"><span class="ct"><?=svgIcon('link',15)?> Packages in use</span></div>
  <div class="card-b" style="padding-bottom:15px">
    <?php foreach($topPkgs as $p): ?>
    <span class="pkg-chip"><?=htmlspecialchars($p->loader_pkg)?><span class="pkg-cnt"><?=$p->cnt?></span></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h">
    <span class="ct"><?=svgIcon('key',15)?> Keys &amp; bindings</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <div class="sbar" style="width:230px">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" style="width:13px;height:13px;flex-shrink:0;margin-right:8px;opacity:.5"><path d="M378.09-314.5q-111.16 0-188.33-77.17-77.17-77.18-77.17-188.33t77.17-188.33q77.17-77.17 188.33-77.17 111.15 0 188.32 77.17 77.18 77.18 77.18 188.33 0 44.48-13.52 83.12-13.53 38.64-36.57 68.16l222.09 222.33q12.67 12.91 12.67 31.94 0 19.04-12.91 31.71-12.68 12.67-31.83 12.67t-31.82-12.67L529.85-364.59q-29.76 23.05-68.64 36.57-38.88 13.52-83.12 13.52Zm0-91q72.84 0 123.67-50.83 50.83-50.82 50.83-123.67t-50.83-123.67q-50.83-50.83-123.67-50.83-72.85 0-123.68 50.83-50.82 50.82-50.82 123.67t50.82 123.67q50.83 50.83 123.68 50.83Z"/></svg>
        <input id="pkgSearch" placeholder="Search key or package...">
      </div>
      <select class="fi" id="pmFilter" style="width:150px;padding:8px 10px;font-size:12.5px">
        <option value="all">All keys</option>
        <option value="bound">Bound only</option>
        <option value="unbound">Unbound only</option>
      </select>
      <button class="btn btn-red btn-sm" onclick="resetAllPkg()"><?=svgIcon('refresh',12)?> Unbind all</button>
    </div>
  </div>
  <div class="tw">
    <table id="pkgTable">
      <thead><tr><th>Key</th><th>Package</th><th>Status</th><th>Binding</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody id="pmList"><tr><td colspan="5" style="text-align:center;padding:36px;color:var(--muted)">Loading&hellip;</td></tr></tbody>
    </table>
  </div>
</div>

<!-- SET PACKAGE MODAL -->
<div class="modal-bg" id="setPkgModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> Set package name</div><button class="mcls" onclick="closeModal('setPkgModal')">&times;</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Key</label><input class="fi fi-mono" id="mKeyDisplay" readonly></div>
      <div class="fg">
        <label class="fl">Package name</label>
        <input class="fi fi-mono" id="mPkgInput" placeholder="com.example.app">
        <div class="fi-hint">Must look like <code>com.example.app</code> — lowercase, at least one dot.</div>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('setPkgModal')">Cancel</button>
      <button class="btn btn-acc" id="mSave" onclick="confirmSetPkg()">Save</button>
    </div>
  </div>
</div>

<script>
let _modalKid = 0;
const ICON_EDIT  = '<svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M157.37-111.87q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33v-102.26q0-18.15 6.84-34.69 6.83-16.53 19.51-29.2l501.17-500.41q12.48-11.72 27.7-17.96 15.21-6.24 31.93-6.24 16.48 0 32.2 6.24 15.71 6.24 27.67 18.72l65.28 65.56q12.48 11.72 18.34 27.56 5.86 15.83 5.86 31.79 0 16.72-5.86 32.05-5.86 15.34-18.34 27.82L324-138.22q-12.67 12.68-29.21 19.51-16.53 6.84-34.68 6.84H157.37ZM698.74-642.5l56-55.76-56.24-56.48-56.24 56 56.48 56.24Z"/></svg>';
const ICON_RESET = '<svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg>';

function esc(v){ const d=document.createElement('div'); d.textContent=v==null?'':String(v); return d.innerHTML; }

async function pmPost(data){ return formPost(location.pathname, {...data, ajax:'1'}); }

async function loadList(){
  const body = document.getElementById('pmList');
  const r = await pmPost({
    action:'list',
    search: document.getElementById('pkgSearch').value,
    filter: document.getElementById('pmFilter').value
  });
  if(!r.ok){ body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:36px;color:var(--coral)">'+esc(r.msg||'Could not load the list.')+'</td></tr>'; return; }
  if(!r.rows || !r.rows.length){ body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:36px;color:var(--muted)">No keys match.</td></tr>'; return; }

  body.innerHTML = r.rows.map(row => `
    <tr id="pk-${row.id}">
      <td><code class="kcode" style="font-size:11px" title="${esc(row.full_key)}">${esc(row.key_disp)}</code></td>
      <td class="pkgcell" style="font-size:12.5px;color:${row.pkg?'var(--text2)':'var(--muted)'}">${row.pkg?esc(row.pkg):'Not bound'}</td>
      <td><span class="bx ${row.status==1?'b-active':'b-ban'}">${row.status==1?'Active':'Blocked'}</span></td>
      <td><span class="bx ${row.pkg?'b-admin':'b-exp'}">${row.pkg?'Bound':'Free'}</span></td>
      <td style="text-align:right;white-space:nowrap">
        <button class="btn btn-ghost btn-xs" data-act="set" data-id="${row.id}" data-key="${esc(row.full_key)}" data-pkg="${esc(row.pkg)}">${ICON_EDIT} Set</button>
        <button class="btn btn-red btn-xs" data-act="reset" data-id="${row.id}">${ICON_RESET} Unbind</button>
      </td>
    </tr>`).join('');
}

// Delegated so freshly rendered rows work without re-binding handlers.
document.getElementById('pmList').addEventListener('click', e => {
  const b = e.target.closest('button[data-act]');
  if(!b) return;
  if(b.dataset.act === 'set')   openSetPkg(b.dataset.id, b.dataset.key, b.dataset.pkg);
  if(b.dataset.act === 'reset') resetPkg(b.dataset.id);
});

let _t;
document.getElementById('pkgSearch').addEventListener('input', () => { clearTimeout(_t); _t = setTimeout(loadList, 250); });
document.getElementById('pmFilter').addEventListener('change', loadList);

async function resetPkg(kid){
  if(!confirm('Unbind this key from its app?\n\nThe next app that checks in will claim it.')) return;
  const r = await pmPost({action:'reset_pkg', kid});
  if(!r.ok){ toast(r.msg,'e'); return; }
  toast(r.msg,'s');
  // Update the row in place — no list reload, no page reload.
  const tr = document.getElementById('pk-'+kid);
  if(tr){
    const c = tr.querySelector('.pkgcell');
    if(c){ c.textContent='Not bound'; c.style.color='var(--muted)'; }
    const badges = tr.querySelectorAll('.bx');
    if(badges[1]){ badges[1].className='bx b-exp'; badges[1].textContent='Free'; }
  }
}

function openSetPkg(kid, fullKey, currentPkg){
  _modalKid = kid;
  document.getElementById('mKeyDisplay').value = fullKey;
  document.getElementById('mPkgInput').value = currentPkg || '';
  openModal('setPkgModal');
  setTimeout(()=>document.getElementById('mPkgInput').focus(), 80);
}

async function confirmSetPkg(){
  const pkg = document.getElementById('mPkgInput').value.trim();
  if(!pkg){ toast('Package name cannot be empty','e'); return; }
  const btn = document.getElementById('mSave'); btn.disabled = true;
  const r = await pmPost({action:'set_pkg', kid:_modalKid, pkg});
  btn.disabled = false;
  if(!r.ok){ toast(r.msg,'e'); return; }
  toast(r.msg,'s');
  closeModal('setPkgModal');
  const tr = document.getElementById('pk-'+_modalKid);
  if(tr){
    const c = tr.querySelector('.pkgcell');
    if(c){ c.textContent=r.pkg; c.style.color='var(--text2)'; }
    const badges = tr.querySelectorAll('.bx');
    if(badges[1]){ badges[1].className='bx b-admin'; badges[1].textContent='Bound'; }
  }
}

async function resetAllPkg(){
  if(!confirm('Unbind EVERY key?\n\nAfter this any app can claim any key on its next check-in. This cannot be undone.')) return;
  const r = await pmPost({action:'reset_all_pkg'});
  if(r.ok){ toast(r.msg,'s'); loadList(); } else toast(r.msg,'e');
}

// panel.js (formPost/toast) loads in the footer, so wait for the DOM before the first fetch
document.addEventListener('DOMContentLoaded', loadList);
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
