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
            db()->q("UPDATE users SET status=1, login_attempts=0, locked_until=NULL WHERE id=?",[$uid2]);
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

<?php
// last known IP per user in ONE query (owner only) instead of one query per row
$lastIps = [];
if ($ulevel===1 && $users) {
    foreach (db()->all("SELECT l.user_id, l.ip FROM activity_logs l
                        JOIN (SELECT user_id, MAX(id) mid FROM activity_logs WHERE ip IS NOT NULL AND user_id IS NOT NULL GROUP BY user_id) m ON m.mid = l.id") as $ipr) {
        $lastIps[(int)$ipr->user_id] = (string)$ipr->ip;
    }
}
$cnt = [1=>0,2=>0,3=>0]; $banned = 0;
foreach ($users as $cu) { $cnt[(int)$cu->level] = ($cnt[(int)$cu->level] ?? 0) + 1; if ((int)$cu->status === 0) $banned++; }
?>
<div class="strip">
  <div class="met"><div class="met-ic" style="--c:var(--amber-tx);--cb:var(--amber-bg)"><?= phIcon('crown') ?></div><div class="met-x"><b><?= $cnt[1] ?></b><span>Owners</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--blue-tx);--cb:var(--blue-bg)"><?= phIcon('identification-badge') ?></div><div class="met-x"><b><?= $cnt[2] ?></b><span>Admins</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--violet-tx);--cb:var(--violet-bg)"><?= phIcon('storefront') ?></div><div class="met-x"><b><?= $cnt[3] ?></b><span>Resellers</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--rose-tx);--cb:var(--rose-bg)"><?= phIcon('prohibit') ?></div><div class="met-x"><b><?= $banned ?></b><span>Banned</span></div></div>
</div>

