<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX ACTIONS (before HTML) ───────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    // ── Safety net: if anything fatals mid-action, return a readable JSON error
    //    instead of a blank page (which the UI showed as "Server error. Check console"). ──
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok'=>false,'msg'=>'Error: '.$e['message']]);
        }
    });
    requireLogin();
    // These are normally set in header.php (loaded later) — define them here
    // so the AJAX handler has the right level/name (owner IP-block was broken).
    $ulevel = (int)($_SESSION['ulevel']??99);
    $uname  = $_SESSION['uname']??'';
    if ($ulevel > 2) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $uid2    = (int)($_POST['uid2']??0);
    $blockIp = !empty($_POST['block_ip']);

    // ── Branch isolation guard: never act on a user outside your branch ──
    if ($uid2 > 0) {
        $tgtBranch = db()->one("SELECT branch FROM users WHERE id=?", [$uid2]);
        if ($tgtBranch && ($tgtBranch->branch ?? 'main') !== currentBranch()) {
            echo json_encode(['ok'=>false,'msg'=>'User not found']); exit;
        }
    }

    // ── Scope guard: an admin may only act on resellers they created. ──
    if ($ulevel === 2 && $uid2 > 0) {
        $tgt = db()->one("SELECT level, ref_giver FROM users WHERE id=?", [$uid2]);
        $allowed = $tgt && (int)$tgt->level === 3 && $tgt->ref_giver === $uname;
        if (!$allowed) { echo json_encode(['ok'=>false,'msg'=>'You can only manage your own resellers']); exit; }
    }

    function getLastIp(int $uid): string {
        $row = db()->one("SELECT ip FROM activity_logs WHERE user_id=? AND ip IS NOT NULL ORDER BY id DESC LIMIT 1",[$uid]);
        return $row ? ($row->ip??'') : '';
    }
    function blockUserIp(int $uid, string $reason): void {
        $ip = getLastIp($uid);
        if ($ip) db()->q("INSERT IGNORE INTO blocked_ips(ip,reason) VALUES(?,?)",[$ip,$reason]);
    }

    switch ($_POST['action']) {
        case 'ban':
            db()->q("UPDATE users SET status=0 WHERE id=?",[$uid2]);
            // Cascade choices come from the confirm dialog:
            //   also_resellers = 1 → (admin only) also ban resellers under them
            //   also_keys      = 1 → also block keys (of the user, and of cascaded resellers)
            $alsoResellers = !empty($_POST['also_resellers']);
            $alsoKeys      = !empty($_POST['also_keys']);
            $banned = db()->one("SELECT username, level FROM users WHERE id=?",[$uid2]);
            $cascadeBits = [];
            if ($banned && (int)$banned->level === 2) {
                $adminName = $banned->username;
                $names = [$adminName]; // admin's own keys
                $resellerNames = [];
                if ($alsoResellers) {
                    $subs = db()->all("SELECT username FROM users WHERE ref_giver=?",[$adminName]);
                    foreach ($subs as $s) { $names[] = $s->username; $resellerNames[] = $s->username; }
                    db()->q("UPDATE users SET status=0 WHERE ref_giver=?",[$adminName]);
                    // Banning resellers ALWAYS disables those resellers' keys — a
                    // banned reseller's keys must not keep working.
                    if ($resellerNames) {
                        $rp = implode(',', array_fill(0, count($resellerNames), '?'));
                        db()->q("UPDATE keys_code SET status=0 WHERE registrator IN ($rp)", $resellerNames);
                    }
                    $cascadeBits[] = count($subs).' resellers + their keys blocked';
                }
                if ($alsoKeys) {
                    // "Also Block All Keys" additionally blocks the ADMIN's own keys
                    $ph = implode(',', array_fill(0, count($names), '?'));
                    db()->q("UPDATE keys_code SET status=0 WHERE registrator IN ($ph)", $names);
                    $cascadeBits[] = 'admin keys blocked';
                }
            } elseif ($banned && (int)$banned->level === 3) {
                if ($alsoKeys) {
                    db()->q("UPDATE keys_code SET status=0 WHERE registrator=?",[$banned->username]);
                    $cascadeBits[] = 'keys blocked';
                }
            }
            if ($blockIp && $ulevel===1) blockUserIp($uid2,'Banned by owner - IP block');
            $cascadeInfo = $cascadeBits ? ' ('.implode(', ',$cascadeBits).')' : '';
            logAct($_SESSION['uid'],$uname,'BAN_USER',"ID:$uid2".($blockIp?' +IP_block':'').$cascadeInfo);
            echo json_encode(['ok'=>true,'msg'=>'User banned'.$cascadeInfo.($blockIp?' & IP blocked.':'.')]);
            exit;
        case 'unban':
            db()->q("UPDATE users SET status=1 WHERE id=?",[$uid2]);
            // ── CASCADE: un-ban resellers + re-enable keys if this is an admin ──
            $unbanned = db()->one("SELECT username, level FROM users WHERE id=?",[$uid2]);
            if ($unbanned && (int)$unbanned->level === 2) {
                $adminName = $unbanned->username;
                $subs = db()->all("SELECT username FROM users WHERE ref_giver=?",[$adminName]);
                $names = [$adminName];
                foreach ($subs as $s) $names[] = $s->username;
                db()->q("UPDATE users SET status=1 WHERE ref_giver=?",[$adminName]);
                $ph = implode(',', array_fill(0, count($names), '?'));
                // re-enable only keys that aren't expired
                db()->q("UPDATE keys_code SET status=1 WHERE registrator IN ($ph) AND (expired_date IS NULL OR expired_date > NOW())", $names);
            } elseif ($unbanned && (int)$unbanned->level === 3) {
                db()->q("UPDATE keys_code SET status=1 WHERE registrator=? AND (expired_date IS NULL OR expired_date > NOW())",[$unbanned->username]);
            }
            logAct($_SESSION['uid'],$uname,'UNBAN_USER',"ID:$uid2");
            echo json_encode(['ok'=>true,'msg'=>'User unbanned.']);
            exit;
        case 'delete':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            if ($blockIp) blockUserIp($uid2,'Deleted by owner - IP block');
            db()->q("DELETE FROM users WHERE id=?",[$uid2]);
            logAct($_SESSION['uid'],$uname,'DELETE_USER',"ID:$uid2".($blockIp?' +IP_block':''));
            echo json_encode(['ok'=>true,'msg'=>'User deleted'.($blockIp?' & IP blocked.':'.')]);
            exit;
        case 'extend_validity':
            // Owner: any user in branch. Admin: only their own resellers.
            $days = (int)($_POST['days']??0);
            if ($days === 0) { echo json_encode(['ok'=>false,'msg'=>'Enter a number of days (can be negative to reduce).']); exit; }
            $tgt = db()->one("SELECT username, level, ref_giver, expiration_date FROM users WHERE id=?",[$uid2]);
            if (!$tgt) { echo json_encode(['ok'=>false,'msg'=>'User not found']); exit; }
            if ($ulevel === 2 && !((int)$tgt->level === 3 && $tgt->ref_giver === $uname)) {
                echo json_encode(['ok'=>false,'msg'=>'You can only extend your own resellers']); exit;
            }
            // Extend from current expiry if still valid, else from now
            $base = ($tgt->expiration_date && strtotime($tgt->expiration_date) > time())
                    ? strtotime($tgt->expiration_date) : time();
            $newExp = date('Y-m-d H:i:s', $base + $days*86400);
            db()->q("UPDATE users SET expiration_date=? WHERE id=?",[$newExp,$uid2]);
            logAct($_SESSION['uid'],$uname,'EXTEND_USER_VALIDITY',"{$tgt->username} {$days}d → {$newExp}");
            echo json_encode(['ok'=>true,'msg'=>"Validity ".($days>0?'extended':'reduced')." for {$tgt->username} → ".date('d M Y',strtotime($newExp))]);
            exit;
        case 'set_password':
            // OWNER ONLY — admins cannot change anyone's password.
            if ((int)($_SESSION['ulevel']??99) !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $newPass = (string)($_POST['new_password']??'');
            if (strlen($newPass) < 4) { echo json_encode(['ok'=>false,'msg'=>'Password must be at least 4 characters.']); exit; }
            $tgt = db()->one("SELECT username, level, ref_giver FROM users WHERE id=?",[$uid2]);
            if (!$tgt) { echo json_encode(['ok'=>false,'msg'=>'User not found']); exit; }
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            db()->q("UPDATE users SET password=? WHERE id=?",[$hash,$uid2]);
            logAct($_SESSION['uid'],$uname,'SET_USER_PASSWORD',"Changed password for {$tgt->username}");
            echo json_encode(['ok'=>true,'msg'=>'Password changed for '.$tgt->username]);
            exit;
        case 'delete_all':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $br = currentBranch();
            $c = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level>1 AND branch=?",[$br])->c??0);
            db()->q("DELETE FROM users WHERE level>1 AND branch=?",[$br]);
            logAct($_SESSION['uid'],$uname,'DELETE_ALL_USERS',"$c users deleted");
            echo json_encode(['ok'=>true,'msg'=>"$c users deleted. Owners kept."]);
            exit;
        case 'set_limit':
            $limit = (int)($_POST['limit']??0);
            db()->q("UPDATE users SET referral_limit=? WHERE id=?",[$limit,$uid2]);
            echo json_encode(['ok'=>true,'msg'=>"Limit set to $limit."]);
            exit;
        case 'set_role':
            if ($ulevel!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $newLevel = (int)($_POST['level']??3);
            if (!in_array($newLevel,[1,2,3],true)) { echo json_encode(['ok'=>false,'msg'=>'Invalid role.']); exit; }
            if ($uid2 === (int)$_SESSION['uid']) { echo json_encode(['ok'=>false,'msg'=>"Can't change your own role."]); exit; }
            db()->q("UPDATE users SET level=? WHERE id=?",[$newLevel,$uid2]);
            if ($newLevel===1) db()->q("UPDATE users SET balance=999999999.99 WHERE id=?",[$uid2]); // owner = unlimited
            logAct($_SESSION['uid'],$uname,'SET_ROLE',"ID:$uid2 -> level $newLevel");
            echo json_encode(['ok'=>true,'msg'=>'Role updated to '.[1=>'Owner',2=>'Admin',3=>'Reseller'][$newLevel].'.']);
            exit;
        case 'unblock_ip':
            if ((int)($_SESSION['ulevel']??99)!==1) { echo json_encode(['ok'=>false,'msg'=>'Owner only.']); exit; }
            $ip = trim($_POST['ip']??'');
            db()->q("DELETE FROM blocked_ips WHERE ip=?",[$ip]);
            logAct($_SESSION['uid'],$uname,'UNBLOCK_IP',$ip);
            echo json_encode(['ok'=>true,'msg'=>"IP $ip unblocked."]);
            exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ── AJAX: username autocomplete (matching-as-you-type) ──
if (isset($_GET['get_users'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv=(int)($_SESSION['ulevel']??99); $unm=$_SESSION['uname']??'';
    if ($ulv>2){ echo json_encode([]); exit; }
    $q=trim($_GET['q']??'');
    if ($ulv===1) {
        $rows=db()->all("SELECT username,level FROM users WHERE branch=? AND username LIKE ? ORDER BY level,username LIMIT 15",[currentBranch(),"%$q%"]);
    } else {
        $codes=array_column((array)db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]),'code');
        if(empty($codes)){ echo json_encode([]); exit; }
        $ph=implode(',',array_fill(0,count($codes),'?'));
        $params=$codes; if($q)$params[]="%$q%";
        $rows=db()->all("SELECT username,level FROM users WHERE ref_code_used IN ($ph)".($q?" AND username LIKE ?":"")." ORDER BY username LIMIT 15",$params);
    }
    echo json_encode(array_values((array)$rows)); exit;
}

// ── PAGE LOAD ─────────────────────────────────
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) > 2) { header('Location: /dashboard.php'); exit; }
$pageTitle='Manage Users'; $activeNav='users';
require_once __DIR__.'/app/Views/Layout/header.php';
$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

// ── FETCH USERS ───────────────────────────────
$q    = trim($_GET['q']??'');
$role = $_GET['role']??'';
$myLevel = (int)($_SESSION['ulevel'] ?? 3);
$myName  = $_SESSION['uname'] ?? '';

$where = 'WHERE 1=1'; $params = [];

// ── BRANCH ISOLATION ──
// Everyone (including the main owner) only ever sees users in their OWN branch.
// The hidden owner sees only the hidden branch; the main owner never sees it.
$where .= " AND branch=?"; $params[] = currentBranch();

// ── Visibility scoping (within the branch) ──
// Owner (level 1): sees everyone in their branch.
// Admin (level 2): sees ONLY themselves + resellers they created.
if ($myLevel === 2) {
    $where .= " AND (username = ? OR (level = 3 AND ref_giver = ?))";
    $params[] = $myName;
    $params[] = $myName;
}

if ($q)             { $where.=" AND (username LIKE ? OR referred_by LIKE ? OR ref_code_used LIKE ?)"; array_push($params, "%$q%","%$q%","%$q%"); }
if ($role==='owner')    $where.=' AND level=1';
if ($role==='admin')    $where.=' AND level=2';
if ($role==='reseller') $where.=' AND level=3';
$users = db()->all("SELECT * FROM users $where ORDER BY level ASC, created_at DESC", $params);
$levelMap = [1=>'Owner',2=>'Admin',3=>'Reseller'];
$levelCls = [1=>'b-owner',2=>'b-admin',3=>'b-reseller'];
?>

<!-- SEARCH CARD -->
<div class="card" style="margin-bottom:14px">
  <div class="card-h">
    <span class="ct"><svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Search & Filter</span>
    <?php if($ulevel===1): ?>
    <button class="btn btn-red btn-sm" onclick="deleteAll()">
      <svg viewBox="0 0 24 24" <?=$s?> width="12" height="12"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
      Delete All Users
    </button>
    <?php endif; ?>
  </div>
  <div class="card-b" style="padding:14px">
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="position:relative;flex:1;min-width:180px">
        <div class="sbar">
          <svg viewBox="0 0 24 24" <?=$s?> style="width:13px;height:13px;flex-shrink:0;margin-right:7px;opacity:.35"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input id="userSearch" placeholder="Type to search users..." value="<?=htmlspecialchars($q)?>" autocomplete="off" oninput="searchU(this.value);liveFilter(this.value)" onfocus="searchU(this.value)" onkeydown="if(event.key==='Enter')applyF()">
        </div>
        <div id="uDrop" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s3);border:1px solid rgba(45,212,191,.18);border-radius:var(--r);z-index:200;max-height:220px;overflow-y:auto;box-shadow:0 12px 36px rgba(0,0,0,.8);"></div>
      </div>
      <?php if($ulevel===1): ?>
      <select class="fi" id="roleF" style="width:130px;padding:8px 10px;font-size:12px" onchange="applyF()">
        <option value="">All Roles</option>
        <option value="owner"    <?=$role==='owner'   ?'selected':''?>>Owner</option>
        <option value="admin"    <?=$role==='admin'   ?'selected':''?>>Admin</option>
        <option value="reseller" <?=$role==='reseller'?'selected':''?>>Reseller</option>
      </select>
      <?php else: ?>
      <input type="hidden" id="roleF" value="">
      <?php endif; ?>
      <button class="btn btn-acc btn-sm" onclick="applyF()">Search</button>
    </div>
  </div>
