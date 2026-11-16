<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX (before any HTML output) ──────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    requireLogin();
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $ulevel2 = (int)($_SESSION['ulevel']??99);
    $uname2  = $_SESSION['uname']??'';
    // Branch isolation: everyone (owner included) only sees keys in their branch.
    // Non-owners are further limited to keys they registered.
    $branchSql = " AND branch='".addslashes(currentBranch())."'";
    $whereOwn = $branchSql . ($ulevel2>1 ? " AND registrator='".addslashes($uname2)."'" : '');

    switch($_POST['action']){
        case 'block':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET status=0 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'BLOCK_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key blocked.']); exit;
        case 'unblock':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET status=1 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'UNBLOCK_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key unblocked.']); exit;
        case 'delete':
            $kid=(int)($_POST['kid']??0);
            db()->q("DELETE FROM keys_code WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'DELETE_KEY',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Key deleted.']); exit;
        case 'reset_device':
            $kid=(int)($_POST['kid']??0);
            db()->q("UPDATE keys_code SET devices=NULL,device_count=0 WHERE id=?{$whereOwn}",[$kid]);
            logAct($_SESSION['uid'],$uname2,'RESET_DEVICE',"ID:$kid");
            echo json_encode(['ok'=>true,'msg'=>'Device reset.']); exit;
        case 'edit_key':
            // Permission: owner/admin always; reseller only if owner enabled the toggle
            $resellerCanEdit = getSetting('reseller_can_edit_keys','0')==='1';
            if ($ulevel2 > 2 && !$resellerCanEdit) {
                echo json_encode(['ok'=>false,'msg'=>'Editing keys is disabled for resellers.']); exit;
            }
            $kid    = (int)($_POST['kid']??0);
            $newMax = max(1,min(99999,(int)($_POST['max_devices']??1)));
            $newKey = trim($_POST['new_key']??'');
            // Fetch current key (scoped for non-owner)
            $cur = db()->one("SELECT * FROM keys_code WHERE id=?{$whereOwn}",[$kid]);
            if (!$cur) { echo json_encode(['ok'=>false,'msg'=>'Key not found or not yours.']); exit; }
            // If renaming, keep it EXACT and ensure uniqueness
            if ($newKey!=='' && $newKey!==$cur->user_key) {
                if (!preg_match('/^[A-Za-z0-9_\-]{3,64}$/',$newKey)) {
                    echo json_encode(['ok'=>false,'msg'=>'Key must be 3-64 chars: letters, numbers, - or _']); exit;
                }
                if (db()->one("SELECT id FROM keys_code WHERE user_key=? AND id<>?",[$newKey,$kid])) {
                    echo json_encode(['ok'=>false,'msg'=>'That key text already exists.']); exit;
                }
                db()->q("UPDATE keys_code SET user_key=?, max_devices=? WHERE id=?{$whereOwn}",[$newKey,$newMax,$kid]);
                logAct($_SESSION['uid'],$uname2,'EDIT_KEY',"ID:$kid rename→$newKey dev→$newMax");
            } else {
                db()->q("UPDATE keys_code SET max_devices=? WHERE id=?{$whereOwn}",[$newMax,$kid]);
                logAct($_SESSION['uid'],$uname2,'EDIT_KEY',"ID:$kid dev→$newMax");
            }
            echo json_encode(['ok'=>true,'msg'=>'Key updated.']); exit;
        case 'delete_expired':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE expired_date IS NOT NULL AND expired_date<NOW()$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE expired_date IS NOT NULL AND expired_date<NOW()$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_EXPIRED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c expired keys."]); exit;
        case 'delete_unused':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE device_count=0 AND status=1$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE device_count=0 AND status=1$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_UNUSED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c unused keys."]); exit;
        case 'delete_blocked':
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE status=0$whereOwn")->c??0);
            db()->q("DELETE FROM keys_code WHERE status=0$whereOwn");
            logAct($_SESSION['uid'],$uname2,'DELETE_BLOCKED',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"Deleted $c blocked keys."]); exit;
        case 'reset_all':
            $w=" WHERE branch='".addslashes(currentBranch())."'".($ulevel2>1?" AND registrator='".addslashes($uname2)."'":'');
            db()->q("UPDATE keys_code SET devices=NULL,device_count=0$w");
            logAct($_SESSION['uid'],$uname2,'RESET_ALL_DEVICES','');
            echo json_encode(['ok'=>true,'msg'=>'All devices reset.']); exit;
        case 'delete_all':
            if($ulevel2!==1){echo json_encode(['ok'=>false,'msg'=>'Owner only.']);exit;}
            // Branch-scoped delete (NOT truncate — truncate would wipe every branch)
            $c=(int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[currentBranch()])->c??0);
            db()->q("DELETE FROM keys_code WHERE branch=?",[currentBranch()]);
            logAct($_SESSION['uid'],$uname2,'DELETE_ALL_KEYS',"$c keys");
            echo json_encode(['ok'=>true,'msg'=>"All $c keys deleted."]); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── PAGE LOAD ──────────────────────────────
