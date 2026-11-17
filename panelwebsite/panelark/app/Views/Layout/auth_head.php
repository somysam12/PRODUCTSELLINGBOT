<?php
// Shared shell for the signed-out pages (login, register, forgot, reset device).
// Expects: $authTitle, $authHeading, $authSub — and optionally $panelName / $panelLogo.
require_once dirname(__DIR__, 2).'/Helpers/icons.php';
$panelName    = $panelName    ?? getSetting('panel_name','HB Panel');
$panelLogo    = $panelLogo    ?? getSetting('panel_logo','');
$panelTagline = $panelTagline ?? getSetting('panel_tagline','Secure Admin Panel');
$panelVer     = $panelVer     ?? getSetting('panel_version','3.0.0');
$authMins     = (int)round((defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800) / 60);
$authTries    = defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#1f1c17">
<?php $favicon = $panelLogo ?: '/assets/favicon.svg'; ?>
<link rel="icon" href="<?= htmlspecialchars($favicon) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon) ?>">
<title><?= htmlspecialchars($authTitle) ?> — <?= htmlspecialchars($panelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/panel.css') ?>">
<script>(function(){try{if(localStorage.getItem('hb_theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
</head>
<body>
<button class="auth-theme" onclick="toggleTheme(this)" aria-label="Toggle dark mode" title="Dark mode">
  <span class="ic-sun"><?= phIcon('sun') ?></span><span class="ic-moon"><?= phIcon('moon') ?></span>
</button>
<div class="auth">
  <div class="auth-card">
    <div class="auth-card-glow" aria-hidden="true"></div>
    <div class="auth-badge-wrap">
      <span class="auth-badge <?= $panelLogo ? 'has-logo' : '' ?>">
        <?php if ($panelLogo): ?><img src="<?= htmlspecialchars($panelLogo) ?>" alt="">
        <?php else: ?><?= phIcon('key') ?><?php endif; ?>
      </span>
    </div>
    <div class="auth-brand"><?= htmlspecialchars($panelName) ?></div>
    <h2 class="auth-h"><?= htmlspecialchars($authHeading) ?></h2>
    <p class="auth-sub"><?= htmlspecialchars($authSub) ?></p>
