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
$authTitle = 'Register'; $authHeading = 'Create account'; $authSub = 'You need a referral code from your admin or owner.';
require __DIR__.'/app/Views/Layout/auth_head.php';
?>
      <?php if($success): ?>
      <div class="alert a-ok"><?= phIcon('check-circle') ?> Account created. You can sign in now.</div>
      <a href="/" class="btn btn-solid btn-full"><?= phIcon('sign-in') ?> Go to sign in</a>
      <?php endif; ?>
      <?php if($errors): ?>
      <div class="alert a-err" style="flex-direction:column;align-items:flex-start;gap:5px">
        <?php foreach($errors as $e): ?><div style="display:flex;align-items:center;gap:8px"><?= phIcon('prohibit') ?> <?=htmlspecialchars($e)?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if(!$success): ?>
      <form method="POST">
        <?=csrfField()?>
        <div class="fld">
          <span class="fld-ic"><?= phIcon('user') ?></span>
          <input class="fld-in" type="text" id="regUser" name="username" placeholder=" " required minlength="4" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?=htmlspecialchars($_POST['username']??'')?>">
          <label class="fld-lb" for="regUser">Username</label>
        </div>
        <div class="fld">
          <span class="fld-ic"><?= phIcon('lock-key') ?></span>
          <input class="fld-in" type="password" id="regPass" name="password" placeholder=" " required minlength="6" autocomplete="new-password">
          <label class="fld-lb" for="regPass">Password</label>
          <button type="button" class="fld-eye" onclick="authEye(this)" aria-label="Show password"><span class="e1"><?= phIcon('eye') ?></span><span class="e2" hidden><?= phIcon('eye-slash') ?></span></button>
        </div>
        <div class="fld">
          <span class="fld-ic"><?= phIcon('ticket') ?></span>
          <input class="fld-in fld-code" type="text" id="regRef" name="referral" placeholder=" " required maxlength="6" minlength="6" autocomplete="off" autocapitalize="characters" spellcheck="false"
                 oninput="this.value=this.value.toUpperCase()" value="<?=htmlspecialchars($_POST['referral']??'')?>">
          <label class="fld-lb" for="regRef">Referral code</label>
        </div>
        <button type="submit" class="btn-login" style="margin-top:6px"><?= phIcon('user-plus') ?> Create account</button>
      </form>
      <?php endif; ?>
      <div class="auth-foot"><span>Already registered? <a href="/">Sign in</a></span></div>
<?php require __DIR__.'/app/Views/Layout/auth_foot.php'; ?>