$pageTitle = 'All Keys';
$activeNav = 'allkeys';
require_once __DIR__.'/app/Views/Layout/header.php';

// Who can edit keys: owner/admin always; reseller only if owner enabled it
$canEditKeys = ($ulevel <= 2) || (getSetting('reseller_can_edit_keys','0')==='1');

// Pagination & filters
$pp=20; $pg=max(1,(int)($_GET['p']??1)); $off=($pg-1)*$pp;
$q=trim($_GET['q']??''); $st=$_GET['st']??'';
$where = $ulevel>1 ? "WHERE registrator='".addslashes($uname)."'" : 'WHERE 1=1';
$where .= " AND branch='".addslashes(currentBranch())."'";  // branch isolation
$params=[];
if($q){$where.=" AND (user_key LIKE ? OR game LIKE ? OR registrator LIKE ?)";$params=["%$q%","%$q%","%$q%"];}
if($st==='active') $where.=' AND status=1 AND (expired_date IS NULL OR expired_date>NOW())';
if($st==='unused') $where.=' AND device_count=0 AND status=1';
if($st==='expired') $where.=' AND expired_date IS NOT NULL AND expired_date<NOW()';
if($st==='blocked') $where.=' AND status=0';

$total=(int)(db()->one("SELECT COUNT(*) c FROM keys_code $where",$params)->c??0);
$keys=db()->all("SELECT * FROM keys_code $where ORDER BY created_at DESC LIMIT $pp OFFSET $off",$params);
$pages=(int)ceil($total/$pp);

