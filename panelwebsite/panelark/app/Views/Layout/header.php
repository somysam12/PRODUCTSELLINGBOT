<?php
require_once dirname(dirname(__DIR__)).'/Config/db.php';
require_once dirname(dirname(__DIR__)).'/Helpers/security.php';
require_once dirname(dirname(__DIR__)).'/Helpers/icons.php';
requireLogin();

$panelName  = getSetting('panel_name','HB Panel');
$panelLogo  = getSetting('panel_logo','');
$maintMode  = getSetting('maintenance_mode','0') === '1';
$csrf       = csrfToken();
$pageTitle  = $pageTitle ?? 'Dashboard';
$activeNav  = $activeNav ?? '';
$ulevel     = (int)($_SESSION['ulevel'] ?? 3);
$uname      = $_SESSION['uname'] ?? 'User';
$levelNames = [1=>'Owner',2=>'Admin',3=>'Reseller'];
$levelLabel = $levelNames[$ulevel] ?? 'User';

function ico($name) {
    // Legacy nav/page icon names → Phosphor duotone set (see app/Helpers/icons.php)
    static $map = [
      'dashboard'=>'squares-four','keys'=>'key','generate'=>'sparkle','random'=>'shuffle','extend'=>'clock-clockwise',
      'users'=>'users-three','approve'=>'seal-check','referral'=>'ticket','balance'=>'wallet','server'=>'hard-drives',
      'onedevice'=>'device-mobile','keyformat'=>'pencil-simple-line','pricing'=>'tag','iplimit'=>'shield-check',
      'settings'=>'gear-six','logs'=>'clipboard-text','logout'=>'sign-out','telegram'=>'telegram-logo',
      'update'=>'arrows-clockwise','lib'=>'package','heartbeat'=>'heartbeat','zap'=>'lightning','wipe'=>'trash',
      'online'=>'broadcast','brush'=>'paint-brush-broad','package'=>'package','robot'=>'robot',
    ];
    return phIcon($map[$name] ?? $name);
}