</div>

<!-- USERS TABLE -->
<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg viewBox="0 0 24 24" <?=$s?> width="14" height="14"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      All Users <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=count($users)?>)</span>
    </span>
  </div>
  <div class="tw">
    <table id="usersTable">
      <thead>
        <tr>
          <th>#</th><th>Username</th><th>Role</th><th>Balance</th>
          <th>Ref Code Used</th><th>Ref Given By</th><th>Expiry</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="9" style="text-align:center;padding:36px;color:var(--muted)">No users found</td></tr>
        <?php else: $i=1; foreach ($users as $u):
          $lvl  = (int)$u->level;
          $lname= $levelMap[$lvl]??'?';
          $lcls = $levelCls[$lvl]??'b-reseller';
          $exp  = $u->expiration_date && strtotime($u->expiration_date) < time();
          // Last IP (owner only, avoid N+1 by only fetching when needed)
          $lastIp = '';
          if ($ulevel===1) {
              $ipRow  = db()->one("SELECT ip FROM activity_logs WHERE user_id=? AND ip IS NOT NULL ORDER BY id DESC LIMIT 1",[$u->id]);
              $lastIp = $ipRow ? ($ipRow->ip??'') : '';
          }
        ?>
        <tr id="ur-<?=$u->id?>">
          <td style="color:var(--muted);font-size:11px"><?=$i++?></td>
          <td>
            <div style="font-weight:700;font-size:12px"><?=htmlspecialchars($u->username)?></div>
            <?php if($u->last_login): ?>
            <div style="font-size:10px;color:var(--muted)">Last: <?=date('d M H:i',strtotime($u->last_login))?></div>
            <?php endif; ?>
            <?php if($lastIp): ?>
            <div style="font-size:9px;color:var(--muted);"><?=htmlspecialchars($lastIp)?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="bx <?=$lcls?>" style="font-size:9px">
              <?=$lvl===1?'● ':($lvl===2?'▲ ':'○ ')?>
              <?=$lname?>
            </span>
          </td>
          <td style="color:var(--acc);font-weight:700;font-size:12px">
            <?=number_format((float)$u->balance,0)?>
          </td>
          <td>
            <?php if($u->ref_code_used): ?>
            <code style="font-size:10px"><?=htmlspecialchars($u->ref_code_used)?></code>
            <?php else: ?>
            <span style="color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--muted2)"><?=htmlspecialchars($u->ref_giver??'—')?></td>
          <td style="font-size:11px;<?=$exp?'color:var(--danger)':''?>">
            <?=$u->expiration_date ? date('d M Y',strtotime($u->expiration_date)) : '∞'?>
          </td>
          <td>
            <?php if($u->status==1):    echo '<span class="bx b-active" style="font-size:9px">Active</span>';
            elseif($u->status==2): echo '<span class="bx b-exp" style="font-size:9px">Pending</span>';
            else:                   echo '<span class="bx b-ban" style="font-size:9px">Banned</span>';
            endif; ?>
          </td>
          <td>
            <?php if ($u->id != $_SESSION['uid']): ?>
            <div style="display:flex;gap:3px;flex-wrap:wrap">
              <?php
              $canManage = ($ulevel===1) || ($ulevel===2 && $lvl===3);
              $isOwnerUser = ($lvl===1);
              if($canManage && !$isOwnerUser):
              ?>
                <?php if ($u->status == 1): ?>
                  <button class="btn btn-red btn-xs" onclick="openBanModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>',<?=$lvl?>)">Ban</button>
                <?php else: ?>
                  <button class="btn btn-acc btn-xs" onclick="uAct(<?=$u->id?>,'unban')">Unban</button>
                  <?php if ($ulevel===1 && $lastIp): ?>
                  <button class="btn btn-blue btn-xs" onclick="unblockIp('<?=htmlspecialchars($lastIp)?>')">Unblock IP</button>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($ulevel===1): ?>
                <button class="btn btn-blue btn-xs" onclick="openPassModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>')">Password</button>
                <?php endif; ?>
                <button class="btn btn-acc btn-xs" onclick="openExtendModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>','<?=$u->expiration_date?date('d M Y',strtotime($u->expiration_date)):'∞'?>')">Extend</button>
                <?php if ($ulevel===1): ?>
                <button class="btn btn-blue btn-xs" onclick="openRoleModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>',<?=$lvl?>)">Role</button>
                <button class="btn btn-blue btn-xs" onclick="openLimitModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>'  ,<?=(int)($u->referral_limit??0)?>)">Limit</button>
                <button class="btn btn-ghost btn-xs" onclick="openDeleteModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>')">Del</button>
                <?php endif; ?>
              <?php endif; ?>
              <?php if($ulevel===1 && $isOwnerUser): // owner can re-role another owner ?>
              <button class="btn btn-blue btn-xs" onclick="openRoleModal(<?=$u->id?>,'<?=htmlspecialchars(addslashes($u->username))?>',<?=$lvl?>)">Role</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- BAN MODAL -->