function kStatus($k){
    if(!$k->status) return ['Blocked','b-ban'];
    if($k->expired_date&&strtotime($k->expired_date)<time()) return ['Expired','b-exp'];
    if(!$k->device_count) return ['Unused','b-unused'];
    return ['Active','b-active'];
}
$s='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>
      All Keys <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=$total?>)</span>
    </span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
      <div class="sbar" style="width:220px">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;flex-shrink:0;margin-right:7px;opacity:.4"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="keySearch" placeholder="Search key, game, user..." value="<?=htmlspecialchars($q)?>">
      </div>
      <select class="fi" id="stF" style="width:120px;padding:7px 10px;font-size:11px" onchange="applyF()">
        <option value="">All Status</option>
        <option value="active" <?=$st==='active'?'selected':''?>>Active</option>
        <option value="unused" <?=$st==='unused'?'selected':''?>>Unused</option>
        <option value="expired" <?=$st==='expired'?'selected':''?>>Expired</option>
        <option value="blocked" <?=$st==='blocked'?'selected':''?>>Blocked</option>
      </select>

      <!-- ACTIONS RIGHT - OWNER ONLY -->
      <?php if($ulevel===1): ?>
      <div style="position:relative" id="actWrap">
        <button class="btn btn-acc btn-sm" onclick="toggleAct()" id="actBtn" style="background:var(--acc);color:#000;font-weight:800">
          <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          Actions
          <svg viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="11" height="11" id="actChev"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="actMenu" style="display:none;position:fixed;right:16px;top:auto;background:var(--s3);border:1px solid var(--line2);border-radius:var(--r2);padding:6px;min-width:228px;z-index:9999;box-shadow:0 20px 50px rgba(0,0,0,.6)">
          <a href="/generate.php" class="act-item">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>
            Generate Keys
          </a>
          <div class="act-item-sep"></div>
          <button class="act-item" onclick="bulkAct('delete_expired')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Delete Expired Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_unused')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Delete Unused Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_blocked')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            Delete Blocked Keys
          </button>
          <button class="act-item" onclick="bulkAct('reset_all')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
            Reset All Devices
          </button>
          <?php if($ulevel===1): ?>
          <div class="act-item-sep" style="background:rgba(255,77,109,.2)"></div>
          <button class="act-item" style="color:#ff4d6d" onclick="bulkAct('delete_all')">
            <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
            Delete All Keys
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; // close owner-only Actions button ?>
    </div>
  </div>

  <div class="tw">
    <table id="keysTable">
      <thead><tr>
        <th>#</th><th>License Key</th><th>Game</th><th>Devices</th><th>Duration</th>
        <?php if($ulevel===1): ?><th>Owner</th><?php endif; ?>
        <th>Expires</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if(empty($keys)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">No keys found</td></tr>
        <?php else: $i=$off+1; foreach($keys as $k):
          [$st2,$sc]=kStatus($k);
          $expColor=($k->expired_date&&strtotime($k->expired_date)<time())?'color:var(--danger)':'';
        ?>
        <tr id="kr-<?=$k->id?>">
          <td style="color:var(--muted)"><?=$i++?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <code class="kcode" style="font-size:10px"><?=htmlspecialchars($k->user_key)?></code>
              <button class="btn btn-ghost btn-xs" onclick="copyText('<?=htmlspecialchars($k->user_key)?>','Key')" title="Copy">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              </button>
            </div>
          </td>
          <td><?=htmlspecialchars($k->game)?></td>
          <td style=""><?=(int)$k->device_count?>/<?=(int)$k->max_devices?></td>
          <td style=""><?php $h=(int)$k->duration;echo $h<24?"{$h}H":round($h/24)."D";?></td>
          <?php if($ulevel===1): ?><td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($k->registrator)?></td><?php endif; ?>
          <td style="font-size:11px;<?=$expColor?>"><?=$k->expired_date?date('d M Y',strtotime($k->expired_date)):'∞'?></td>
          <td><span class="bx <?=$sc?>"><?=$st2?></span></td>
          <td>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?php if($k->status): ?>
              <button class="btn btn-warn btn-xs" onclick="kAct(<?=$k->id?>,'block')" title="Block">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
              </button>
              <?php else: ?>
              <button class="btn btn-acc btn-xs" onclick="kAct(<?=$k->id?>,'unblock')" title="Unblock">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="20 6 9 17 4 12"/></svg>
              </button>
              <?php endif; ?>
              <button class="btn btn-blue btn-xs" onclick="kAct(<?=$k->id?>,'reset_device')" title="Reset Device">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>
              </button>
              <?php if($canEditKeys): ?>
              <button class="btn btn-ghost btn-xs" onclick="openEditKey(<?=$k->id?>,'<?=htmlspecialchars(addslashes($k->user_key))?>',<?=(int)$k->max_devices?>)" title="Edit Key">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
              </button>
              <?php endif; ?>
              <button class="btn btn-red btn-xs" onclick="confirmDo('Delete this key?',()=>kAct(<?=$k->id?>,'delete'))" title="Delete">
                <svg viewBox="0 0 24 24" <?=$s?> width="10" height="10"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach;endif;?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?>
  <div class="pagination">
    <?php for($p2=1;$p2<=$pages;$p2++): ?>
    <a href="?p=<?=$p2?>&q=<?=urlencode($q)?>&st=<?=urlencode($st)?>" class="pg-btn <?=$p2===$pg?'on':''?>"><?=$p2?></a>
    <?php endfor;?>
  </div>
  <?php endif;?>
</div>

<style>
.act-item{display:flex;align-items:center;gap:11px;width:100%;padding:9px 12px;background:none;border:none;border-radius:var(--r4);color:var(--text2);font-family:var(--body);font-size:13px;font-weight:450;cursor:pointer;transition:.13s;text-decoration:none;text-align:left;white-space:nowrap}
.act-item:hover{background:rgba(255,255,255,.05);color:var(--text)}
.act-item:hover svg{opacity:1}
.act-item svg{flex-shrink:0;opacity:.6;stroke:currentColor}
.act-item-sep{height:1px;background:var(--line);margin:5px 0}
</style>

<!-- DELETE ALL KEYS MODAL -->
<div class="modal-bg" id="deleteAllKeysModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> DELETE ALL KEYS</div><button class="mcls" onclick="closeModal('deleteAllKeysModal')">×</button></div>
    <div class="mbody">
      <div style="text-align:center;padding:8px 0 16px">
        <div style="width:54px;height:54px;margin:0 auto 14px;background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.3);border-radius:var(--r);display:flex;align-items:center;justify-content:center">
          <svg viewBox="0 0 24 24" fill="none" stroke="#ff4d6d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        </div>
        <div style="font-size:15px;font-weight:700;color:var(--danger);margin-bottom:6px">This cannot be undone</div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6"><strong style="color:var(--text)">Every license key</strong> in the database will be<br>permanently deleted. All active users will lose access.</div>
      </div>
      <div style="background:rgba(255,77,109,.06);border:1px solid rgba(255,77,109,.2);border-radius:var(--r);padding:12px 14px;font-size:11px;color:var(--muted2)">
        <strong style="color:var(--danger)">⚠ Warning:</strong> Type <code style="color:var(--danger);background:rgba(255,77,109,.1);padding:1px 5px;border-radius:var(--r2)">DELETE</code> below to confirm.
      </div>
      <input class="fi fi-mono" id="dak-confirm" placeholder="Type DELETE to confirm" oninput="document.getElementById('dak-btn').disabled=this.value!=='DELETE'" style="margin-top:12px;color:var(--danger);letter-spacing:2px">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteAllKeysModal')">Cancel</button>
      <button class="btn btn-red" id="dak-btn" disabled onclick="doDeleteAllKeys()">Delete All Keys</button>
    </div>
  </div>
</div>

<!-- EDIT KEY MODAL -->
<div class="modal-bg" id="editKeyModal">
  <div class="modal">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> EDIT KEY</div><button class="mcls" onclick="closeModal('editKeyModal')">×</button></div>
    <div class="mbody">
      <input type="hidden" id="ek-id">
      <div class="fg">
        <label class="fl">Key Text</label>
        <input class="fi fi-mono" id="ek-key" placeholder="Key text (3-64 chars)">
        <div class="fi-hint">Letters, numbers, - or _ . Leave as-is to keep the same key.</div>
      </div>
      <div class="fg">
        <label class="fl">Max Devices</label>
        <input class="fi fi-mono" id="ek-max" type="number" min="1" max="99999">
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('editKeyModal')">Cancel</button>
      <button class="btn btn-acc" id="ek-save" onclick="saveEditKey()">Save Changes</button>
    </div>
  </div>
</div>

<script>
const csrf=document.querySelector('meta[name="csrf"]').content;
async function kAct(id,action){
  const r=await formPost('/keys.php',{action,kid:id,csrf});
  if(r.ok){toast(r.msg,'s');if(action==='delete')document.getElementById('kr-'+id)?.remove();else setTimeout(()=>location.reload(),500);}
  else toast(r.msg,'e');
}
function openEditKey(id,key,max){
  document.getElementById('ek-id').value=id;
  document.getElementById('ek-key').value=key;
  document.getElementById('ek-max').value=max;
  openModal('editKeyModal');
}
async function saveEditKey(){
  const id=document.getElementById('ek-id').value;
  const newKey=document.getElementById('ek-key').value.trim();
  const max=document.getElementById('ek-max').value;
  const btn=document.getElementById('ek-save'); btn.disabled=true;
  const r=await formPost('/keys.php',{action:'edit_key',kid:id,new_key:newKey,max_devices:max,csrf});
  btn.disabled=false;
  if(r.ok){toast(r.msg,'s');closeModal('editKeyModal');setTimeout(()=>location.reload(),700);}
  else toast(r.msg,'e');
}
async function bulkAct(action){
  closeAct();
  if(action==='delete_all'){
    document.getElementById('dak-confirm').value='';
    document.getElementById('dak-btn').disabled=true;
    openModal('deleteAllKeysModal');
    return;
  }
  try {
    const r = await formPost('/keys.php', {action: action, csrf: csrf});
    if(r.ok){
      toast(r.msg, 's');
      setTimeout(()=>location.reload(), 1200);
    } else {
      toast(r.msg || 'Error', 'e');
    }
  } catch(e){
    toast('Error: '+e.message, 'e');
  }
}
async function doDeleteAllKeys(){
  try {
    const r=await formPost('/keys.php',{action:'delete_all',csrf});
    if(r.ok){toast(r.msg,'s');closeModal('deleteAllKeysModal');setTimeout(()=>location.reload(),1200);}
    else toast(r.msg||'Error','e');
  } catch(e){toast('Error: '+e.message,'e');}
}
function toggleAct(){
  const m = document.getElementById('actMenu');
  const c = document.getElementById('actChev');
  const btn = document.getElementById('actBtn');
  const open = m.style.display === 'block';
  if(!open){
    // Position fixed relative to button
    const rect = btn.getBoundingClientRect();
    m.style.top  = (rect.bottom + 8) + 'px';
    m.style.right = (window.innerWidth - rect.right) + 'px';
    m.style.display = 'block';
    m.style.opacity = '0';
    m.style.transform = 'translateY(-6px) scale(.97)';
    m.style.transition = 'opacity .18s,transform .18s';
    requestAnimationFrame(()=>{
      m.style.opacity='1';
      m.style.transform='translateY(0) scale(1)';
    });
  } else {
    m.style.opacity='0';
    m.style.transform='translateY(-4px) scale(.97)';
    setTimeout(()=>{ m.style.display='none'; m.style.opacity=''; m.style.transform=''; },180);
  }
  c.style.transition='transform .2s';
  c.style.transform = open ? '' : 'rotate(180deg)';
}
function closeAct(){
  const m = document.getElementById('actMenu');
  const c = document.getElementById('actChev');
  if(m && m.style.display==='block'){
    m.style.opacity='0';
    m.style.transform='translateY(-4px)';
    setTimeout(()=>{ m.style.display='none'; m.style.opacity=''; m.style.transform=''; },180);
  }
  if(c) c.style.transform='';
}
document.addEventListener('click',e=>{if(!document.getElementById('actWrap')?.contains(e.target))closeAct();});
function applyF(){location.href=`/keys.php?q=${encodeURIComponent(document.getElementById('keySearch').value)}&st=${encodeURIComponent(document.getElementById('stF').value)}`;}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
