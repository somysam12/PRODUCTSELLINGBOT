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
  border-radius:20px;padding:5px 12px;font-size:12.5px;color:var(--acc);margin:0 6px 6px 0}
.pkg-cnt{background:var(--acc);color:#062a26;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:600}
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
        <svg viewBox="0 0 24 24" <?=$s?> style="width:13px;height:13px;flex-shrink:0;margin-right:8px;opacity:.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
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
const ICON_EDIT  = '<svg viewBox="0 0 24 24" <?=$s?> width="11" height="11"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
const ICON_RESET = '<svg viewBox="0 0 24 24" <?=$s?> width="11" height="11"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>';

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

loadList();
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
