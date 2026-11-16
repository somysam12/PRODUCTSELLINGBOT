<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
$msg=''; $msgType=''; $done=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!verifyCsrf($_POST['csrf']??'')){ $msg='Invalid token.'; $msgType='err'; }
    else {
        $username=strtolower(trim($_POST['username']??''));
        $password=$_POST['password']??'';
        $u=db()->one("SELECT * FROM users WHERE username=?",[$username]);
        if(!$u||!password_verify($password,$u->password)){ $msg='Invalid username or password.'; $msgType='err'; }
        elseif($u->status==0){ $msg='Account is banned.'; $msgType='err'; }
        else {
            // Reset ONLY the panel login-IP/device binding (respect 24h limit). Does NOT touch keys.
            $blocked = false;
            try {
                $limit  = max(1,(int)getSetting('panel_device_reset_limit','1'));
                $resets = (int)($u->device_resets ?? 0);
                $last   = $u->device_reset_at ?? null;
                if ($last && strtotime($last) < strtotime('-24 hours')) $resets = 0; // window elapsed
                if ($resets >= $limit) {
                    $msg="Reset limit reached ({$limit} per 24h). Try again later.";
                    $msgType='err'; $blocked=true;
                } else {
                    db()->q("UPDATE users SET login_device=NULL, device_resets=?, device_reset_at=NOW() WHERE id=?",
                        [$resets+1, $u->id]);
                }
            } catch (Throwable $e) { /* columns missing → ignore */ }

            if (!$blocked) {
                logAct($u->id,$username,'RESET_LOGIN_IP','Reset panel login IP from login page');
                $msg='Login IP reset! You can now log in from a new device.'; $msgType='ok'; $done=true;
            }
        }
    }
    // PRG: stash result and redirect to GET so refresh/resume never re-POSTs
    if (session_status()===PHP_SESSION_ACTIVE) {
        $_SESSION['rd_flash'] = ['msg'=>$msg,'msgType'=>$msgType,'done'=>$done];
    }
    header('Location: /reset-device.php'); exit;
}
// GET: pull one-time flash
if (!empty($_SESSION['rd_flash'])) {
    $msg     = $_SESSION['rd_flash']['msg'] ?? '';
    $msgType = $_SESSION['rd_flash']['msgType'] ?? '';
    $done    = $_SESSION['rd_flash']['done'] ?? false;
    unset($_SESSION['rd_flash']);
}
$panelName=getSetting('panel_name','HB Panel');
$panelLogo=getSetting('panel_logo','');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Device — <?=htmlspecialchars($panelName)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/panel.css?v=12">
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div><div class="orb2"></div>
  <div class="pts" id="pts"></div>
  <div class="login-wrap">
    <div class="login-card anim-in">
      <div class="login-top">
        <div class="brand-logo" style="background:linear-gradient(140deg,var(--gold),var(--coral));color:#3a2c05">
          <div class="brand-logo-inner"><?=svgIcon('phone',19)?></div>
        </div>
        <div class="brand-name">Reset login device</div>
        <div class="brand-sub">Unbind your account from its current device</div>
      </div>
      <div class="login-body">
        <?php if($msg): ?>
        <div class="alert a-<?=$msgType?>" style="margin-bottom:16px">
          <?=svgIcon($msgType==='ok'?'check':'ban',14)?> <?=htmlspecialchars($msg)?>
        </div>
        <?php endif; ?>
        <?php if(!$done): ?>
        <div class="alert a-warn" style="margin-bottom:16px">
          <?=svgIcon('alert',14)?> This resets the device your account is bound to.
        </div>
        <form method="POST">
          <?=csrfField()?>
          <div class="login-field">
            <label class="login-label">Username</label>
            <input class="login-input" type="text" name="username" placeholder="Your username" required autocomplete="username">
          </div>
          <div class="login-field">
            <label class="login-label">Password</label>
            <div class="login-inp-wrap">
              <input class="login-input" type="password" name="password" id="rPass" placeholder="Your password" required autocomplete="current-password" style="padding-right:44px">
              <button type="button" class="login-input-icon-right" onclick="toggleR()"><span id="rEye"><?=svgIcon('eye',16)?></span></button>
            </div>
          </div>
          <button type="submit" class="btn-login" style="background:var(--gold);color:#3a2c05">
            Reset login device
          </button>
        </form>
        <?php else: ?>
        <a href="/" class="btn btn-acc btn-full" style="margin-top:8px">Back to sign in</a>
        <?php endif; ?>
      </div>
      <div class="login-foot"><p><a href="/">Back to sign in</a></p></div>
    </div>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js?v=7"></script>
<script>
spawnParticles('pts',16);
let rv=false;
function toggleR(){rv=!rv;document.getElementById('rPass').type=rv?'text':'password';document.getElementById('rEye').innerHTML=rv?`<?=svgIcon('eye-off',16)?>`:`<?=svgIcon('eye',16)?>`;}</script>
</body>
</html>
