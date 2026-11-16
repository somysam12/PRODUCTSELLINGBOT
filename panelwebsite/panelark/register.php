<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Helpers/icons.php';
ddosCheck();
if (isLoggedIn()) { header('Location: /dashboard.php'); exit; }

$errors=[]; $success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verifyCsrf($_POST['csrf']??'')) { $errors[]='Invalid token.'; }
    else {
        $username=strtolower(trim($_POST['username']??''));
        $password=$_POST['password']??'';
        $refCode=strtoupper(trim($_POST['referral']??''));
        if(strlen($username)<4) $errors[]='Username min 4 characters.';
        if(!preg_match('/^[a-z0-9_]+$/',$username)) $errors[]='Username: letters, numbers, underscore only.';
        if(strlen($password)<6) $errors[]='Password min 6 characters.';
        if(empty($refCode)) $errors[]='Referral code required.';
        if(empty($errors)){
            $ref=db()->one("SELECT * FROM referral_codes WHERE code=? AND is_used=0",[$refCode]);
            if(!$ref){ $errors[]='Invalid or used referral code.'; }
            else {
                $exists=db()->one("SELECT id FROM users WHERE username=?",[$username]);
                if($exists){ $errors[]='Username already taken.'; }
                else {
                    $expMap=['30 Days'=>30,'60 Days'=>60,'90 Days'=>90,'180 Days'=>180,'1 Year'=>365,'1 Week'=>7,'15 Days'=>15];
                    $days=$expMap[$ref->acc_expire]??30;
                    $expiry=date('Y-m-d H:i:s',strtotime("+{$days} days"));
                    $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>10]);
                    $refLimitNew = (int)($ref->referral_limit??0);
                    db()->q("INSERT INTO users(username,password,level,balance,referred_by,ref_code_used,ref_giver,status,expiration_date,referral_limit,branch) VALUES(?,?,?,?,?,?,?,1,?,?,?)",
                        [$username,$hash,(int)$ref->level,$ref->saldo,$ref->created_by,$refCode,$ref->created_by,$expiry,$refLimitNew,($ref->branch ?? 'main')]);
                    $newId=db()->lastId();
                    db()->q("UPDATE referral_codes SET is_used=1,used_by=?,used_at=NOW() WHERE id=?",[$username,$ref->id]);
                    db()->q("UPDATE users SET referrals_used=referrals_used+1 WHERE username=?",[$ref->created_by]);
                    logAct((int)$newId,$username,'REGISTER',"Via referral: {$refCode}");
                    $success=true;
                }
            }
        }
    }
    // PRG: on success, redirect to a GET so refresh/resume never re-POSTs the form
    if ($success) { $_SESSION['reg_success']=1; header('Location: /register.php?done=1'); exit; }
    if (!empty($errors)) { $_SESSION['reg_errors']=$errors; header('Location: /register.php'); exit; }
}

// GET: pull one-time flash state
if (isset($_GET['done']) && !empty($_SESSION['reg_success'])) { $success=true; unset($_SESSION['reg_success']); }
if (!empty($_SESSION['reg_errors'])) { $errors=$_SESSION['reg_errors']; unset($_SESSION['reg_errors']); }
$panelName=getSetting('panel_name','HB Panel');
$panelLogo=getSetting('panel_logo','');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Register — <?=htmlspecialchars($panelName)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/panel.css?v=12">
<style>
.ref-wrap{background:var(--acc-08);border:1px solid var(--acc-20);border-radius:var(--r2);padding:16px;margin-bottom:16px}
.ref-inp{width:100%;background:var(--s3);border:1px solid var(--line2);border-radius:var(--r3);color:var(--acc);padding:12px 14px;font-size:18px;font-weight:600;letter-spacing:3px;text-transform:uppercase;outline:none;transition:border-color .16s,box-shadow .16s}
.ref-inp:focus{border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-13)}
.ref-inp::placeholder{color:var(--muted);font-size:12.5px;font-weight:400;letter-spacing:.3px;text-transform:none}
</style>
</head>
<body>
<div class="login-scene">
  <div class="orb1"></div><div class="orb2"></div>
  <div class="pts" id="pts"></div>
  <div class="login-wrap" style="max-width:410px">
    <div class="login-card anim-in">
      <div class="login-top">
        <div class="brand-logo">
          <?php if($panelLogo): ?><img src="<?=htmlspecialchars($panelLogo)?>" alt=""><?php endif; ?>
        </div>
        <div class="brand-name">Create account</div>
        <div class="brand-sub">A referral code is required to register</div>
      </div>
      <div class="login-body">
        <?php if($success): ?>
        <div class="alert a-ok" style="margin-bottom:16px"><?=svgIcon('check',14)?> Account created! <a href="/" style="color:var(--acc);font-weight:700">Login now</a></div>
        <?php endif; ?>
        <?php if($errors): ?>
        <div class="alert a-err" style="margin-bottom:16px;flex-direction:column;align-items:flex-start;gap:4px">
          <?php foreach($errors as $e): ?><div style="display:flex;align-items:center;gap:6px"><?=svgIcon('ban',12)?> <?=htmlspecialchars($e)?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if(!$success): ?>
        <form method="POST">
          <?=csrfField()?>
          <div class="login-field">
            <label class="login-label" for="regUser">Username</label>
            <input class="login-input" type="text" id="regUser" name="username" placeholder="Min 4 chars, a-z 0-9 _" required minlength="4" autocomplete="username" value="<?=htmlspecialchars($_POST['username']??'')?>">
            <div class="fi-hint" style="margin-top:6px">Letters, numbers, underscore only</div>
          </div>
          <div class="login-field">
            <label class="login-label" for="regPass">Password</label>
            <div class="login-inp-wrap">
              <input class="login-input" type="password" id="regPass" name="password" placeholder="Min 6 characters" required minlength="6" autocomplete="new-password" style="padding-right:44px">
              <button type="button" class="login-input-icon-right" onclick="togglePass()">
                <span id="eyeIco2"><?=svgIcon('eye',16)?></span>
              </button>
            </div>
          </div>
          <div class="ref-wrap">
            <div style="font-size:12.5px;font-weight:500;color:var(--acc);margin-bottom:9px">Referral code <span style="color:var(--muted)">&mdash; required</span></div>
            <input class="ref-inp" type="text" name="referral" placeholder="6-character code, e.g. AB12CD" required maxlength="6" minlength="6" oninput="this.value=this.value.toUpperCase()" value="<?=htmlspecialchars($_POST['referral']??'')?>">
            <div style="font-size:12px;color:var(--muted);margin-top:9px">Ask your admin or owner for a valid referral code.</div>
          </div>
          <button type="submit" class="btn-login">Create account</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="login-foot">
        <p>Have an account? <a href="/">Login here</a></p>
      </div>
    </div>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js?v=7"></script>
<script>
spawnParticles('pts',16);
let pv=false;
function togglePass(){pv=!pv;document.getElementById('regPass').type=pv?'text':'password';document.getElementById('eyeIco2').innerHTML=pv?`<?=svgIcon('eye-off',16)?>`:`<?=svgIcon('eye',16)?>}`;}
</script>
</body>
</html>
