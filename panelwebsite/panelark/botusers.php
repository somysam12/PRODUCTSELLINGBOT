<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'Owner';

// ── AJAX actions ──
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $uid = (int)($_POST['uid']??0);

    if ($_POST['action']==='reset_chat') {
        // Unlink the Telegram chat so the user can log in from a new Telegram
        $t = db()->one("SELECT username,branch FROM users WHERE id=?", [$uid]);
        if ($t && ($t->branch ?? 'main') !== currentBranch()) { echo json_encode(['ok'=>false,'msg'=>'User not found']); exit; }
        if ($t) {
            db()->q("UPDATE users SET tg_chat_id=NULL, tg_login_state=NULL, tg_state_data=NULL WHERE id=?", [$uid]);
            logAct($_SESSION['uid'],$uname,'RESET_BOT_CHAT',"Unlinked TG for {$t->username}");
            echo json_encode(['ok'=>true,'msg'=>'Telegram unlinked. User can log in from a new Telegram now.']);
        } else echo json_encode(['ok'=>false,'msg'=>'User not found']);
        exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$pageTitle='Bot Users'; $activeNav='botusers';
require_once __DIR__.'/app/Views/Layout/header.php';

// All users currently linked to a Telegram chat
$linked = db()->all("SELECT id,username,level,tg_chat_id,last_login,status FROM users WHERE tg_chat_id IS NOT NULL AND tg_chat_id<>'' AND branch=? ORDER BY level ASC, username ASC", [currentBranch()]);
$levelMap = [1=>'Owner',2=>'Admin',3=>'Reseller'];
$levelCls = [1=>'b-owner',2=>'b-admin',3=>'b-reseller'];
$botOn = getSetting('tg_bot_enabled','0')==='1';
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px">
  <div style="min-width:0">
    <h1 style="font-size:22px;font-weight:600;letter-spacing:-.5px;margin-bottom:4px">Bot Users</h1>
    <p style="font-size:13px;color:var(--muted)">Telegram accounts connected to the bot. Reset a link to free the account for a new Telegram.</p>
  </div>
  <span class="bx <?= $botOn?'b-active':'b-ban' ?>"><?= $botOn?'Bot ON':'Bot OFF' ?></span>
</div>

<div class="card">
  <div class="card-h"><span class="ct">🔗 Connected Accounts (<?= count($linked) ?>)</span></div>
  <div class="card-b" style="padding:0">
    <?php if (!$linked): ?>
      <div style="padding:32px;text-align:center;color:var(--muted)">No Telegram accounts linked yet.</div>
    <?php else: ?>
    <div class="tw">
      <table>
        <thead><tr>
          <th>User</th><th>Role</th><th>Chat ID</th><th>Last Login</th><th style="text-align:right">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($linked as $r): ?>
          <tr>
            <td data-l="User"><span class="mono"><?= htmlspecialchars($r->username) ?></span>
              <?php if((int)$r->status===0): ?><span class="bx b-ban" style="margin-left:6px;font-size:10px">banned</span><?php endif; ?>
            </td>
            <td data-l="Role"><span class="bx <?= $levelCls[(int)$r->level] ?>"><?= $levelMap[(int)$r->level] ?></span></td>
            <td data-l="Chat ID"><span class="mono" style="font-size:12px"><?= htmlspecialchars($r->tg_chat_id) ?></span></td>
            <td data-l="Last Login"><span style="color:var(--muted);font-size:12px"><?= $r->last_login ? date('d M, H:i',strtotime($r->last_login)) : '—' ?></span></td>
            <td data-l="Action" style="text-align:right">
              <button class="btn btn-red btn-sm" onclick="resetChat(<?= (int)$r->id ?>,'<?= htmlspecialchars(addslashes($r->username)) ?>')">Reset Link</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="info-box" style="margin-top:16px">
  <b>How single-device works:</b> one Telegram = one account. If a user changes phone/Telegram,
  press <b>Reset Link</b> — that frees their account so they can log in with their username &amp;
  password from the new Telegram. Until you reset, the old Telegram stays locked to that account.
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
async function resetChat(uid, name){
  if(!confirm('Reset Telegram link for '+name+'?\nThey will need to /start and log in again from any Telegram.')) return;
  const fd = new FormData();
  fd.append('action','reset_chat'); fd.append('uid',uid); fd.append('csrf',csrf);
  const r = await fetch('/botusers.php',{method:'POST',body:fd}).then(x=>x.json()).catch(()=>({ok:false,msg:'Network error'}));
  if(r.ok){ toast(r.msg||'Done','ok'); setTimeout(()=>location.reload(),800); }
  else toast(r.msg||'Failed','e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
