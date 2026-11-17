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
$authTitle = 'Reset login device'; $authHeading = 'Reset login device'; $authSub = 'Unbind your account so you can sign in from a new device.';
require __DIR__.'/app/Views/Layout/auth_head.php';
?>
      <?php if($msg): ?>
      <div class="alert a-<?=$msgType==='ok'?'ok':'err'?>"><?= phIcon($msgType==='ok'?'check-circle':'prohibit') ?> <?=htmlspecialchars($msg)?></div>
      <?php endif; ?>
      <?php if(!$done): ?>
      <div class="alert a-warn"><?= phIcon('warning') ?> Only the panel login binding is reset. Your license keys are not changed.</div>
      <form method="POST">
        <?=csrfField()?>
        <div class="af">
          <label for="rUser">Username</label>
          <div class="af-inp"><?= phIcon('user') ?>
            <input type="text" id="rUser" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false">
          </div>
        </div>
        <div class="af">
          <label for="rPass">Password</label>
          <div class="af-inp"><?= phIcon('lock-key') ?>
            <input type="password" id="rPass" name="password" required autocomplete="current-password">
            <button type="button" class="af-eye" onclick="authEye(this)" aria-label="Show password"><span class="e1"><?= phIcon('eye') ?></span><span class="e2" hidden><?= phIcon('eye-slash') ?></span></button>
          </div>
        </div>
        <button type="submit" class="btn-login" style="margin-top:6px"><?= phIcon('device-mobile') ?> Reset login device</button>
      </form>
      <?php else: ?>
      <a href="/" class="btn btn-solid btn-full"><?= phIcon('sign-in') ?> Back to sign in</a>
      <?php endif; ?>
      <div class="auth-foot"><span><a href="/">Back to sign in</a></span></div>
<?php require __DIR__.'/app/Views/Layout/auth_foot.php'; ?>