<div class="modal-bg" id="banModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> BAN USER</div><button class="mcls" onclick="closeModal('banModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="bm-user" readonly style="color:var(--danger);font-weight:700"></div>

      <!-- Cascade options (shown based on target role) -->
      <div id="bm-optAdminResellers" style="display:none;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:var(--r);margin-bottom:10px">
        <div>
          <div style="font-weight:700;font-size:12px">Also Block All Resellers</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">Ban every reseller under this admin + disable their keys</div>
        </div>
        <label class="tog"><input type="checkbox" id="bm-alsoResellers"><span class="togsl"></span></label>
      </div>
      <div id="bm-optKeys" style="display:none;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:var(--r);margin-bottom:10px">
        <div>
          <div style="font-weight:700;font-size:12px" id="bm-keysLabel">Also Block All Keys</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px" id="bm-keysSub">Disable all keys generated by this user</div>
        </div>
        <label class="tog"><input type="checkbox" id="bm-alsoKeys"><span class="togsl"></span></label>
      </div>

      <?php if($ulevel===1): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:var(--r)">
        <div>
          <div style="font-weight:700;font-size:12px">Also Block IP Address</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">User cannot register again or use any account</div>
        </div>
        <label class="tog"><input type="checkbox" id="bm-blockip"><span class="togsl"></span></label>
      </div>
      <?php endif; ?>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('banModal')">Cancel</button>
      <button class="btn btn-red" onclick="doBan()">Ban User</button>
    </div>
  </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-bg" id="passModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> CHANGE PASSWORD</div><button class="mcls" onclick="closeModal('passModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">User</label><input class="fi fi-mono" id="pm-user" readonly style="font-weight:700"></div>
      <div class="fg"><label class="fl">New Password</label><input class="fi fi-mono" id="pm-newpass" type="text" placeholder="Enter new password (min 4 chars)"><div class="fi-hint">The user will log in with this new password on panel and bot.</div></div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('passModal')">Cancel</button>
      <button class="btn btn-acc" onclick="savePass()">Change Password</button>
    </div>
  </div>
</div>

<!-- EXTEND VALIDITY MODAL -->
<div class="modal-bg" id="extendModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--acc)"></span> EXTEND VALIDITY</div><button class="mcls" onclick="closeModal('extendModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">User</label><input class="fi fi-mono" id="xm-user" readonly style="font-weight:700"></div>
      <div class="fg"><label class="fl">Current Expiry</label><input class="fi fi-mono" id="xm-cur" readonly></div>
      <div class="fg">
        <label class="fl">Add Days</label>
        <input class="fi fi-mono" id="xm-days" type="number" value="30" placeholder="e.g. 30 (use negative to reduce)">
        <div class="fi-hint">Extends from current expiry if still valid, else from today. Negative reduces.</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
        <button class="btn btn-ghost btn-xs" onclick="document.getElementById('xm-days').value=7">+7d</button>
        <button class="btn btn-ghost btn-xs" onclick="document.getElementById('xm-days').value=30">+30d</button>
        <button class="btn btn-ghost btn-xs" onclick="document.getElementById('xm-days').value=90">+90d</button>
        <button class="btn btn-ghost btn-xs" onclick="document.getElementById('xm-days').value=365">+365d</button>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('extendModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveExtend()">Apply</button>
    </div>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-bg" id="deleteModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> DELETE USER</div><button class="mcls" onclick="closeModal('deleteModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="dm-user" readonly style="color:var(--danger);font-weight:700"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:rgba(255,77,109,.05);border:1px solid rgba(255,77,109,.15);border-radius:var(--r)">
        <div>
          <div style="font-weight:700;font-size:12px">Also Block IP Address</div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">Prevents re-registration from same IP</div>
        </div>
        <label class="tog"><input type="checkbox" id="dm-blockip"><span class="togsl"></span></label>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-red" onclick="doDelete()">Delete User</button>
    </div>
  </div>
</div>

<!-- SET ROLE MODAL -->
<div class="modal-bg" id="roleModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> CHANGE ROLE</div><button class="mcls" onclick="closeModal('roleModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi" id="rm-uname" readonly style="color:var(--acc);"></div>
      <div class="fg">
        <label class="fl">Role / Level</label>
        <select class="fi" id="rm-level">
          <option value="1">Level 1 — Owner</option>
          <option value="2">Level 2 — Admin</option>
          <option value="3">Level 3 — Reseller</option>
        </select>
        <div class="fi-hint">Setting a user to Owner grants unlimited balance.</div>
      </div>
      <input type="hidden" id="rm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('roleModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveRole()">Save Role</button>
    </div>
  </div>
</div>

<!-- DELETE ALL USERS MODAL -->
<div class="modal-bg" id="deleteAllModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> DELETE ALL USERS</div><button class="mcls" onclick="closeModal('deleteAllModal')">×</button></div>
    <div class="mbody">
      <div style="text-align:center;padding:8px 0 16px">
        <div style="width:54px;height:54px;margin:0 auto 14px;background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.3);border-radius:var(--r);display:flex;align-items:center;justify-content:center">
          <svg viewBox="0 0 24 24" fill="none" stroke="#ff4d6d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
        </div>
        <div style="font-size:15px;font-weight:700;color:var(--danger);margin-bottom:6px">This cannot be undone</div>
        <div style="font-size:12px;color:var(--muted);line-height:1.6">All <strong style="color:#e2e8f0">Admins and Resellers</strong> will be permanently deleted.<br>Owner accounts will be kept.</div>
      </div>
      <div style="background:rgba(255,77,109,.06);border:1px solid rgba(255,77,109,.2);border-radius:var(--r);padding:12px 14px;font-size:11px;color:var(--muted2)">
        <strong style="color:var(--danger)">⚠ Warning:</strong> Type <code style="color:var(--danger);background:rgba(255,77,109,.1);padding:1px 5px;border-radius:var(--r2)">DELETE</code> below to confirm.
      </div>
      <input class="fi fi-mono" id="da-confirm" placeholder="Type DELETE to confirm" oninput="document.getElementById('da-btn').disabled=this.value!=='DELETE'" style="margin-top:12px;color:var(--danger);letter-spacing:2px">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteAllModal')">Cancel</button>
      <button class="btn btn-red" id="da-btn" disabled onclick="doDeleteAll()">Delete All Users</button>
    </div>
  </div>
</div>

<!-- SET LIMIT MODAL -->
<div class="modal-bg" id="limitModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> SET REFERRAL LIMIT</div><button class="mcls" onclick="closeModal('limitModal')">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi" id="lm-uname" readonly style="color:var(--acc);"></div>
      <div class="fg"><label class="fl">Max Referral Codes (0 = unlimited)</label><input class="fi fi-mono" type="number" id="lm-limit" min="0" value="0"></div>
      <input type="hidden" id="lm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('limitModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveLimit()">Save Limit</button>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
let banUid=0, delUid=0;

function openBanModal(uid,uname,level){
  banUid=uid;
  document.getElementById('bm-user').value=uname;
  // Reset toggles
  const rs=document.getElementById('bm-alsoResellers'); if(rs)rs.checked=false;
  const ks=document.getElementById('bm-alsoKeys'); if(ks)ks.checked=false;
  const ip=document.getElementById('bm-blockip'); if(ip)ip.checked=false;
  // Show options based on role: admin(2) → resellers+keys, reseller(3) → keys only
  const optRes=document.getElementById('bm-optAdminResellers');
  const optKeys=document.getElementById('bm-optKeys');
  const keysLabel=document.getElementById('bm-keysLabel');
  const keysSub=document.getElementById('bm-keysSub');
  if(level===2){
    optRes.style.display='flex';
    optKeys.style.display='flex';
    keysLabel.textContent='Also Block All Keys';
    keysSub.textContent='Also disable this admin\u2019s own keys (reseller keys are auto-disabled above)';
  } else if(level===3){
    optRes.style.display='none';
    optKeys.style.display='flex';
    keysLabel.textContent='Also Block This Reseller\u2019s Keys';
    keysSub.textContent='Disable all keys generated by this reseller';
  } else {
    optRes.style.display='none';
    optKeys.style.display='none';
  }
  openModal('banModal');
}
function doBan(){
  const blockIp=document.getElementById('bm-blockip')?.checked?1:0;
  const alsoResellers=document.getElementById('bm-alsoResellers')?.checked?1:0;
  const alsoKeys=document.getElementById('bm-alsoKeys')?.checked?1:0;
  formPost('/users.php',{action:'ban',uid2:banUid,block_ip:blockIp,also_resellers:alsoResellers,also_keys:alsoKeys,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('banModal');setTimeout(()=>location.reload(),700);}
    else toast(r.msg,'e');
  });
}
let passUid=0;
function openPassModal(uid,uname){
  passUid=uid;
  document.getElementById('pm-user').value=uname;
  document.getElementById('pm-newpass').value='';
  openModal('passModal');
}
function savePass(){
  const np=document.getElementById('pm-newpass').value;
  if(np.length<4){toast('Password must be at least 4 characters','e');return;}
  formPost('/users.php',{action:'set_password',uid2:passUid,new_password:np,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('passModal');}
    else toast(r.msg,'e');
  });
}
let extendUid=0;
function openExtendModal(uid,uname,curExp){
  extendUid=uid;
  document.getElementById('xm-user').value=uname;
  document.getElementById('xm-cur').value=curExp;
  document.getElementById('xm-days').value=30;
  openModal('extendModal');
}
function saveExtend(){
  const days=parseInt(document.getElementById('xm-days').value||'0',10);
  if(!days){toast('Enter a number of days','e');return;}
  formPost('/users.php',{action:'extend_validity',uid2:extendUid,days:days,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('extendModal');setTimeout(()=>location.reload(),900);}
    else toast(r.msg,'e');
  });
}
function openDeleteModal(uid,uname){delUid=uid;document.getElementById('dm-user').value=uname;openModal('deleteModal');}
function doDelete(){
  const blockIp=document.getElementById('dm-blockip')?.checked?1:0;
  formPost('/users.php',{action:'delete',uid2:delUid,block_ip:blockIp,csrf}).then(r=>{
    if(r.ok){toast(r.msg,'s');closeModal('deleteModal');document.getElementById('ur-'+delUid)?.remove();}
    else toast(r.msg,'e');
  });
}
async function uAct(uid,action){
  const r=await formPost('/users.php',{action,uid2:uid,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),600);}else toast(r.msg,'e');
}
async function unblockIp(ip){
  if(!confirm('Unblock IP: '+ip+'?'))return;
  const r=await formPost('/users.php',{action:'unblock_ip',ip,csrf});
  r.ok?toast(r.msg,'s'):toast(r.msg,'e');
}
function deleteAll(){
  document.getElementById('da-confirm').value='';
  document.getElementById('da-btn').disabled=true;
  openModal('deleteAllModal');
}
async function doDeleteAll(){
  const r=await formPost('/users.php',{action:'delete_all',csrf});
  if(r.ok){toast(r.msg,'s');closeModal('deleteAllModal');setTimeout(()=>location.reload(),700);}else toast(r.msg,'e');
}
function openRoleModal(uid,uname,curLevel){
  document.getElementById('rm-uid').value=uid;
  document.getElementById('rm-uname').value=uname;
  document.getElementById('rm-level').value=curLevel;
  openModal('roleModal');
}
async function saveRole(){
  const r=await formPost('/users.php',{action:'set_role',uid2:document.getElementById('rm-uid').value,level:document.getElementById('rm-level').value,csrf});
  if(r.ok){toast(r.msg,'s');closeModal('roleModal');setTimeout(()=>location.reload(),700);}else toast(r.msg,'e');
}
function openLimitModal(uid,uname,cur){
  document.getElementById('lm-uid').value=uid;
  document.getElementById('lm-uname').value=uname;
  document.getElementById('lm-limit').value=cur;
  openModal('limitModal');
}
async function saveLimit(){
  const r=await formPost('/users.php',{action:'set_limit',uid2:document.getElementById('lm-uid').value,limit:document.getElementById('lm-limit').value,csrf});
  if(r.ok){toast(r.msg,'s');closeModal('limitModal');}else toast(r.msg,'e');
}
function applyF(){location.href=`/users.php?q=${encodeURIComponent(document.getElementById('userSearch').value)}&role=${encodeURIComponent(document.getElementById('roleF').value)}`;}
// Live filter visible table rows without reload
function liveFilter(q){
  const tbody=document.querySelector('#usersTable tbody');
  if(!tbody) return;
  const lq=q.toLowerCase().trim();
  tbody.querySelectorAll('tr').forEach(r=>{ r.style.display=(!lq||r.textContent.toLowerCase().includes(lq))?'':'none'; });
}
let uT;
function searchU(q){
  clearTimeout(uT);
  const dd=document.getElementById('uDrop');
  if(!q.trim()){ dd.style.display='none'; return; }
  uT=setTimeout(async()=>{
    try{
      const r=await fetch('/users.php?get_users=1&q='+encodeURIComponent(q.trim()));
      const list=await r.json();
      if(!list.length){ dd.style.display='none'; return; }
      const lv={1:'Owner',2:'Admin',3:'Reseller'};
      dd.innerHTML=list.map(u=>`<div onclick="pickU('${u.username}')" style="padding:10px 14px;cursor:pointer;font-size:12.5px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.05);transition:.1s" onmouseover="this.style.background='rgba(45,212,191,.08)'" onmouseout="this.style.background=''"><span style="font-weight:700">${u.username}</span><span style="color:var(--muted2);font-size:11px">${lv[u.level]||''}</span></div>`).join('');
      dd.style.display='block';
    }catch(e){ dd.style.display='none'; }
  },250);
}
function pickU(name){ document.getElementById('userSearch').value=name; document.getElementById('uDrop').style.display='none'; applyF(); }
document.addEventListener('click',e=>{ if(!e.target.closest('#userSearch')&&!e.target.closest('#uDrop')) document.getElementById('uDrop').style.display='none'; });
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