$navItems = [
  'dashboard' => ['ico'=>ico('dashboard'),'label'=>'Dashboard',       'href'=>'/dashboard.php', 'min'=>3],
  'allkeys'   => ['ico'=>ico('keys'),     'label'=>'All Keys',         'href'=>'/keys.php',      'min'=>3],
  'generate'  => ['ico'=>ico('generate'), 'label'=>'Generate Key',     'href'=>'/generate.php',  'min'=>3],
  'genrandom' => ['ico'=>ico('random'),   'label'=>'Random Keys',      'href'=>'/genrandom.php', 'min'=>3],
  'extend'    => ['ico'=>ico('extend'),   'label'=>'Extend Duration',  'href'=>'/extend.php',    'min'=>2],
  'users'     => ['ico'=>ico('users'),    'label'=>'Manage Users',     'href'=>'/users.php',     'min'=>2],
  'referral'  => ['ico'=>ico('referral'), 'label'=>'Referral Codes',   'href'=>'/referral.php',  'min'=>2],
  'balance'   => ['ico'=>ico('balance'),  'label'=>'Balance',          'href'=>'/balance.php',   'min'=>2],
  'panelset'  => ['ico'=>ico('server'),   'label'=>'Server & Maint.',  'href'=>'/panelset.php',  'min'=>1],
  'onedevice' => ['ico'=>ico('onedevice'),'label'=>'One Device',       'href'=>'/onedevice.php', 'min'=>1],
  'keyformat' => ['ico'=>ico('keyformat'),'label'=>'Key Format',       'href'=>'/keyformat.php', 'min'=>1],
  'pricing'   => ['ico'=>ico('pricing'),  'label'=>'Pricing',          'href'=>'/pricing.php',   'min'=>1],
  'iplimit'   => ['ico'=>ico('iplimit'),  'label'=>'IP Management',    'href'=>'/iplimit.php',   'min'=>1],
  'rebranding'=> ['ico'=>ico('brush'),      'label'=>'Manage Rebranding','href'=>'/manage_rebranding.php','min'=>1],
  'heartbeat' => ['ico'=>ico('heartbeat'),'label'=>'Heartbeat & Security','href'=>'/heartbeat.php', 'min'=>1],
  'kicks'     => ['ico'=>ico('zap'),     'label'=>'Heartbeat Kicks',  'href'=>'/heartbeatkicks.php','min'=>1],
  'pkg_manager'=>['ico'=>ico('package'),  'label'=>'Package Manager',  'href'=>'/pkg_manager.php',  'min'=>1],
  'panelalert'=> ['ico'=>ico('telegram'), 'label'=>'Panel Alert',      'href'=>'/panelalert.php','min'=>1],
  'botusers'  => ['ico'=>ico('robot'),    'label'=>'Bot Users',        'href'=>'/botusers.php','min'=>1],
  'panelupdate'=>['ico'=>ico('update'),   'label'=>'Panel Update',     'href'=>'/panelupdate.php','min'=>1],
  'settings'  => ['ico'=>ico('settings'), 'label'=>'Settings',         'href'=>'/settings.php',  'min'=>1],
  'logs'      => ['ico'=>ico('logs'),     'label'=>'Activity Logs',    'href'=>'/logs.php',      'min'=>1],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<meta name="theme-color" content="#1f1c17">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="apple-touch-icon" href="/assets/favicon.svg">
<title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($panelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('/assets/css/panel.css') ?>">
<script>(function(){try{var d=document.documentElement;if(localStorage.getItem('hb_theme')==='dark')d.setAttribute('data-theme','dark');if(localStorage.getItem('hb_rail')==='pin')d.classList.add('sb-pin');}catch(e){}})();</script>
</head>
<body>
<div id="mobOverlay" class="mob-overlay"></div>
<div class="app">

<!-- ═══ SIDE RAIL ═══ -->
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sb-logo">
    <div class="logo-box <?= $panelLogo ? '' : 'logo-box-default' ?>">
      <?php if ($panelLogo): ?>
        <img src="<?= htmlspecialchars($panelLogo) ?>" alt="Logo">
      <?php else: ?>
        <?= strtoupper(substr($panelName, 0, 2)) ?>
      <?php endif; ?>
    </div>
    <div class="sb-brand">
      <div class="logo-name"><?= htmlspecialchars($panelName) ?></div>
      <div class="logo-ver">v<?= htmlspecialchars(getSetting('panel_version','3.0.0')) ?></div>
    </div>
  </div>

  <div class="sb-nav">
    <div class="sb-sec">
      <span class="sb-lbl"><i>Main</i></span>
      <?php foreach (['dashboard','allkeys','generate','genrandom'] as $k):
        $item = $navItems[$k];
        if ($ulevel > $item['min']) continue; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>" title="<?= $item['label'] ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <?php if ($ulevel <= 2): ?>
    <div class="sb-sec">
      <span class="sb-lbl"><i>Management</i></span>
      <?php foreach (['extend','users','referral','balance'] as $k):
        $item = $navItems[$k];
        if ($ulevel > $item['min']) continue; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>" title="<?= $item['label'] ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($ulevel === 1): ?>
    <div class="sb-sec">
      <span class="sb-lbl"><i>System</i></span>
      <?php foreach (['panelset','onedevice','keyformat','pricing','iplimit','rebranding','heartbeat','kicks','pkg_manager','panelalert','botusers','panelupdate','settings','logs'] as $k):
        $item = $navItems[$k]; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>" title="<?= $item['label'] ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
        <?php if ($k === 'panelset' && $maintMode): ?><span class="nb nb-r">ON</span><?php endif; ?>
        <?php if ($k === 'onedevice' && getSetting('one_device_mode','0')==='1'): ?><span class="nb nb-g">ON</span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="sb-foot">
    <div class="uc">
      <div class="ua ua-<?= strtolower($levelLabel) ?>"><?= strtoupper(substr($uname, 0, 2)) ?></div>
      <div class="uc-txt">
        <div class="un"><?= htmlspecialchars($uname) ?></div>
        <div class="ur ur-<?= strtolower($levelLabel) ?>"><?= strtoupper($levelLabel) ?></div>
      </div>
      <a href="/logout.php" class="lout" title="Log out" aria-label="Log out"><?= ico('logout') ?></a>
    </div>
  </div>
</nav>

<!-- ═══ MAIN ═══ -->
<div class="main">
  <header class="topbar">
    <div class="tb-l">
      <button class="menu-tog" onclick="toggleSidebar()" aria-label="Toggle menu"><?= phIcon('list') ?></button>
      <div class="pg-title"><span><?= htmlspecialchars($pageTitle) ?></span><small><?= strtoupper($levelLabel) ?></small></div>
    </div>
    <div class="topbar-r">
      <?php
        // Remaining = time left until the FIXED 30-min session expires (from login).
        $sessStart  = $_SESSION['login_at'] ?? $_SESSION['last_act'] ?? time();
        $sessRemain = max(0, (defined('SESSION_LIFETIME')?SESSION_LIFETIME:1800) - (time() - (int)$sessStart));
      ?>
      <span class="pill p-on sess-timer" id="sessTimer" data-remain="<?= (int)$sessRemain ?>" title="Auto-logout timer — 30 minutes from login">
        <?= phIcon('timer') ?><span id="sessTimerTxt">--:--</span>
      </span>
      <?php if ($maintMode): ?>
        <span class="pill p-warn tb-status"><span class="dot"></span>MAINT</span>
      <?php else: ?>
        <span class="pill p-on tb-status"><span class="dot"></span>ONLINE</span>
      <?php endif; ?>
      <button class="theme-tog" id="themeTog" onclick="toggleTheme(this)" aria-label="Toggle dark mode" title="Dark mode">
        <span class="ic-sun"><?= phIcon('sun') ?></span><span class="ic-moon"><?= phIcon('moon') ?></span>
      </button>
      <a href="/logout.php" class="theme-tog tb-logout" aria-label="Log out" title="Log out"><?= phIcon('sign-out') ?></a>
    </div>
  </header>

  <script>
  // ── Session auto-logout countdown (fixed 30-min window from login) ──
  (function(){
    var el = document.getElementById('sessTimer');
    var txt = document.getElementById('sessTimerTxt');
    if(!el || !txt) return;
    var remain = parseInt(el.getAttribute('data-remain')||'0',10);
    function fmt(s){ var m=Math.floor(s/60), ss=s%60; return (m<10?'0':'')+m+':'+(ss<10?'0':'')+ss; }
    function tick(){
      if(remain<=0){
        txt.textContent='00:00';
        el.className='pill p-off sess-timer';
        window.location.href='/logout.php';
        return;
      }
      txt.textContent=fmt(remain);
      if(remain<=60) el.className='pill p-off sess-timer';
      else if(remain<=300) el.className='pill p-warn sess-timer';
      else el.className='pill p-on sess-timer';
      remain--;
    }
    tick(); setInterval(tick,1000);
  })();
  </script>

  <div class="content">

  <!-- Update banner -->
  <div id="updateBanner" class="top-note top-note-warn" style="display:none">
    <span class="top-note-txt"><?= ico('update') ?> <span id="updateBannerTxt">New panel update available</span></span>
    <a href="/panelupdate.php" class="btn btn-warn btn-xs">Update</a>
  </div>

  <?php if ($maintMode): ?>
  <div class="maint-bar show">
    <?= ico('server') ?><span>Maintenance mode is on — devices cannot connect.</span>
    <a href="/panelset.php" class="btn btn-warn btn-xs">Manage</a>
  </div>
  <?php endif; ?>

