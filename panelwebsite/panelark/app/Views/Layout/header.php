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
    $s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
    $icons = [
      'dashboard' => '<svg viewBox="0 0 24 24" '.$s.'><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
      'keys'      => '<svg viewBox="0 0 24 24" '.$s.'><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>',
      'generate'  => '<svg viewBox="0 0 24 24" '.$s.'><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
      'random'    => '<svg viewBox="0 0 24 24" '.$s.'><polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/></svg>',
      'extend'    => '<svg viewBox="0 0 24 24" '.$s.'><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
      'users'     => '<svg viewBox="0 0 24 24" '.$s.'><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
      'approve'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
      'referral'  => '<svg viewBox="0 0 24 24" '.$s.'><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
      'balance'   => '<svg viewBox="0 0 24 24" '.$s.'><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
      'server'    => '<svg viewBox="0 0 24 24" '.$s.'><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
      'onedevice' => '<svg viewBox="0 0 24 24" '.$s.'><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>',
      'keyformat' => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
      'pricing'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
      'iplimit'   => '<svg viewBox="0 0 24 24" '.$s.'><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
      'settings'  => '<svg viewBox="0 0 24 24" '.$s.'><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
      'logs'      => '<svg viewBox="0 0 24 24" '.$s.'><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
      'logout'    => '<svg viewBox="0 0 24 24" '.$s.'><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
      'telegram'  => '<svg viewBox="0 0 24 24" '.$s.'><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
      'update'    => '<svg viewBox="0 0 24 24" '.$s.'><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4"/></svg>',
      'lib'       => '<svg viewBox="0 0 24 24" '.$s.'><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
      'heartbeat' => '<svg viewBox="0 0 24 24" '.$s.'><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
      'zap'       => '<svg viewBox="0 0 24 24" '.$s.'><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
      'wipe'      => '<svg viewBox="0 0 24 24" '.$s.'><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
      'online'    => '<svg viewBox="0 0 24 24" '.$s.'><circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49"/><path d="M7.76 7.76a6 6 0 0 0 0 8.49"/><path d="M20.48 3.52a12 12 0 0 1 0 16.97"/><path d="M3.52 3.52a12 12 0 0 0 0 16.97"/></svg>',
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
<link rel="stylesheet" href="/assets/css/panel.css?v=13">
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
