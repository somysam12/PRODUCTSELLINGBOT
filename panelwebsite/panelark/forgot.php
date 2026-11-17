<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
$authTitle = 'Forgot password'; $authHeading = 'Forgot password'; $authSub = 'Passwords are reset by the panel owner or your admin.';
require __DIR__.'/app/Views/Layout/auth_head.php';
?>
      <div class="alert a-info"><?= phIcon('info') ?> Message the owner or the admin who gave you your referral code and ask them to set a new password for your username.</div>
      <a href="/" class="btn btn-solid btn-full"><?= phIcon('arrow-left') ?> Back to sign in</a>
<?php require __DIR__.'/app/Views/Layout/auth_foot.php'; ?>
