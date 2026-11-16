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
    // Icon set: Lucide (github.com/lucide-icons/lucide, MIT) — 24px grid, 2px rounded stroke.
    $s = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
      'dashboard' => '<svg viewBox="0 0 24 24" '.$s.'><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>',
      'keys'      => '<svg viewBox="0 0 24 24" '.$s.'><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L21 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg>',
      'generate'  => '<svg viewBox="0 0 24 24" '.$s.'><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
      'random'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.1-8.6c.7-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.3 0-2.6-.7-3.3-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/></svg>',
      'extend'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12 2v2"/><path d="M12 14l3-2"/><circle cx="12" cy="14" r="8"/><path d="M5 5 3 7"/><path d="m19 5 2 2"/></svg>',
      'users'     => '<svg viewBox="0 0 24 24" '.$s.'><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      'approve'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
      'referral'  => '<svg viewBox="0 0 24 24" '.$s.'><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      'balance'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg>',
      'server'    => '<svg viewBox="0 0 24 24" '.$s.'><rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>',
      'onedevice' => '<svg viewBox="0 0 24 24" '.$s.'><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>',
      'keyformat' => '<svg viewBox="0 0 24 24" '.$s.'><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>',
      'pricing'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
      'iplimit'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>',
      'settings'  => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
      'logs'      => '<svg viewBox="0 0 24 24" '.$s.'><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>',
      'logout'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
      'telegram'  => '<svg viewBox="0 0 24 24" '.$s.'><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>',
      'update'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>',
      'lib'       => '<svg viewBox="0 0 24 24" '.$s.'><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="m7.5 4.27 9 5.15"/></svg>',
      'heartbeat' => '<svg viewBox="0 0 24 24" '.$s.'><path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/></svg>',
      'zap'       => '<svg viewBox="0 0 24 24" '.$s.'><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>',
      'wipe'      => '<svg viewBox="0 0 24 24" '.$s.'><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>',
      'online'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12 20h.01"/><path d="M8.5 16.429a5 5 0 0 1 7 0"/><path d="M5 12.859a10 10 0 0 1 14 0"/><path d="M2 8.82a15 15 0 0 1 20 0"/></svg>',
    ];
    return $icons[$name] ?? '';
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
  'rebranding'=> ['ico'=>ico('lib'),      'label'=>'Manage Rebranding','href'=>'/manage_rebranding.php','min'=>1],
  'heartbeat' => ['ico'=>ico('heartbeat'),'label'=>'Heartbeat & Security','href'=>'/heartbeat.php', 'min'=>1],
  'kicks'     => ['ico'=>ico('zap'),     'label'=>'Heartbeat Kicks',  'href'=>'/heartbeatkicks.php','min'=>1],
  'pkg_manager'=>['ico'=>ico('server'),  'label'=>'Package Manager',  'href'=>'/pkg_manager.php',  'min'=>1],
  'panelalert'=> ['ico'=>ico('telegram'), 'label'=>'Panel Alert',      'href'=>'/panelalert.php','min'=>1],
  'botusers'  => ['ico'=>ico('users'),    'label'=>'Bot Users',        'href'=>'/botusers.php','min'=>1],
  'panelupdate'=>['ico'=>ico('update'),   'label'=>'Panel Update',     'href'=>'/panelupdate.php','min'=>1],
  'settings'  => ['ico'=>ico('settings'), 'label'=>'Settings',         'href'=>'/settings.php',  'min'=>1],
  'logs'      => ['ico'=>ico('logs'),     'label'=>'Activity Logs',    'href'=>'/logs.php',      'min'=>1],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($panelName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/panel.css?v=15">
<script>(function(){try{if(localStorage.getItem('hb_theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
</head>
<body>
<div id="mobOverlay" class="mob-overlay"></div>
<div class="app">

<!-- ═══ SIDEBAR ═══ -->
<nav class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="logo-box <?= $panelLogo ? '' : 'logo-box-default' ?>">
      <?php if ($panelLogo): ?>
        <img src="<?= htmlspecialchars($panelLogo) ?>" alt="Logo">
      <?php else: ?>
        <?= strtoupper(substr($panelName, 0, 2)) ?>
      <?php endif; ?>
    </div>
    <div style="min-width:0">
      <div class="logo-name"><?= htmlspecialchars($panelName) ?></div>
      <div class="logo-ver">v<?= getSetting('panel_version','3.0.0') ?></div>
    </div>
  </div>

  <div class="sb-nav">
    <!-- MAIN -->
    <div class="sb-sec">
      <span class="sb-lbl">Main</span>
      <?php foreach (['dashboard','allkeys','generate','genrandom'] as $k):
        $item = $navItems[$k];
        if ($ulevel > $item['min']) continue; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- MANAGEMENT -->
    <?php if ($ulevel <= 2): ?>
    <div class="sb-sec">
      <span class="sb-lbl">Management</span>
      <?php foreach (['extend','users','referral','balance'] as $k):
        $item = $navItems[$k];
        if ($ulevel > $item['min']) continue; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- SYSTEM (Owner only) -->
    <?php if ($ulevel === 1): ?>
    <div class="sb-sec">
      <span class="sb-lbl">System</span>
      <?php foreach (['panelset','onedevice','keyformat','pricing','iplimit','rebranding','heartbeat','kicks','pkg_manager','panelalert','botusers','panelupdate','settings','logs'] as $k):
        $item = $navItems[$k]; ?>
      <a href="<?= $item['href'] ?>" class="ni <?= $activeNav === $k ? 'on' : '' ?>">
        <span class="ni-ico"><?= $item['ico'] ?></span>
        <span class="ni-lbl"><?= $item['label'] ?></span>
        <?php if ($k === 'panelset' && $maintMode): ?><span class="nb nb-r">ON</span><?php endif; ?>
        <?php if ($k === 'onedevice' && getSetting('one_device_mode','0')==='1'): ?><span class="nb nb-g">ON</span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- User footer -->
  <div class="sb-foot">
    <div class="uc">
      <div class="ua ua-<?= strtolower($levelLabel) ?>"><?= strtoupper(substr($uname, 0, 2)) ?></div>
      <div style="flex:1;min-width:0">
        <div class="un" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($uname) ?></div>
        <div class="ur ur-<?= strtolower($levelLabel) ?>">● <?= strtoupper($levelLabel) ?></div>
      </div>
      <a href="/logout.php" class="lout" title="Logout"><?= ico('logout') ?></a>
    </div>
  </div>
</nav>

<!-- ═══ MAIN CONTENT ═══ -->
<div class="main">
  <!-- Topbar -->
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;min-width:0">
      <button class="menu-tog" onclick="toggleSidebar()" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="22" height="22">
          <line x1="3" y1="6" x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <div class="pg-title"><?= htmlspecialchars($pageTitle) ?><small><?= strtoupper($levelLabel) ?></small></div>
    </div>
    <div class="topbar-r">
      <button class="theme-tog" id="themeTog" onclick="toggleTheme(this)" aria-label="Toggle dark mode" title="Dark mode">
        <svg class="ic-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
        <svg class="ic-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
      </button>
      <?php
        // Session logout countdown — remaining seconds until idle timeout
        // Remaining = time left until the FIXED 30-min session expires (from login).
        // Refresh does not reset it — it keeps counting from login_at.
        $sessRemain = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800;
        $sessStart  = $_SESSION['login_at'] ?? $_SESSION['last_act'] ?? time();
        $sessRemain = max(0, (defined('SESSION_LIFETIME')?SESSION_LIFETIME:1800) - (time() - (int)$sessStart));
      ?>
      <span class="pill p-on sess-timer" id="sessTimer" data-remain="<?= (int)$sessRemain ?>" title="Auto-logout timer — fixed 30 min from login">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:2px"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <span id="sessTimerTxt">--:--</span>
      </span>
      <?php if ($maintMode): ?>
        <span class="pill p-warn"><span class="dot"></span>MAINT</span>
      <?php else: ?>
        <span class="pill p-on"><span class="dot"></span>ONLINE</span>
      <?php endif; ?>
      <a href="/generate.php" class="btn btn-solid btn-sm">
        <?= ico('zap') ?> Generate
      </a>
    </div>
  </div>

  <script>
  // ── Session auto-logout countdown (30-min idle window) ──
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
        // time's up → log out
        window.location.href='/logout.php';
        return;
      }
      txt.textContent=fmt(remain);
      // color states: warn under 5 min, danger under 1 min
      if(remain<=60) el.className='pill p-off sess-timer';
      else if(remain<=300) el.className='pill p-warn sess-timer';
      else el.className='pill p-on sess-timer';
      remain--;
    }
    tick(); setInterval(tick,1000);
    // The timer reflects the FIXED 30-min session (from login). On refresh it
    // re-syncs to the true remaining time via data-remain — it does NOT restart
    // at 30:00. When it hits 0, the server session has also expired.
  })();
  </script>

  <!-- Update banner -->
  <div id="updateBanner" style="display:none;background:repeating-linear-gradient(45deg,rgba(255,176,32,.08) 0 10px,transparent 10px 20px);border-bottom:1px solid rgba(255,176,32,.3);padding:9px 26px;align-items:center;justify-content:space-between;gap:10px">
    <div style="display:flex;align-items:center;gap:10px;font-size:10.5px;font-family:var(--mono);letter-spacing:1.2px;text-transform:uppercase;color:var(--warn)">
      <?= ico('update') ?> <span id="updateBannerTxt">New panel update available</span>
    </div>
    <a href="/panelupdate.php" class="btn btn-warn btn-xs" style="background:var(--warn);color:#000;border-color:var(--warn)">Update</a>
  </div>

  <!-- Maintenance bar -->
  <?php if ($maintMode): ?>
  <div class="maint-bar show">
    <?= ico('server') ?>&nbsp; MAINTENANCE MODE — Devices cannot connect &nbsp;·&nbsp;
    <a href="/panelset.php" style="color:var(--warn)">Manage →</a>
  </div>
  <?php endif; ?>

  <div class="content">
