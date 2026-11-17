<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
ddosCheck();
if (isLoggedIn()) { header('Location: /dashboard.php'); exit; }

$error = ''; $timeout = isset($_GET['timeout']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf'] ?? '')) { $error = 'Invalid security token.'; }
    else {
        $r = attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
        if ($r['ok']) {
            if (!empty($_POST['remember_me'])) {
                $lt = 86400;
                $_SESSION['remember_until'] = time() + $lt;
                $_SESSION['remember_me']    = true;
                $remData = base64_encode(json_encode(['until'=>time()+$lt,'user'=>$_SESSION['uname']??'']));
                setcookie('hb_remember', $remData, time()+$lt, '/', '', false, true);
                setcookie(session_name(), session_id(), time()+$lt, '/');
                session_set_cookie_params($lt);
            }
            header('Location: /dashboard.php'); exit;
        }
        $error = $r['msg'];
    }
}
$panelName    = getSetting('panel_name','HB Panel');
$panelLogo    = getSetting('panel_logo','');
$panelTagline = getSetting('panel_tagline','Secure Admin Panel');
$panelVer     = getSetting('panel_version','3.0.0');
$authTitle = 'Sign in'; $authHeading = 'Sign in'; $authSub = 'Enter your panel username and password.';
require __DIR__.'/app/Views/Layout/auth_head.php';
?>
      <?php if ($timeout): ?>
      <div class="alert a-warn"><?= phIcon('timer') ?> Your session ended. Sign in again to continue.</div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert a-err"><?= phIcon('prohibit') ?> <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="loginForm">
        <?= csrfField() ?>
        <div class="af">
          <label for="usernameInp">Username</label>
          <div class="af-inp"><?= phIcon('user') ?>
            <input type="text" id="usernameInp" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false">
          </div>
        </div>
        <div class="af">
          <label for="passInp">Password</label>
          <div class="af-inp"><?= phIcon('lock-key') ?>
            <input type="password" id="passInp" name="password" required autocomplete="current-password">
            <button type="button" class="af-eye" onclick="authEye(this)" aria-label="Show password"><span class="e1"><?= phIcon('eye') ?></span><span class="e2" hidden><?= phIcon('eye-slash') ?></span></button>
          </div>
        </div>
        <div class="af-opts" style="justify-content:flex-end">
          <a href="/forgot.php" class="auth-link">Forgot password?</a>
        </div>
        <button type="submit" class="btn-login" id="loginBtn"><span id="loginTxt">Sign in</span></button>
      </form>

      <div class="auth-foot">
        <span>No account? <a href="/register.php">Register with a referral code</a></span>
        <a href="/reset-device.php" class="btn btn-sm"><?= phIcon('device-mobile') ?> Reset login device</a>
      </div>
<script>
document.getElementById('loginForm').addEventListener('submit', function(){
  var b = document.getElementById('loginBtn');
  document.getElementById('loginTxt').textContent = 'Signing in…';
  b.classList.add('is-loading');
  setTimeout(function(){ b.disabled = true; }, 0);
});
</script>
<?php require __DIR__.'/app/Views/Layout/auth_foot.php'; ?>
