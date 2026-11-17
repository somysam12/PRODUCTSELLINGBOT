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
      <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M280-360q-50 0-85-35t-35-85q0-50 35-85t85-35q50 0 85 35t35 85q0 50-35 85t-85 35Zm0 127.41q78.67 0 142.59-44.83 63.91-44.84 89.87-118.99h12.56l46.26 46.5q6.96 6.71 15.04 9.57 8.07 2.86 17.03 2.86 8.95 0 16.91-2.86 7.96-2.86 14.91-9.81l50.33-50.33 64.5 52.61q6.96 5.96 15.29 8.94 8.34 2.97 17.3 2.21 8.95-.76 16.53-4.38 7.58-3.62 13.77-10.57l87.13-97.26q6.2-6.96 9.06-14.92 2.85-7.95 2.85-16.91t-3.23-16.65q-3.24-7.7-9.44-13.89l-39.09-38.85q-6.71-6.72-15.05-10.08-8.34-3.36-17.53-3.36H512.22q-24.24-70.15-86.89-116.99-62.66-46.83-145.33-46.83-103.11 0-175.26 72.15Q32.59-583.11 32.59-480q0 102.87 72.27 175.14 72.27 72.27 175.14 72.27Z"/></svg>
      All Keys <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=$total?>)</span>
    </span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
      <div class="sbar" style="width:220px">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" style="width:13px;height:13px;flex-shrink:0;margin-right:7px;opacity:.4"><path d="M378.09-314.5q-111.16 0-188.33-77.17-77.17-77.18-77.17-188.33t77.17-188.33q77.17-77.17 188.33-77.17 111.15 0 188.32 77.17 77.18 77.18 77.18 188.33 0 44.48-13.52 83.12-13.53 38.64-36.57 68.16l222.09 222.33q12.67 12.91 12.67 31.94 0 19.04-12.91 31.71-12.68 12.67-31.83 12.67t-31.82-12.67L529.85-364.59q-29.76 23.05-68.64 36.57-38.88 13.52-83.12 13.52Zm0-91q72.84 0 123.67-50.83 50.83-50.82 50.83-123.67t-50.83-123.67q-50.83-50.83-123.67-50.83-72.85 0-123.68 50.83-50.82 50.82-50.82 123.67t50.82 123.67q50.83 50.83 123.68 50.83Z"/></svg>
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
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="15"><g transform="translate(0 -960) scale(40)"><path d="M19 8.375q-.125 0-.288-.088-.162-.087-.212-.262L17.7 6.3l-1.725-.775q-.175-.075-.25-.225-.075-.15-.075-.3t.075-.3q.075-.15.25-.225L17.7 3.7l.8-1.725q.05-.175.212-.263.163-.087.288-.087.15 0 .3.087.15.088.225.263l.8 1.725 1.7.775q.175.075.263.225.087.15.087.3t-.087.3q-.088.15-.263.225l-1.7.775-.8 1.725q-.075.175-.225.262-.15.088-.3.088Zm0 14q-.125 0-.288-.087-.162-.088-.212-.263l-.8-1.725-1.725-.775q-.175-.075-.25-.225-.075-.15-.075-.3t.075-.3q.075-.15.25-.225L17.7 17.7l.8-1.725q.05-.175.212-.263.163-.087.288-.087.15 0 .3.087.15.088.225.263l.8 1.725 1.7.775q.175.075.263.225.087.15.087.3t-.087.3q-.088.15-.263.225l-1.7.775-.8 1.725q-.075.175-.225.263-.15.087-.3.087ZM9.025 18.6q-.3 0-.587-.162-.288-.163-.438-.488l-1.525-3.375-3.4-1.55q-.3-.15-.475-.438-.175-.287-.175-.587t.175-.588q.175-.287.475-.437l3.4-1.55L8 6.05q.15-.325.438-.488.287-.162.587-.162.325 0 .612.162.288.163.438.488L11.6 9.425l3.4 1.55q.3.15.475.437.175.288.175.588t-.175.587q-.175.288-.475.438l-3.4 1.55-1.525 3.375q-.15.325-.438.488-.287.162-.612.162Z"/></g></svg>
          Actions
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" id="actChev" width="13" height="13"><path d="M480-355.5q-8.96 0-17.15-3.1-8.2-3.1-14.92-9.81L264.17-552.17Q251.5-564.85 251.5-584t12.67-31.83Q276.85-628.5 296-628.5t31.83 12.67L480-463.65l152.17-152.18Q644.85-628.5 664-628.5t31.83 12.67Q708.5-603.15 708.5-584t-12.67 31.83L512.07-368.41q-6.72 6.71-14.92 9.81-8.19 3.1-17.15 3.1Z"/></svg>
        </button>
        <div id="actMenu" style="display:none;position:fixed;right:16px;top:auto;background:var(--s3);border:1px solid var(--line2);border-radius:var(--r2);padding:6px;min-width:228px;z-index:9999;box-shadow:0 20px 50px rgba(0,0,0,.6)">
          <a href="/generate.php" class="act-item">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M280-360q-50 0-85-35t-35-85q0-50 35-85t85-35q50 0 85 35t35 85q0 50-35 85t-85 35Zm0 127.41q78.67 0 142.59-44.83 63.91-44.84 89.87-118.99h12.56l46.26 46.5q6.96 6.71 15.04 9.57 8.07 2.86 17.03 2.86 8.95 0 16.91-2.86 7.96-2.86 14.91-9.81l50.33-50.33 64.5 52.61q6.96 5.96 15.29 8.94 8.34 2.97 17.3 2.21 8.95-.76 16.53-4.38 7.58-3.62 13.77-10.57l87.13-97.26q6.2-6.96 9.06-14.92 2.85-7.95 2.85-16.91t-3.23-16.65q-3.24-7.7-9.44-13.89l-39.09-38.85q-6.71-6.72-15.05-10.08-8.34-3.36-17.53-3.36H512.22q-24.24-70.15-86.89-116.99-62.66-46.83-145.33-46.83-103.11 0-175.26 72.15Q32.59-583.11 32.59-480q0 102.87 72.27 175.14 72.27 72.27 175.14 72.27Z"/></svg>
            Generate Keys
          </a>
          <div class="act-item-sep"></div>
          <button class="act-item" onclick="bulkAct('delete_expired')">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M523.59-497.91v-137.31q0-18.52-12.53-31.05Q498.52-678.8 480-678.8t-31.06 12.53q-12.53 12.53-12.53 31.05v153.5q0 9.2 3.36 17.55 3.36 8.36 10.08 15.04l128.17 128.17q12.2 12.2 30.39 12.2 18.2 0 30.63-12.2 12.44-12.19 12.44-30.63 0-18.43-12.44-30.87L523.59-497.91ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
            Delete Expired Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_unused')">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M325.5-434.5h309q19.15 0 32.33-13.17Q680-460.85 680-480t-13.17-32.33q-13.18-13.17-32.33-13.17h-309q-19.15 0-32.33 13.17Q280-499.15 280-480t13.17 32.33q13.18 13.17 32.33 13.17ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
            Delete Unused Keys
          </button>
          <button class="act-item" onclick="bulkAct('delete_blocked')">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M480-71.87q-84.67 0-159.11-32.22-74.43-32.21-129.63-87.41-55.19-55.2-87.29-129.75-32.1-74.55-32.1-159.23 0-84.67 32.1-158.99 32.1-74.31 87.29-129.39 55.2-55.07 129.63-87.17 74.44-32.1 159.11-32.1 84.67 0 159.11 32.1 74.43 32.1 129.63 87.17 55.19 55.08 87.29 129.39 32.1 74.32 32.1 158.99 0 84.68-32.1 159.23-32.1 74.55-87.29 129.75-55.2 55.2-129.63 87.41Q564.67-71.87 480-71.87Zm0-91q52.09 0 100.65-16.3 48.57-16.31 89.13-48.11L226.8-670.02q-31.32 41.04-47.63 89.37-16.3 48.32-16.3 100.17 0 132.81 92.28 225.21T480-162.87Zm253.43-128.06q31.09-41.05 47.4-89.37 16.3-48.33 16.3-100.18 0-132.56-92.28-224.61-92.28-92.04-224.85-92.04-51.85 0-100.05 16.06-48.21 16.07-89.25 47.16l442.73 442.98Z"/></svg>
            Delete Blocked Keys
          </button>
          <button class="act-item" onclick="bulkAct('reset_all')">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg>
            Reset All Devices
          </button>
          <?php if($ulevel===1): ?>
          <div class="act-item-sep" style="background:var(--rose-bg)"></div>
          <button class="act-item" style="color:var(--rose-tx)" onclick="bulkAct('delete_all')">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M277.37-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-514.5q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17H354.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17h159.52q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33h168.61q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17v514.5q0 37.78-26.61 64.39t-64.39 26.61H277.37Zm121.2-168.37q17.95 0 30.29-12.34 12.34-12.33 12.34-30.29v-274.74q0-17.96-12.34-30.29-12.34-12.34-30.29-12.34-17.96 0-30.42 12.34-12.45 12.33-12.45 30.29v274.74q0 17.96 12.45 30.29 12.46 12.34 30.42 12.34Zm163.1 0q17.96 0 30.3-12.34 12.33-12.33 12.33-30.29v-274.74q0-17.96-12.33-30.29-12.34-12.34-30.3-12.34-17.95 0-30.41 12.34-12.46 12.33-12.46 30.29v274.74q0 17.96 12.46 30.29 12.46 12.34 30.41 12.34Z"/></svg>
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
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M368.37-237.37q-37.78 0-64.39-26.61t-26.61-64.39v-474.26q0-37.78 26.61-64.39t64.39-26.61h354.26q37.78 0 64.39 26.61t26.61 64.39v474.26q0 37.78-26.61 64.39t-64.39 26.61H368.37Zm-171 171q-37.78 0-64.39-26.61t-26.61-64.39v-519.76q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v519.76h399.76q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17H197.37Z"/></svg>
              </button>
            </div>
          </td>
          <td><?=htmlspecialchars($k->game)?></td>
          <td style=""><?=(int)$k->device_count?>/<?=(int)$k->max_devices?></td>
          <td style=""><?php $h=(int)$k->duration;echo $h<24?"{$h}H":round($h/24)."D";?></td>
          <?php if($ulevel===1): ?><td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($k->registrator)?></td><?php endif; ?>
          <td style="font-size:11px;<?=$expColor?>"><?php
            if ($k->expired_date) echo date('d M Y',strtotime($k->expired_date));
            elseif ((int)$k->duration > 0) echo '<span style="color:var(--muted2)">Not activated</span>';
            else echo '∞';
          ?></td>
          <td><span class="bx <?=$sc?>"><?=$st2?></span></td>
          <td>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?php if($k->status): ?>
              <button class="btn btn-warn btn-xs" onclick="kAct(<?=$k->id?>,'block')" title="Block">
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M480-71.87q-84.67 0-159.11-32.22-74.43-32.21-129.63-87.41-55.19-55.2-87.29-129.75-32.1-74.55-32.1-159.23 0-84.67 32.1-158.99 32.1-74.31 87.29-129.39 55.2-55.07 129.63-87.17 74.44-32.1 159.11-32.1 84.67 0 159.11 32.1 74.43 32.1 129.63 87.17 55.19 55.08 87.29 129.39 32.1 74.32 32.1 158.99 0 84.68-32.1 159.23-32.1 74.55-87.29 129.75-55.2 55.2-129.63 87.41Q564.67-71.87 480-71.87Zm0-91q52.09 0 100.65-16.3 48.57-16.31 89.13-48.11L226.8-670.02q-31.32 41.04-47.63 89.37-16.3 48.32-16.3 100.17 0 132.81 92.28 225.21T480-162.87Zm253.43-128.06q31.09-41.05 47.4-89.37 16.3-48.33 16.3-100.18 0-132.56-92.28-224.61-92.28-92.04-224.85-92.04-51.85 0-100.05 16.06-48.21 16.07-89.25 47.16l442.73 442.98Z"/></svg>
              </button>
              <?php else: ?>
              <button class="btn btn-acc btn-xs" onclick="kAct(<?=$k->id?>,'unblock')" title="Unblock">
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="m382-362.13 334.7-334.7q13.67-13.67 32.06-13.67t32.07 13.67q13.67 13.68 13.67 32.45 0 18.77-13.67 32.45L414.07-264.41q-13.68 13.67-32.07 13.67t-32.07-13.67L178.41-435.93q-13.67-13.68-13.29-32.45.38-18.77 14.05-32.45 13.68-13.67 32.45-13.67 18.77 0 32.45 13.67L382-362.13Z"/></svg>
              </button>
              <?php endif; ?>
              <button class="btn btn-blue btn-xs" onclick="kAct(<?=$k->id?>,'reset_device')" title="Reset Device">
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg>
              </button>
              <?php if($canEditKeys): ?>
              <button class="btn btn-ghost btn-xs" onclick="openEditKey(<?=$k->id?>,'<?=htmlspecialchars(addslashes($k->user_key))?>',<?=(int)$k->max_devices?>)" title="Edit Key">
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M401.67-356.17q-19.15 0-32.32-13.18-13.18-13.17-13.18-32.32v-92.46q0-18.15 6.84-34.69 6.84-16.53 19.51-29.2l337.07-336.83q13.67-13.67 30.58-20.39 16.92-6.72 34.07-6.72 17.91 0 34.44 6.72 16.54 6.72 30.21 20.39l45.96 46.72q12.91 13.67 19.63 30.21 6.72 16.53 6.72 33.68t-6.34 33.81q-6.34 16.65-20.01 30.32L558.02-383.28q-12.67 12.67-29.2 19.89-16.54 7.22-34.69 7.22h-92.46Zm379.46-372.07 56.76-56L785-836.89l-56.76 55.76 52.89 52.89ZM202.87-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-554.26q0-37.78 26.61-64.39t64.39-26.61h246.61q15.67 0 26.11 7.96 10.43 7.95 15.91 20.39 5.48 12.43 3.62 25.23-1.86 12.79-13.05 23.98L302.52-591.02q-12.67 12.67-19.51 29.2-6.84 16.54-6.84 34.69v159.96q0 37.78 26.61 64.39t64.39 26.61h159.44q18.15 0 34.68-6.84 16.54-6.84 29.21-19.51l180.07-180.07q11.19-11.19 23.98-13.05 12.8-1.86 25.23 3.62 12.44 5.48 20.39 15.91 7.96 10.44 7.96 26.11v247.13q0 37.78-26.61 64.39t-64.39 26.61H202.87Z"/></svg>
              </button>
              <?php endif; ?>
              <button class="btn btn-red btn-xs" onclick="confirmDo('Delete this key?',()=>kAct(<?=$k->id?>,'delete'))" title="Delete">
                <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M277.37-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-514.5q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17H354.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17h159.52q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33h168.61q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17v514.5q0 37.78-26.61 64.39t-64.39 26.61H277.37Zm121.2-168.37q17.95 0 30.29-12.34 12.34-12.33 12.34-30.29v-274.74q0-17.96-12.34-30.29-12.34-12.34-30.29-12.34-17.96 0-30.42 12.34-12.45 12.33-12.45 30.29v274.74q0 17.96 12.45 30.29 12.46 12.34 30.42 12.34Zm163.1 0q17.96 0 30.3-12.34 12.33-12.33 12.33-30.29v-274.74q0-17.96-12.33-30.29-12.34-12.34-30.3-12.34-17.95 0-30.41 12.34-12.46 12.33-12.46 30.29v274.74q0 17.96 12.46 30.29 12.46 12.34 30.41 12.34Z"/></svg>
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
.act-item:hover{background:var(--sunk);color:var(--text)}
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
        <div style="width:54px;height:54px;margin:0 auto 14px;background:var(--rose-bg);border:1px solid rgba(207,51,70,.3);border-radius:var(--r);display:flex;align-items:center;justify-content:center">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="24"><path d="M277.37-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-514.5q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17H354.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17h159.52q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33h168.61q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17v514.5q0 37.78-26.61 64.39t-64.39 26.61H277.37Zm121.2-168.37q17.95 0 30.29-12.34 12.34-12.33 12.34-30.29v-274.74q0-17.96-12.34-30.29-12.34-12.34-30.29-12.34-17.96 0-30.42 12.34-12.45 12.33-12.45 30.29v274.74q0 17.96 12.45 30.29 12.46 12.34 30.42 12.34Zm163.1 0q17.96 0 30.3-12.34 12.33-12.33 12.33-30.29v-274.74q0-17.96-12.33-30.29-12.34-12.34-30.3-12.34-17.95 0-30.41 12.34-12.46 12.33-12.46 30.29v274.74q0 17.96 12.46 30.29 12.46 12.34 30.41 12.34Z"/></svg>
        </div>
        <div style="font-size:15px;font-weight:700;color:var(--danger);margin-bottom:6px">This cannot be undone</div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6"><strong style="color:var(--text)">Every license key</strong> in the database will be<br>permanently deleted. All active users will lose access.</div>
      </div>
      <div style="background:var(--rose-bg);border:1px solid rgba(207,51,70,.3);border-radius:var(--r);padding:12px 14px;font-size:11px;color:var(--muted2)">
        <strong style="color:var(--danger)">⚠ Warning:</strong> Type <code style="color:var(--danger);background:var(--rose-bg);padding:1px 5px;border-radius:var(--r2)">DELETE</code> below to confirm.
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