<div class="card">
  <div class="card-h">
    <span class="ct"><?= phIcon('users-three') ?> Users <span style="color:var(--muted);font-size:12px;font-weight:600">(<?=count($users)?>)</span></span>
    <?php if($ulevel===1): ?>
    <div class="chips">
      <?php foreach ([''=>'All','owner'=>'Owners','admin'=>'Admins','reseller'=>'Resellers'] as $rv=>$rl): ?>
      <button type="button" class="chip <?= $role===$rv ? 'on' : '' ?>" onclick="document.getElementById('roleF').value='<?= $rv ?>';applyF()"><?= $rl ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <input type="hidden" id="roleF" value="<?= htmlspecialchars($role) ?>">
    <div style="position:relative;flex:1 1 200px;max-width:320px">
      <div class="sbar">
        <?= phIcon('magnifying-glass') ?>
        <input id="userSearch" aria-label="Search users" placeholder="Search" value="<?=htmlspecialchars($q)?>" autocomplete="off" oninput="searchU(this.value);liveFilter(this.value)" onfocus="searchU(this.value)" onkeydown="if(event.key==='Enter')applyF()">
      </div>
      <div id="uDrop" style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--card-bg);border:1px solid var(--line2);border-radius:var(--r2);z-index:200;max-height:220px;overflow-y:auto;box-shadow:var(--pop)"></div>
    </div>
    <?php if($ulevel===1): ?>
    <button class="btn btn-red btn-sm" onclick="deleteAll()"><?= phIcon('trash') ?> Delete all</button>
    <?php endif; ?>
  </div>

  <div id="usersList">
    <?php if (empty($users)): ?>
    <div class="empty">No users found.</div>
    <?php else: foreach ($users as $u):
      $lvl   = (int)$u->level;
      $lname = $levelMap[$lvl]??'?';
      $lcls  = $levelCls[$lvl]??'b-reseller';
      $exp   = $u->expiration_date && strtotime($u->expiration_date) < time();
      $lastIp= $lastIps[(int)$u->id] ?? '';
      $un    = htmlspecialchars(addslashes($u->username));
      $canManage   = ($ulevel===1) || ($ulevel===2 && $lvl===3);
      $isOwnerUser = ($lvl===1);
    ?>
    <div class="urow" id="ur-<?=$u->id?>">
      <div class="u-who">
        <span class="av av-<?= $lvl ?>"><?= strtoupper(substr($u->username,0,2)) ?></span>
        <div class="li-x">
          <b><?=htmlspecialchars($u->username)?></b>
          <span><?= $u->last_login ? 'Last '.date('d M, H:i',strtotime($u->last_login)) : 'Never signed in' ?><?= $lastIp ? ' · '.htmlspecialchars($lastIp) : '' ?></span>
        </div>
      </div>
      <div class="u-tags">
        <span class="bx <?=$lcls?>"><?=$lname?></span>
        <?php if($u->status==1): ?><span class="bx b-active">Active</span>
        <?php elseif($u->status==2): ?><span class="bx b-exp">Pending</span>
        <?php else: ?><span class="bx b-ban">Banned</span><?php endif; ?>
      </div>
      <div class="u-meta"><small>Balance</small><b><?= $lvl===1 ? 'Unlimited' : number_format((float)$u->balance,0) ?></b></div>
      <div class="u-meta"><small>Referral</small><b><?= $u->ref_code_used ? htmlspecialchars($u->ref_code_used).($u->ref_giver ? ' · '.htmlspecialchars($u->ref_giver) : '') : '—' ?></b></div>
      <div class="u-meta"><small>Expires</small><b<?= $exp ? ' style="color:var(--danger)"' : '' ?>><?=$u->expiration_date ? date('d M Y',strtotime($u->expiration_date)) : 'Never'?></b></div>
      <div class="u-acts">
        <?php if ($u->id != $_SESSION['uid']): ?>
          <?php if($canManage && !$isOwnerUser): ?>
            <?php if ($u->status == 1): ?>
              <button class="btn btn-red icon-btn" title="Ban" aria-label="Ban" onclick="openBanModal(<?=$u->id?>,'<?=$un?>',<?=$lvl?>)"><?= phIcon('prohibit') ?></button>
            <?php else: ?>
              <button class="btn btn-acc icon-btn" title="Unban" aria-label="Unban" onclick="uAct(<?=$u->id?>,'unban')"><?= phIcon('check-circle') ?></button>
              <?php if ($ulevel===1 && $lastIp): ?>
              <button class="btn btn-blue icon-btn" title="Unblock IP" aria-label="Unblock IP" onclick="unblockIp('<?=htmlspecialchars($lastIp)?>')"><?= phIcon('shield-check') ?></button>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($ulevel===1): ?>
            <button class="btn icon-btn" title="Change password" aria-label="Change password" onclick="openPassModal(<?=$u->id?>,'<?=$un?>')"><?= phIcon('password') ?></button>
            <?php endif; ?>
            <button class="btn icon-btn" title="Extend validity" aria-label="Extend validity" onclick="openExtendModal(<?=$u->id?>,'<?=$un?>','<?=$u->expiration_date?date('d M Y',strtotime($u->expiration_date)):'Never'?>')"><?= phIcon('clock-clockwise') ?></button>
            <?php if ($ulevel===1): ?>
            <button class="btn icon-btn" title="Change role" aria-label="Change role" onclick="openRoleModal(<?=$u->id?>,'<?=$un?>',<?=$lvl?>)"><?= phIcon('identification-badge') ?></button>
            <button class="btn icon-btn" title="Referral limit" aria-label="Referral limit" onclick="openLimitModal(<?=$u->id?>,'<?=$un?>',<?=(int)($u->referral_limit??0)?>)"><?= phIcon('ticket') ?></button>
            <button class="btn btn-red icon-btn" title="Delete user" aria-label="Delete user" onclick="openDeleteModal(<?=$u->id?>,'<?=$un?>')"><?= phIcon('trash') ?></button>
            <?php endif; ?>
          <?php endif; ?>
          <?php if($ulevel===1 && $isOwnerUser): ?>
          <button class="btn icon-btn" title="Change role" aria-label="Change role" onclick="openRoleModal(<?=$u->id?>,'<?=$un?>',<?=$lvl?>)"><?= phIcon('identification-badge') ?></button>
          <?php endif; ?>
        <?php else: ?>
          <span class="bx b-unused">You</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- BAN MODAL -->
