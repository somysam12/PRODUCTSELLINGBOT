<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    // Session-expiry safe: return JSON (not an HTML redirect) so the JS shows a clean message
    if (!isLoggedIn()) { echo json_encode(['ok'=>false,'msg'=>'Session expired. Please log in again.','relogin'=>true]); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    $uid  = (int)($_SESSION['uid'] ?? 0);
    $me   = db()->one("SELECT * FROM users WHERE id=?", [$uid]);
    if (!$me) { echo json_encode(['ok'=>false,'msg'=>'Account not found.']); exit; }

    // Every change on this page is gated on the current password.
    $current = (string)($_POST['current_password'] ?? '');
    if ($current === '' || !password_verify($current, $me->password)) {
        echo json_encode(['ok'=>false,'msg'=>'Current password is incorrect.']); exit;
    }

    switch ($_POST['action']) {

        case 'change_username':
            $new = strtolower(trim($_POST['new_username'] ?? ''));
            if ($new === $me->username) { echo json_encode(['ok'=>false,'msg'=>'That is already your username.']); exit; }
            if (strlen($new) < 4 || strlen($new) > 32) { echo json_encode(['ok'=>false,'msg'=>'Username must be 4–32 characters.']); exit; }
            if (!preg_match('/^[a-z0-9_]+$/', $new)) { echo json_encode(['ok'=>false,'msg'=>'Only letters, numbers and underscore.']); exit; }
            if (db()->one("SELECT id FROM users WHERE username=? AND id<>?", [$new, $uid])) {
                echo json_encode(['ok'=>false,'msg'=>'That username is already taken.']); exit;
            }

            $old = $me->username;
            try {
                // The username is stored as a plain string across several tables.
                // If these are not renamed together the account loses sight of its
                // own keys and referrals, so it all happens in one transaction.
                db()->tx(function($d) use ($old, $new, $uid) {
                    $d->q("UPDATE users           SET username=?    WHERE id=?",           [$new, $uid]);
                    $d->q("UPDATE keys_code       SET registrator=? WHERE registrator=?",  [$new, $old]);
                    $d->q("UPDATE referral_codes  SET created_by=?  WHERE created_by=?",   [$new, $old]);
                    $d->q("UPDATE referral_codes  SET used_by=?     WHERE used_by=?",      [$new, $old]);
                    $d->q("UPDATE users           SET referred_by=? WHERE referred_by=?",  [$new, $old]);
                    $d->q("UPDATE users           SET ref_giver=?   WHERE ref_giver=?",    [$new, $old]);
                    $d->q("UPDATE activity_logs   SET username=?    WHERE username=?",     [$new, $old]);
                });
            } catch (Throwable $e) {
                echo json_encode(['ok'=>false,'msg'=>'Could not rename the account. Nothing was changed.']); exit;
            }

            sessionSet('uname', $new);
            logAct($uid, $new, 'CHANGE_USERNAME', "Renamed account {$old} -> {$new} (keys, referrals and logs moved across)");
            echo json_encode(['ok'=>true,'msg'=>'Username changed to '.$new,'uname'=>$new]); exit;

        case 'change_password':
            $new  = (string)($_POST['new_password'] ?? '');
            $conf = (string)($_POST['confirm_password'] ?? '');
            if (strlen($new) < 6)  { echo json_encode(['ok'=>false,'msg'=>'New password must be at least 6 characters.']); exit; }
            if ($new !== $conf)    { echo json_encode(['ok'=>false,'msg'=>'The two new passwords do not match.']); exit; }
            if ($new === $current) { echo json_encode(['ok'=>false,'msg'=>'New password must be different from the current one.']); exit; }

            db()->q("UPDATE users SET password=? WHERE id=?",
                [password_hash($new, PASSWORD_BCRYPT, ['cost'=>10]), $uid]);
            logAct($uid, $me->username, 'CHANGE_PASSWORD', 'Password changed from the account page');
            echo json_encode(['ok'=>true,'msg'=>'Password updated.']); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

$pageTitle = 'My Account';
$activeNav = 'account';
require_once __DIR__.'/app/Views/Layout/header.php';

$me = db()->one("SELECT username, level, balance, created_at, last_login FROM users WHERE id=?", [(int)$_SESSION['uid']]);
$ROLE = [1=>'Owner', 2=>'Admin', 3=>'Reseller'];
$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="g2">

  <div class="card">
    <div class="card-h"><span class="ct"><?=svgIcon('user',15)?> Change username</span></div>
    <div class="card-b">
      <div class="alert a-warn" style="margin-bottom:16px">
        <?=svgIcon('alert',14)?>
        <span>Your keys, referral codes and history move with you automatically.
        You will sign in with the new name from now on.</span>
      </div>
      <div class="fg">
        <label class="fl">Current username</label>
        <input class="fi" value="<?=htmlspecialchars($me->username ?? '')?>" readonly>
      </div>
      <div class="fg">
        <label class="fl">New username</label>
        <input class="fi" id="newUsername" placeholder="4–32 chars, a–z 0–9 _" maxlength="32" autocomplete="off">
        <div class="fi-hint">Lowercase letters, numbers and underscore only.</div>
      </div>
      <div class="fg">
        <label class="fl">Current password</label>
        <div style="position:relative">
          <input class="fi" type="password" id="uCurPass" placeholder="Confirm it's you" autocomplete="current-password" style="padding-right:42px">
          <button type="button" class="login-input-icon-right" onclick="togglePass('uCurPass')" title="Show/hide"><?=svgIcon('eye',15)?></button>
        </div>
      </div>
      <button class="btn btn-acc btn-full" id="unBtn" onclick="saveUsername()">Change username</button>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><span class="ct"><?=svgIcon('lock',15)?> Change password</span></div>
      <div class="card-b">
        <div class="fg">
          <label class="fl">Current password</label>
          <div style="position:relative">
            <input class="fi" type="password" id="pCurPass" placeholder="Your password right now" autocomplete="current-password" style="padding-right:42px">
            <button type="button" class="login-input-icon-right" onclick="togglePass('pCurPass')" title="Show/hide"><?=svgIcon('eye',15)?></button>
          </div>
        </div>
        <div class="fg">
          <label class="fl">New password</label>
          <div style="position:relative">
            <input class="fi" type="password" id="newPass" placeholder="At least 6 characters" autocomplete="new-password" style="padding-right:42px">
            <button type="button" class="login-input-icon-right" onclick="togglePass('newPass')" title="Show/hide"><?=svgIcon('eye',15)?></button>
          </div>
        </div>
        <div class="fg">
          <label class="fl">Repeat new password</label>
          <input class="fi" type="password" id="confPass" placeholder="Type it again" autocomplete="new-password">
        </div>
        <button class="btn btn-acc btn-full" id="pwBtn" onclick="savePassword()">Change password</button>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><span class="ct"><?=svgIcon('info',15)?> Account</span></div>
      <div class="card-b">
        <div class="srow"><span>Role</span><span class="bx b-<?=strtolower($ROLE[(int)$me->level] ?? 'reseller')?>"><?=$ROLE[(int)$me->level] ?? 'User'?></span></div>
        <div class="srow"><span>Balance</span><span style="color:var(--acc);font-weight:600"><?=number_format((float)$me->balance,2)?></span></div>
        <div class="srow"><span>Member since</span><span style="color:var(--muted)"><?=$me->created_at ? date('d M Y', strtotime($me->created_at)) : '—'?></span></div>
        <div class="srow"><span>Last sign-in</span><span style="color:var(--muted)"><?=$me->last_login ? date('d M Y, H:i', strtotime($me->last_login)) : '—'?></span></div>
      </div>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

async function saveUsername(){
  const name = document.getElementById('newUsername').value.trim().toLowerCase();
  const pass = document.getElementById('uCurPass').value;
  if(!name){ toast('Enter the new username','e'); return; }
  if(!pass){ toast('Enter your current password','e'); return; }
  const btn = document.getElementById('unBtn'); btn.disabled = true;
  const r = await formPost('/account.php',{action:'change_username',new_username:name,current_password:pass,csrf});
  btn.disabled = false;
  if(!r.ok){ toast(r.msg,'e'); return; }
  toast(r.msg,'s');
  document.getElementById('uCurPass').value = '';
  document.getElementById('newUsername').value = '';
  // Reflect the new name in the sidebar without a reload.
  document.querySelectorAll('.un').forEach(el => el.textContent = r.uname);
  const cur = document.querySelector('input[readonly]');
  if(cur) cur.value = r.uname;
}

async function savePassword(){
  const cur  = document.getElementById('pCurPass').value;
  const nw   = document.getElementById('newPass').value;
  const conf = document.getElementById('confPass').value;
  if(!cur){ toast('Enter your current password','e'); return; }
  if(nw.length < 6){ toast('New password must be at least 6 characters','e'); return; }
  if(nw !== conf){ toast('The two new passwords do not match','e'); return; }
  const btn = document.getElementById('pwBtn'); btn.disabled = true;
  const r = await formPost('/account.php',{action:'change_password',current_password:cur,new_password:nw,confirm_password:conf,csrf});
  btn.disabled = false;
  if(r.relogin){ toast('Session expired — redirecting to login','e'); setTimeout(()=>location.href='/',1200); return; }
  if(!r.ok){ toast(r.msg,'e'); return; }
  toast(r.msg,'s');
  ['pCurPass','newPass','confPass'].forEach(id => document.getElementById(id).value = '');
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
