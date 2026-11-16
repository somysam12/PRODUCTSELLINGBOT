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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($panelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/panel.css?v=12">
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div>
  <div class="orb2"></div>
  <div class="orb3"></div>
  <div class="pts" id="pts"></div>

  <div class="login-wrap">
    <div class="login-card anim-in">

      <!-- Top brand -->
      <div class="login-top">
        <div class="brand-logo">
          <?php if ($panelLogo): ?>
            <img src="<?= htmlspecialchars($panelLogo) ?>" alt="Logo">
          <?php endif; ?>
        </div>
        <div class="brand-name"><?= htmlspecialchars($panelName) ?></div>
        <div class="brand-sub">Sign in to your account</div>
      </div>

      <!-- Form body -->
      <div class="login-body">
        <?php if ($timeout): ?>
        <div class="alert a-warn" style="margin-bottom:16px">
          <?= svgIcon('alert',14) ?> Session expired. Please sign in again.
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert a-err" style="margin-bottom:16px">
          <?= svgIcon('ban',14) ?> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
          <?= csrfField() ?>

          <!-- Username -->
          <div class="login-field">
            <label class="login-label" for="usernameInp">Username</label>
            <div class="login-inp-wrap">
              <input class="login-input" type="text" id="usernameInp" name="username"
                     placeholder="Enter your username" required autocomplete="username">
            </div>
          </div>

          <!-- Password -->
          <div class="login-field">
            <label class="login-label" for="passInp">Password</label>
            <div class="login-inp-wrap">
              <input class="login-input" type="password" id="passInp" name="password"
                     placeholder="Enter your password" required autocomplete="current-password"
                     style="padding-right:44px">
              <button type="button" class="login-input-icon-right" id="eyeBtn"
                      onclick="togglePassV()" title="Toggle password">
                <span id="eyeIco"><?= svgIcon('eye',16) ?></span>
              </button>
            </div>
          </div>

          <!-- Options -->
          <div class="login-opts">
            <label class="stay-lbl">
              <label class="tog" style="margin:0">
                <input type="checkbox" name="remember_me" value="1">
                <span class="togsl"></span>
              </label>
              Remember me
            </label>
            <a href="/forgot.php" class="forgot-link">Forgot password?</a>
          </div>

          <button type="submit" class="btn-login" id="loginBtn">
            <span id="loginTxt">Sign in</span>
            <span id="loginSpin" style="display:none">Signing in&hellip;</span>
          </button>
        </form>
      </div>

      <!-- Footer -->
      <div class="login-foot">
        <p>No account? <a href="/register.php">Register with referral code</a></p>
        <a href="/reset-device.php" class="reset-link">
          <?= svgIcon('phone',13) ?> Reset Login Device
        </a>
      </div>

    </div>
  </div>
</div>

<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js?v=7"></script>
<script>
spawnParticles('pts', 18);

let passVisible = false;
function togglePassV() {
  passVisible = !passVisible;
  document.getElementById('passInp').type = passVisible ? 'text' : 'password';
  document.getElementById('eyeIco').innerHTML = passVisible
    ? `<?= svgIcon('eye-off',16) ?>`
    : `<?= svgIcon('eye',16) ?>`;
}

document.getElementById('loginForm').addEventListener('submit', () => {
  document.getElementById('loginTxt').style.display = 'none';
  document.getElementById('loginSpin').style.display = 'flex';
  document.getElementById('loginBtn').disabled = true;
});
</script>
</body>
</html>