<div class="modal-bg" id="banModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> Ban user</div><button class="mcls" onclick="closeModal('banModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="bm-user" readonly></div>
      <div id="bm-optAdminResellers" class="perm-row" style="display:none">
        <div class="perm-txt"><div class="perm-title">Also ban this admin's resellers</div></div>
        <label class="tog"><input type="checkbox" id="bm-alsoResellers"><span class="togsl"></span></label>
      </div>
      <div id="bm-optKeys" class="perm-row" style="display:none">
        <div class="perm-txt"><div class="perm-title" id="bm-keysLabel">Also block all keys</div><div class="perm-sub" id="bm-keysSub"></div></div>
        <label class="tog"><input type="checkbox" id="bm-alsoKeys"><span class="togsl"></span></label>
      </div>
      <?php if($ulevel===1): ?>
      <div class="perm-row">
        <div class="perm-txt"><div class="perm-title">Also block IP address</div></div>
        <label class="tog"><input type="checkbox" id="bm-blockip"><span class="togsl"></span></label>
      </div>
      <?php endif; ?>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('banModal')">Cancel</button>
      <button class="btn btn-red" onclick="doBan()">Ban user</button>
    </div>
  </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div class="modal-bg" id="passModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> Change password</div><button class="mcls" onclick="closeModal('passModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">User</label><input class="fi fi-mono" id="pm-user" readonly></div>
      <div class="fg"><label class="fl" for="pm-newpass">New password (min 4 characters)</label><input class="fi fi-mono" id="pm-newpass" type="text" autocomplete="off"></div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('passModal')">Cancel</button>
      <button class="btn btn-acc" onclick="savePass()">Change password</button>
    </div>
  </div>
</div>

<!-- EXTEND VALIDITY MODAL -->
<div class="modal-bg" id="extendModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> Extend validity</div><button class="mcls" onclick="closeModal('extendModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="g2" style="gap:12px">
        <div class="fg"><label class="fl">User</label><input class="fi fi-mono" id="xm-user" readonly></div>
        <div class="fg"><label class="fl">Current expiry</label><input class="fi fi-mono" id="xm-cur" readonly></div>
      </div>
      <div class="fg">
        <label class="fl" for="xm-days">Days to add (negative to reduce)</label>
        <input class="fi fi-mono" id="xm-days" type="number" value="30">
      </div>
      <div class="chips">
        <button type="button" class="chip" onclick="document.getElementById('xm-days').value=7">+7</button>
        <button type="button" class="chip" onclick="document.getElementById('xm-days').value=30">+30</button>
        <button type="button" class="chip" onclick="document.getElementById('xm-days').value=90">+90</button>
        <button type="button" class="chip" onclick="document.getElementById('xm-days').value=365">+365</button>
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
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> Delete user</div><button class="mcls" onclick="closeModal('deleteModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="dm-user" readonly></div>
      <div class="perm-row">
        <div class="perm-txt"><div class="perm-title">Also block IP address</div></div>
        <label class="tog"><input type="checkbox" id="dm-blockip"><span class="togsl"></span></label>
      </div>
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-red" onclick="doDelete()">Delete user</button>
    </div>
  </div>
</div>

<!-- SET ROLE MODAL -->
<div class="modal-bg" id="roleModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> Change role</div><button class="mcls" onclick="closeModal('roleModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="rm-uname" readonly></div>
      <div class="fg">
        <label class="fl" for="rm-level">Role</label>
        <select class="fi" id="rm-level">
          <option value="1">Owner (unlimited balance)</option>
          <option value="2">Admin</option>
          <option value="3">Reseller</option>
        </select>
      </div>
      <input type="hidden" id="rm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('roleModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveRole()">Save role</button>
    </div>
  </div>
</div>

<!-- DELETE ALL USERS MODAL -->
<div class="modal-bg" id="deleteAllModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot" style="background:var(--danger)"></span> Delete all users</div><button class="mcls" onclick="closeModal('deleteAllModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <p style="font-size:13.5px;line-height:1.6;margin-bottom:14px">Every admin and reseller is deleted permanently. Owner accounts are kept.</p>
      <label class="fl" for="da-confirm">Type DELETE to confirm</label>
      <input class="fi fi-mono" id="da-confirm" autocomplete="off" oninput="document.getElementById('da-btn').disabled=this.value!=='DELETE'" style="letter-spacing:2px">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('deleteAllModal')">Cancel</button>
      <button class="btn btn-red" id="da-btn" disabled onclick="doDeleteAll()">Delete all users</button>
    </div>
  </div>
</div>

<!-- SET LIMIT MODAL -->
<div class="modal-bg" id="limitModal">
  <div class="mbox mbox-sm">
    <div class="mhead"><div class="mtit"><span class="mdot"></span> Referral limit</div><button class="mcls" onclick="closeModal('limitModal')" aria-label="Close">×</button></div>
    <div class="mbody">
      <div class="fg"><label class="fl">Username</label><input class="fi fi-mono" id="lm-uname" readonly></div>
      <div class="fg"><label class="fl" for="lm-limit">Max referral codes (0 = unlimited)</label><input class="fi fi-mono" type="number" id="lm-limit" min="0" value="0"></div>
      <input type="hidden" id="lm-uid" value="">
    </div>
    <div class="mfoot">
      <button class="btn btn-ghost" onclick="closeModal('limitModal')">Cancel</button>
      <button class="btn btn-acc" onclick="saveLimit()">Save limit</button>
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
  const list=document.getElementById('usersList');
  if(!list) return;
  const lq=q.toLowerCase().trim();
  list.querySelectorAll('.urow').forEach(r=>{ r.style.display=(!lq||r.textContent.toLowerCase().includes(lq))?'':'none'; });
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
      dd.innerHTML=list.map(u=>`<div onclick="pickU('${u.username}')" style="padding:10px 14px;cursor:pointer;font-size:12.5px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line2);transition:.1s" onmouseover="this.style.background='var(--paper)'" onmouseout="this.style.background=''"><span style="font-weight:700">${u.username}</span><span style="color:var(--muted2);font-size:11px">${lv[u.level]||''}</span></div>`).join('');
      dd.style.display='block';
    }catch(e){ dd.style.display='none'; }
  },250);
}
function pickU(name){ document.getElementById('userSearch').value=name; document.getElementById('uDrop').style.display='none'; applyF(); }
document.addEventListener('click',e=>{ if(!e.target.closest('#userSearch')&&!e.target.closest('#uDrop')) document.getElementById('uDrop').style.display='none'; });
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
