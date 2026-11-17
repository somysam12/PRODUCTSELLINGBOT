<?php
// =========================================================
// MANAGE REBRANDING — Lib & APK Control Center
// =========================================================
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX HANDLERS (before HTML output) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv   = (int)($_SESSION['ulevel'] ?? 99);
    $uid   = $_SESSION['uid']   ?? 0;
    $uname = $_SESSION['uname'] ?? '';
    if ($ulv !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token']); exit; }

    $sec    = $_POST['section'] ?? '';
    $libDir = __DIR__.'/server/libs/';
    $apkDir = __DIR__.'/server/apks/';

    switch ($sec) {

        // ── Set base domain (drives ALL generated links) ──
        case 'set_base':
            $b = trim($_POST['base_url'] ?? '');
            if ($b !== '') {
                if (!preg_match('#^https?://[^\s/]+#i', $b)) {
                    echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
                }
                $b = rtrim($b, '/');
            }
            setSetting('panel_base_url', $b);
            logAct($uid, $uname, 'SET_BASE_URL', $b ?: '(default)');
            echo json_encode(['ok'=>true,'msg'=>$b ? "Base domain set to {$b}" : 'Base domain reset to default.']);
            exit;

        // ── Set lib kill-below version ────────────────────
        case 'lib_killer':
            $kb = trim($_POST['kill_below'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $kb)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version format (e.g. 4.2)']); exit;
            }
            $lr = trim($_POST['kill_redirect'] ?? '');
            if ($lr !== '' && !preg_match('#^https?://#i', $lr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('lib_kill_below', $kb);
            setSetting('lib_kill_enabled', '1');
            setSetting('lib_kill_redirect', $lr);
            logAct($uid, $uname, 'LIB_KILL_SET', "Kill below v$kb | Redirect: $lr");
            echo json_encode(['ok'=>true,'msg'=>"Lib Killer ACTIVATED! All libs below v{$kb} will crash on next check."]);
            exit;

        // ── Mod Lib: set version / kill / redirect ────────
        case 'modlib_save':
            $mv = trim($_POST['mod_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $mv)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 1.2)']); exit;
            }
            $mk = ($_POST['mod_kill'] ?? '0') === '1' ? '1' : '0';
            $mr = trim($_POST['mod_redirect'] ?? '');
            if ($mr !== '' && !preg_match('#^https?://#i', $mr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('modlib_version', $mv);
            setSetting('modlib_kill_enabled', $mk);
            setSetting('modlib_kill_redirect', $mr);
            logAct($uid, $uname, 'MODLIB_SAVE', "v$mv kill=$mk redir=$mr");
            echo json_encode(['ok'=>true,'msg'=>$mk==='1'
                ? 'Mod Lib KILL-ALL active — every mod lib will crash.'
                : "Mod version set to v{$mv}. Any older mod lib now crashes."]);
            exit;

        // ── Reset / disable lib killer ────────────────────
        case 'lib_killer_reset':
            setSetting('lib_kill_enabled', '0');
            setSetting('lib_kill_below', '0.0');
            logAct($uid, $uname, 'LIB_KILL_RESET', '');
            echo json_encode(['ok'=>true,'msg'=>'Lib Killer disabled. All versions allowed.']);
            exit;

        // ── Manually set lib version ──────────────────────
        case 'set_lib_version':
            $v = trim($_POST['new_lib_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 4.2)']); exit;
            }
            setSetting('lib_version', $v);
            ensureWritableDir($libDir);
            file_put_contents($libDir.'version.txt', $v);
            logAct($uid, $uname, 'LIB_VERSION_SET', "v$v");
            echo json_encode(['ok'=>true,'msg'=>"Lib version set to v{$v} and version.txt updated."]);
            exit;

        // ── APK killer settings ───────────────────────────
        case 'apk_killer':
            $on  = ($_POST['kill_on'] ?? '0') === '1' ? '1' : '0';
            $kv  = trim($_POST['kill_version'] ?? '');
            $km  = trim($_POST['kill_msg'] ?? 'This version has been expired. Please update your application.');
            $kr  = trim($_POST['kill_redirect'] ?? '');
            if ($kr !== '' && !preg_match('#^https?://#i', $kr)) {
                echo json_encode(['ok'=>false,'msg'=>'Redirect URL must start with http:// or https://']); exit;
            }
            setSetting('apk_kill_enabled', $on);
            setSetting('apk_kill_version_target', $kv);
            setSetting('apk_kill_message', $km);
            setSetting('apk_kill_redirect', $kr);
            logAct($uid, $uname, 'APK_KILL_'.($on?'ON':'OFF'), "Target: $kv | Redirect: $kr");
            $status = $on ? 'APK Killer ACTIVATED' : 'APK Killer disabled';
            echo json_encode(['ok'=>true,'msg'=>$status.' — settings saved!']);
            exit;

        // ── Manually set APK version ──────────────────────
        case 'set_apk_version':
            $v = trim($_POST['new_apk_version'] ?? '');
            if (!preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
                echo json_encode(['ok'=>false,'msg'=>'Invalid version (e.g. 1.5)']); exit;
            }
            setSetting('apk_version', $v);
            ensureWritableDir($apkDir);
            file_put_contents($apkDir.'version.txt', $v);
            logAct($uid, $uname, 'APK_VERSION_SET', "v$v");
            echo json_encode(['ok'=>true,'msg'=>"APK version set to v{$v} and version.txt updated."]);
            exit;

        // ── Delete a lib file ─────────────────────────────
        case 'delete_lib':
            $fn = basename($_POST['fname'] ?? '');
            if (!$fn || strpos($fn,'..') !== false) { echo json_encode(['ok'=>false,'msg'=>'Invalid file']); exit; }
            $fp = $libDir.$fn;
            if (!file_exists($fp)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
            unlink($fp);
            logAct($uid, $uname, 'LIB_DELETE', "File: $fn");
            echo json_encode(['ok'=>true,'msg'=>"Deleted: $fn"]);
            exit;

        // ── Delete an APK file ────────────────────────────
        case 'delete_apk':
            $fn = basename($_POST['fname'] ?? '');
            if (!$fn || strpos($fn,'..') !== false) { echo json_encode(['ok'=>false,'msg'=>'Invalid file']); exit; }
            $fp = $apkDir.$fn;
            if (!file_exists($fp)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
            unlink($fp);
            logAct($uid, $uname, 'APK_DELETE', "File: $fn");
            echo json_encode(['ok'=>true,'msg'=>"Deleted: $fn"]);
            exit;

        // ── Save app config (visible versions) ───────────
        case 'save_appconfig':
            $raw  = trim($_POST['versions'] ?? 'bgmi,gl,kr,tw');
            $all  = ['bgmi','gl','kr','tw'];
            $vers = array_values(array_filter(explode(',', $raw), fn($v)=>in_array(trim($v),$all)));
            setSetting('app_versions', implode(',', $vers));
            logAct($uid, $uname, 'APP_CONFIG_SAVE', 'versions='.implode(',', $vers));
            echo json_encode(['ok'=>true,'msg'=>'App Config saved.']);
            exit;

        // ── Save slot names / descriptions ───────────────
        case 'save_slot_names':
            for ($i = 1; $i <= 6; $i++) {
                $n = trim($_POST['slot_name_'.$i] ?? '');
                $d = trim($_POST['slot_desc_'.$i] ?? '');
                setSetting('slot_name_'.$i, $n);
                setSetting('slot_desc_'.$i, $d);
            }
            logAct($uid, $uname, 'SLOT_NAMES_SAVE', '');
            echo json_encode(['ok'=>true,'msg'=>'Slot names saved.']);
            exit;

        default:
            echo json_encode(['ok'=>false,'msg'=>'Unknown section']); exit;
    }
}

// ── FILE UPLOAD HANDLERS (regular multipart POST) ────────
$uploadMsg     = '';
$uploadMsgType = 'ok';
$activeTab     = 'lib';

// Detect POST that exceeded post_max_size (PHP wipes $_POST/$_FILES silently)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $cl  = (int)$_SERVER['CONTENT_LENGTH'];
    $max = ini_get('post_max_size');
    $uploadMsg = "Upload too large (".round($cl/1048576,1)."MB). Server limit is {$max}. "
               . "Raise upload_max_filesize / post_max_size (see .user.ini) or ask your host.";
    $uploadMsgType = 'err';
    $activeTab = 'apk';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST) && empty($_POST['ajax'])) {
    if (empty($_SESSION['uid'])) { header('Location: /index.php'); exit; }
    // NOTE: cast to int — some hosts return session level as string "1", and a
    // strict !== 1 would wrongly bounce the owner to the dashboard on every upload.
    if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

    $activeTab = $_POST['active_tab'] ?? 'lib';

    // Human-readable PHP upload error codes
    $upErr = function($code){
        return [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize. Raise it in cPanel MultiPHP INI Editor.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded — retry.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server has no temp folder (contact host).',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the temp file (contact host).',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ][$code] ?? ('Upload error code: '.$code);
    };

    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $uploadMsg = 'Session/CSRF token expired — reload the page and try again.'; $uploadMsgType = 'err';
    } else {
        $libDir = __DIR__.'/server/libs/';
        $apkDir = __DIR__.'/server/apks/';

        if (!empty($_FILES['lib_file']['name'])) {
            // ── LIB UPLOAD (supports 3 named slots) ───────
            $activeTab = 'lib';
            $f   = $_FILES['lib_file'];
            $nm  = basename($f['name']);
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            $allowed = ['so','zip','tar','gz','lib','dll','a','o','bin'];
            // Which slot (1,2,3)? Determines the stored filename so each slot
            // keeps its OWN fixed secure link that never changes on re-upload.
            $slot = (int)($_POST['lib_slot'] ?? 1);
            if ($slot < 1 || $slot > 6) $slot = 1;
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $uploadMsg = $upErr($f['error']); $uploadMsgType = 'err';
            } elseif (!in_array($ext, $allowed)) {
                $uploadMsg = 'File type not allowed. Allowed: '.implode(', ', $allowed); $uploadMsgType = 'err';
            } elseif ($f['size'] > 50*1024*1024) {
                $uploadMsg = 'File too large. Max 50MB.'; $uploadMsgType = 'err';
            } else {
                ensureWritableDir($libDir);
                if (!is_writable($libDir)) {
                    $uploadMsg = 'server/libs/ is not writable. In cPanel File Manager set its permissions to 0755 (or 0775).';
                    $uploadMsgType = 'err';
                } else {
                    // Fixed slot filename → stable secure link per slot.
                    // Keeps original extension so .so/.zip both work.
                    $slotName = 'libslot'.$slot.'.'.$ext;
                    // Remove any previous file for this slot (any extension) so the slot holds one file
                    foreach ($allowed as $ax) { $old = $libDir.'libslot'.$slot.'.'.$ax; if (is_file($old)) @unlink($old); }
                    if (move_uploaded_file($f['tmp_name'], $libDir.$slotName)) {
                        setSetting('lib_slot'.$slot.'_name', $slotName);
                        setSetting('lib_slot'.$slot.'_orig', $nm);
                        $newVer = trim($_POST['lib_new_ver'] ?? '');
                        if (preg_match('/^\d+(\.\d+){0,3}$/', $newVer)) {
                            setSetting('lib_slot'.$slot.'_version', $newVer);
                            // Slot 1 also drives the global lib_version / version.txt (primary lib)
                            if ($slot === 1) {
                                setSetting('lib_version', $newVer);
                                file_put_contents($libDir.'version.txt', $newVer);
                            }
                        }
                        logAct($_SESSION['uid'], $_SESSION['uname']??'', 'LIB_UPLOAD',
                            "Slot: $slot | File: $nm → $slotName | Ver: $newVer | Size: ".round($f['size']/1024)."KB");
                        $uploadMsg = "Library uploaded to Slot {$slot} ('{$nm}'). Version v{$newVer}.";
                    } else {
                        $uploadMsg = 'Upload failed. Run: chmod 755 server/libs/'; $uploadMsgType = 'err';
                    }
                }
            }

        } elseif (!empty($_FILES['apk_file']['name'])) {
            // ── APK UPLOAD ────────────────────────────────
            $activeTab = 'apk';
            $f   = $_FILES['apk_file'];
            $nm  = basename($f['name']);
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $uploadMsg = $upErr($f['error']); $uploadMsgType = 'err';
            } elseif ($ext !== 'apk') {
                $uploadMsg = 'Only .apk files allowed.'; $uploadMsgType = 'err';
            } elseif ($f['size'] > 200*1024*1024) {
                $uploadMsg = 'File too large. Max 200MB.'; $uploadMsgType = 'err';
            } else {
                ensureWritableDir($apkDir);
                if (!is_writable($apkDir)) {
                    $uploadMsg = 'server/apks/ is not writable. In cPanel File Manager set its permissions to 0755 (or 0775).';
                    $uploadMsgType = 'err';
                } elseif (move_uploaded_file($f['tmp_name'], $apkDir.$nm)) {
                    $newVer = trim($_POST['apk_new_ver'] ?? '');
                    if (preg_match('/^\d+(\.\d+){0,3}$/', $newVer)) {
                        setSetting('apk_version', $newVer);
                        file_put_contents($apkDir.'version.txt', $newVer);
                        // Always point the update URL at the APK we just uploaded,
                        // whatever its filename — so uploading any-named APK + a new
                        // version number makes older apps update to THIS file.
                        setSetting('apk_url', panelBase().'/server/apks/'.urlencode($nm));
                    }
                    logAct($_SESSION['uid'], $_SESSION['uname']??'', 'APK_UPLOAD',
                        "File: $nm | Ver: $newVer | Size: ".round($f['size']/1024/1024,1)."MB");
                    $uploadMsg = "APK '{$nm}' uploaded! Version set to v{$newVer}.";
                } else {
                    $uploadMsg = 'Upload failed. Run: chmod 755 server/apks/'; $uploadMsgType = 'err';
                }
            }
        }
    }
}

// ── PAGE SETUP ────────────────────────────────────────────
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Manage Rebranding';
$activeNav = 'rebranding';
require_once __DIR__.'/app/Views/Layout/header.php';

// Auto-create new DB settings (safe, uses INSERT IGNORE)
$initSettings = [
    'lib_version'             => '1.0',
    'lib_kill_below'          => '0.0',
    'lib_kill_enabled'        => '0',
    'apk_kill_enabled'        => '0',
    'apk_kill_version_target' => '',
    'apk_kill_message'        => 'This version has been expired. Please update your application.',
    'apk_kill_redirect'       => '',
    'lib_kill_redirect'       => '',
    'modlib_version'          => '1.0',
    'modlib_kill_enabled'     => '0',
    'modlib_kill_redirect'    => '',
    // 6-slot BGMI/Global system
    'app_versions'            => 'bgmi,gl,kr,tw',
    'slot_name_1' => '', 'slot_desc_1' => '',
    'slot_name_2' => '', 'slot_desc_2' => '',
    'slot_name_3' => '', 'slot_desc_3' => '',
    'slot_name_4' => '', 'slot_desc_4' => '',
    'slot_name_5' => '', 'slot_desc_5' => '',
    'slot_name_6' => '', 'slot_desc_6' => '',
];
foreach ($initSettings as $k => $v) {
    db()->q("INSERT IGNORE INTO panel_settings(skey,svalue) VALUES(?,?)", [$k, $v]);
}

// Load settings
$libVer     = getSetting('lib_version', '1.0');
$libKillOn  = getSetting('lib_kill_enabled', '0') === '1';
$libKillBw  = getSetting('lib_kill_below', '0.0');
$libKillRdr = getSetting('lib_kill_redirect', '');
$modVer     = getSetting('modlib_version', '1.0');
$modKillOn  = getSetting('modlib_kill_enabled', '0') === '1';
$modRdr     = getSetting('modlib_kill_redirect', '');
$apkVer     = getSetting('apk_version', '1.0.0');
$apkKillOn  = getSetting('apk_kill_enabled', '0') === '1';
$apkKillVer = getSetting('apk_kill_version_target', '');
$apkKillMsg = getSetting('apk_kill_message', 'This version has been expired. Please update your application.');
$apkKillRdr = getSetting('apk_kill_redirect', '');
$apkUrl     = getSetting('apk_url', '');
$panelBase  = panelBase();                       // owner-overridable base domain
$panelBaseSet = getSetting('panel_base_url', ''); // raw value for the input

function bumpVer(string $v): string {
    $p = explode('.', $v);
    $p[count($p)-1] = (int)end($p) + 1;
    return implode('.', $p);
}
$libVerNext = bumpVer($libVer);
$apkVerNext = bumpVer($apkVer);
$modVerNext = bumpVer($modVer);

// List uploaded libs
$libDir = __DIR__.'/server/libs/';
$libs   = [];
if (is_dir($libDir)) {
    foreach (scandir($libDir) as $f) {
        if (in_array($f, ['.','..', '.htaccess', 'version.txt'])) continue;
        $p = $libDir.$f;
        if (is_file($p)) $libs[] = ['name'=>$f,'size'=>filesize($p),'time'=>filemtime($p)];
    }
    usort($libs, fn($a,$b)=>$b['time']-$a['time']);
}

// List uploaded APKs
$apkDir = __DIR__.'/server/apks/';
$apks   = [];
if (is_dir($apkDir)) {
    foreach (scandir($apkDir) as $f) {
        if (in_array($f, ['.','..', '.htaccess', 'version.txt'])) continue;
        $p = $apkDir.$f;
        if (is_file($p)) $apks[] = ['name'=>$f,'size'=>filesize($p),'time'=>filemtime($p)];
    }
    usort($apks, fn($a,$b)=>$b['time']-$a['time']);
}

function fmtSz(int $b): string {
    if ($b >= 1048576) return round($b/1048576,1).'MB';
    if ($b >= 1024)    return round($b/1024,0).'KB';
    return $b.'B';
}
function iniBytes(string $v): int {
    $v = trim($v); if ($v==='') return 0;
    $n = (int)$v; $u = strtolower(substr($v,-1));
    if ($u==='g') $n *= 1024*1024*1024;
    elseif ($u==='m') $n *= 1024*1024;
    elseif ($u==='k') $n *= 1024;
    return $n;
}
$umf = ini_get('upload_max_filesize');
$pms = ini_get('post_max_size');
$limitBytes  = min(iniBytes($umf), iniBytes($pms));
$limitOkApk  = $limitBytes >= 200*1024*1024;   // want ≥200MB for APKs
$limitOkLib  = $limitBytes >= 50*1024*1024;    // want ≥50MB for libs

$libKillUrl = $panelBase.'/server/lib_version.php';
$apkKillUrl   = $panelBase.'/server/apk_kill.php';
$modKillUrl   = $panelBase.'/server/modlib_version.php';
$libVerTxtUrl = $panelBase.'/server/libs/version.txt';
$apkVerTxtUrl = $panelBase.'/server/apks/version.txt';
$secLibUrl    = $panelBase.'/secure/pack';   // NEW app → lib download
$secUnlockUrl = $panelBase.'/secure/auth';   // NEW app → lib unlock

// ── 6-slot BGMI/Global lib system ────────────────────────
$libDirR  = __DIR__.'/server/libs/';
$appconfigUrl = $panelBase.'/server/appconfig.php';
$appVersionsRaw = getSetting('app_versions', 'bgmi,gl,kr,tw');
$appVers = array_filter(array_map('trim', explode(',', $appVersionsRaw)));

$slotDefaults = [
    1 => ['defName'=>'Java Mode',  'defDesc'=>'BGMI Java menu',       'cat'=>'BGMI',   'color'=>'#4DDD6A'],
    2 => ['defName'=>'ImGui Mode', 'defDesc'=>'BGMI ImGui overlay',   'cat'=>'BGMI',   'color'=>'#4DDD6A'],
    3 => ['defName'=>'Auto Mode',  'defDesc'=>'BGMI Auto play',       'cat'=>'BGMI',   'color'=>'#4DDD6A'],
    4 => ['defName'=>'GL Java',    'defDesc'=>'Global Java menu',     'cat'=>'GLOBAL', 'color'=>'#FF7A18'],
    5 => ['defName'=>'GL ImGui',   'defDesc'=>'Global ImGui overlay', 'cat'=>'GLOBAL', 'color'=>'#FF7A18'],
    6 => ['defName'=>'GL Auto',    'defDesc'=>'Global Auto play',     'cat'=>'GLOBAL', 'color'=>'#FF7A18'],
];
$slotInfo = [];
for ($n = 1; $n <= 6; $n++) {
    $def   = $slotDefaults[$n];
    // Find actual uploaded file for this slot (any extension)
    $found = glob($libDirR.'libslot'.$n.'.*') ?: [];
    $file  = count($found) ? basename($found[0]) : '';
    $exists = (bool)$file;
    $slotInfo[$n] = [
        'file'    => $file,
        'exists'  => $exists,
        'size'    => $exists ? filesize($libDirR.$file) : 0,
        'time'    => $exists ? filemtime($libDirR.$file) : 0,
        'dlurl'   => $exists ? ($secLibUrl.'?f='.urlencode($file)) : '',
        'cat'     => $def['cat'],
        'color'   => $def['color'],
        'defName' => $def['defName'],
        'defDesc' => $def['defDesc'],
        'name'    => getSetting('slot_name_'.$n, '') ?: $def['defName'],
        'desc'    => getSetting('slot_desc_'.$n, '') ?: $def['defDesc'],
    ];
}

function renderSlotCard(int $n, array $s, string $libVerNext, string $sv): string {
    $color  = htmlspecialchars($s['color']);
    $cat    = htmlspecialchars($s['cat']);
    $name   = htmlspecialchars($s['name']);
    $file   = htmlspecialchars($s['file']);
    $dlurl  = htmlspecialchars(addslashes($s['dlurl']));
    $border = $s['exists'] ? 'rgba(45,212,80,.3)' : 'var(--border)';
    $bg     = $s['exists'] ? 'rgba(45,212,80,.04)' : 'var(--bg2)';
    $badge  = $s['exists']
        ? '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:8px;background:var(--acc-bg);border:1px solid var(--acc-20);font-size:9px;font-weight:700;color:var(--acc-tx);font-family:var(--mono);text-transform:uppercase">LOADED</span>'
        : '<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:8px;background:var(--rose-bg);border:1px solid rgba(207,51,70,.3);font-size:9px;font-weight:700;color:var(--rose-tx);font-family:var(--mono);text-transform:uppercase">EMPTY</span>';
    $szLine = $s['exists'] ? '&nbsp;·&nbsp;'.round($s['size']/1024).'KB&nbsp;·&nbsp;'.date('d M H:i',(int)$s['time']) : '';
    $delBtn = $s['exists'] ? "<button class=\"btn btn-red btn-xs\" style=\"margin-top:6px;width:100%\" onclick=\"rbDelLib('{$file}')\">Delete Slot {$n}</button>" : '';
    $vn = htmlspecialchars($libVerNext);
    ob_start(); ?>
    <div style="border:1px solid <?=$border?>;border-radius:12px;padding:14px;background:<?=$bg?>;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:28px;height:28px;border-radius:8px;background:<?=$color?>22;border:1px solid <?=$color?>55;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:<?=$color?>"><?=$n?></div>
          <div>
            <div style="font-size:11px;font-weight:800;color:var(--text)"><?=$cat?> · <?=$name?></div>
            <div style="font-size:10px;color:var(--muted);font-family:monospace"><?=$file ?: 'no file'?><?=$szLine?></div>
          </div>
        </div>
        <?=$badge?>
      </div>
      <?php if ($s['exists']): ?>
      <div style="font-size:10px;background:var(--bg3);border-radius:6px;padding:6px 10px;color:var(--acc);word-break:break-all;margin-bottom:8px"><?=htmlspecialchars($s['dlurl'])?><button style="float:right;font-size:9px;padding:1px 6px;border:1px solid var(--border);background:var(--bg2);border-radius:4px;cursor:pointer;color:var(--muted)" onclick="copyText('<?=$dlurl?>','Slot <?=$n?> link')">Copy</button></div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data">
        <?=csrfField()?>
        <input type="hidden" name="active_tab" value="lib">
        <input type="hidden" name="lib_slot" value="<?=$n?>">
        <div class="lib-slot-row">
          <label class="lib-slot-pick" onclick="document.getElementById('lfi<?=$n?>').click()">
            <span id="lfn<?=$n?>" class="lib-slot-pickname">Choose .zip / .so</span>
            <input type="file" id="lfi<?=$n?>" name="lib_file" accept=".so,.zip,.tar,.gz,.bin"
              onchange="document.getElementById('lfn<?=$n?>').textContent=this.files[0]?this.files[0].name:'Choose .zip / .so'"
              style="display:none">
          </label>
          <input type="text" class="fi fi-mono lib-slot-ver" name="lib_new_ver" placeholder="ver e.g. <?=$vn?>">
          <button type="submit" class="btn btn-acc lib-slot-btn">Upload</button>
        </div>
      </form>
      <?=$delBtn?>
    </div>
    <?php return ob_get_clean();
}

$csrf         = csrfToken();
$sv           = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

// small helper to render a copyable URL row
function urlRow(string $label, string $url, string $sv): string {
    $u = htmlspecialchars($url);
    $a = htmlspecialchars(addslashes($url));
    return '<div style="margin-bottom:10px">'
      .'<div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">'.htmlspecialchars($label).'</div>'
      .'<div class="code-box">'.$u
      .'<button class="cb-copy" onclick="copyText(\''.$a.'\',\''.htmlspecialchars($label).'\')">Copy</button>'
      .'</div></div>';
}
?>

<!-- ── Styles ─────────────────────────────────────────── -->
<style>
.rb-tab{font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;min-width:0;overflow:hidden;text-overflow:ellipsis}
.rb-tab.active.danger{color:#fff;border-color:transparent;background:linear-gradient(180deg,#f2687a,#d33a4c);box-shadow:0 2px 0 #a8273a,0 6px 14px rgba(217,67,90,.3),inset 0 1px 0 rgba(255,255,255,.3)}
.kill-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border-radius:7px;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.kill-on{background:var(--rose-bg);border:1px solid rgba(207,51,70,.3);color:var(--rose-tx)}
.kill-off{background:var(--acc-bg);border:1px solid var(--acc-20);color:var(--acc-tx)}
.kill-dot{display:none}
.code-box{position:relative;padding-right:74px;font-size:11.5px}
.code-box .cb-copy{position:absolute;top:8px;right:8px;padding:4px 9px;font-size:10.5px;font-weight:700;border-radius:8px;cursor:pointer;color:var(--ink-tx);background:rgba(255,255,255,.1);border:1px solid var(--ink-line)}
.code-box .cb-copy:hover{background:rgba(255,255,255,.18)}
.ver-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:8px;background:var(--acc-bg);border:1px solid var(--acc-20);font-size:12px;font-weight:800;color:var(--acc-tx)}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:7px;background:var(--ink);color:#6ee7b7;font-size:10.5px;font-weight:800;flex-shrink:0}
.kill-switch{display:flex;align-items:center;gap:14px;padding:14px 16px;background:var(--paper);border:1px solid var(--line);border-radius:var(--r2)}
.kill-switch.on{border-color:rgba(207,51,70,.3);background:var(--rose-bg)}
.ks-track{position:relative;width:56px;height:30px;border-radius:30px;background:var(--sunk);border:1px solid var(--line2);box-shadow:var(--well);cursor:pointer;flex-shrink:0;transition:all .25s}
.ks-track.on{background:linear-gradient(180deg,#d33a4c,#f2687a);border-color:#a8273a;box-shadow:inset 0 2px 4px rgba(120,20,30,.4)}
.ks-knob{position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:linear-gradient(180deg,#fff,#ece6da);transition:all .25s cubic-bezier(.34,1.4,.64,1);box-shadow:0 1px 2px rgba(0,0,0,.3),0 2px 5px rgba(0,0,0,.2)}
.ks-track.on .ks-knob{left:29px}
.ks-label{font-size:13px;font-weight:800;letter-spacing:.3px}
.ks-label .st-on{color:var(--rose-tx)}.ks-label .st-off{color:var(--muted)}
.lib-slot-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--r2);padding:14px;margin-bottom:12px}
.lib-slot-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;min-width:0}
.lib-slot-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:7px;background:var(--ink);color:#6ee7b7;font-size:10.5px;font-weight:800;letter-spacing:.5px;white-space:nowrap}
.lib-slot-status{font-size:11px;font-weight:700;min-width:0;overflow-wrap:anywhere}
.lib-slot-status.on{color:var(--acc-tx)}
.lib-slot-status.off{color:var(--muted)}
.lib-slot-file{font-size:11px;color:var(--muted2);margin-bottom:4px;overflow-wrap:anywhere;word-break:break-word}
.lib-slot-orig{font-family:var(--mono)}
.lib-slot-row{display:flex;gap:8px;align-items:stretch;flex-wrap:wrap}
.lib-slot-pick{flex:1 1 160px;min-width:0;display:flex;align-items:center;padding:9px 12px;background:var(--sunk);border:1px dashed var(--line3);border-radius:12px;box-shadow:var(--well);cursor:pointer;transition:.15s}
.lib-slot-pick:hover{border-color:var(--acc)}
.lib-slot-pickname{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lib-slot-ver{flex:0 0 130px;min-width:100px}
.lib-slot-btn{flex:0 0 auto;white-space:nowrap}
@media(max-width:520px){.lib-slot-ver{flex:1 1 100%}.lib-slot-btn{flex:1 1 100%}}
</style>

<?php if ($uploadMsg): ?>
  <div class="alert a-<?=$uploadMsgType?>" style="margin-bottom:16px"><?=htmlspecialchars($uploadMsg)?></div>
<?php endif; ?>

<!-- ── Server upload-limit health banner ─────────────────── -->
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;border-radius:var(--r);
     background:<?=$limitOkLib?'rgba(45,212,80,.06)':'rgba(255,77,109,.07)'?>;
     border:1px solid <?=$limitOkLib?'rgba(45,212,80,.25)':'rgba(255,77,109,.3)'?>">
  <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">Server Upload Limit</div>
  <div style="font-size:14px;font-weight:800;color:<?=$limitOkLib?'#2dd450':'#ff4d6d'?>">
    <?=fmtSz($limitBytes)?>
  </div>
  <div style="font-size:11px;color:var(--muted)">
    (upload_max_filesize=<?=htmlspecialchars($umf)?>, post_max_size=<?=htmlspecialchars($pms)?>)
  </div>
  <div style="flex:1"></div>
  <div style="font-size:11px;">
    Lib&nbsp;<?=$limitOkLib?'<span style="color:var(--acc-tx)">OK</span>':'<span style="color:var(--rose-tx)">LOW</span>'?>
    &nbsp;·&nbsp;
    APK&nbsp;<?=$limitOkApk?'<span style="color:var(--acc-tx)">OK</span>':'<span style="color:var(--rose-tx)">LOW</span>'?>
  </div>
</div>
<?php if (!$limitOkLib): ?>

<?php endif; ?>

<!-- ── Base domain (drives every link below) ──────────── -->
<div class="card" style="margin-bottom:16px">
  <div class="card-h"><span class="ct"><svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M480-71.87q-83.67 0-158.11-32.22-74.43-32.21-130.01-87.79-55.58-55.58-87.79-130.01Q71.87-396.33 71.87-480q0-84.43 32.22-158.49 32.21-74.05 87.79-129.63 55.58-55.58 130.01-87.79 74.44-32.22 158.11-32.22 84.43 0 158.49 32.22 74.05 32.21 129.63 87.79 55.58 55.58 87.79 129.63 32.22 74.06 32.22 158.49 0 83.67-32.22 158.11-32.21 74.43-87.79 130.01-55.58 55.58-129.63 87.79Q564.43-71.87 480-71.87Zm-.48-92.76q25.05-35.52 44.05-74.4 19-38.88 31-82.4H405.2q12 43.52 30.64 82.4t43.68 74.4Zm-102.8-16q-18.48-32.52-31.5-68.02-13.02-35.5-22.02-72.78H206.63q28.28 49.52 71.42 86.28 43.15 36.76 98.67 54.52Zm205.85 0q55.52-17.76 98.9-54.52 43.38-36.76 71.66-86.28H636.57q-9 37.28-22.27 72.78-13.26 35.5-31.73 68.02Zm-409.7-220.09H307.2q-3-20-4.5-39.26-1.5-19.26-1.5-40.02 0-21 1.5-40.14t4.5-39.14H172.87q-5 19.76-7.5 39.02t-2.5 40.26q0 20.76 2.5 40.14t7.5 39.14Zm214.33 0h185.37q3-20 4.26-39.26 1.26-19.26 1.26-40.02 0-21-1.26-40.14t-4.26-39.14H387.2q-3 20-4.5 39.14T381.2-480q0 20.76 1.5 40.02t4.5 39.26Zm265.37 0h134.08q5-19.76 7.5-39.14t2.5-40.14q0-21-2.5-40.26-2.5-19.26-7.5-39.02H652.57q2.76 20 4.14 39.14t1.38 40.14q0 20.76-1.38 40.02t-4.14 39.26Zm-16-238.08h116.56q-28.28-49.53-71.66-86.17-43.38-36.64-98.9-54.4 18.47 32.52 31.73 67.9 13.27 35.38 22.27 72.67Zm-231.37 0h149.37q-11.77-43.53-30.77-82.29-19-38.76-44.28-74.28-25.04 35.52-43.68 74.28T405.2-638.8Zm-198.57 0H323.2q9-37.29 22.02-72.67t31.5-67.9q-55.52 17.76-98.67 54.4-43.14 36.64-71.42 86.17Z"/></svg> Base Domain</span></div>
  <div class="card-b">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" class="fi fi-mono" id="baseUrlInp" style="flex:1;min-width:220px"
             value="<?=htmlspecialchars($panelBaseSet)?>"
             placeholder="https://sub.yourdomain.com">
      <button class="btn btn-acc" onclick="saveBase()">Save Domain</button>
    </div>
    <div style="margin-top:8px;font-size:11px;color:var(--muted)">Active base now: <code><?=htmlspecialchars($panelBase)?></code></div>
  </div>
</div>

<!-- ── Tab Bar ────────────────────────────────────────── -->
<div class="vtabs"><div class="rb-tabs" id="rbTabs">
  <button data-tab="lib"     class="rb-tab<?=$activeTab==='lib'?' active':''?>" onclick="rbTab('lib',this)">Lib Manager</button>
  <button data-tab="libkill" class="rb-tab<?=$activeTab==='libkill'?' active danger':''?>" onclick="rbTab('libkill',this)">Lib Killer</button>
  <button data-tab="apk"     class="rb-tab<?=$activeTab==='apk'?' active':''?>" onclick="rbTab('apk',this)">APK Manager</button>
  <button data-tab="apkkill" class="rb-tab<?=$activeTab==='apkkill'?' active danger':''?>" onclick="rbTab('apkkill',this)">APK Killer</button>
  <button data-tab="modkill"   class="rb-tab<?=$activeTab==='modkill'  ?' active danger':''?>" onclick="rbTab('modkill',this)">Mod Lib Kill</button>
  <button data-tab="appconfig" class="rb-tab<?=$activeTab==='appconfig'?' active':''?>"        onclick="rbTab('appconfig',this)">App Config</button>
</div>

</div><div class="vtabs-body">
<!-- ══════════════════════════════════════════════════════
     TAB 1 — LIB MANAGER
════════════════════════════════════════════════════════ -->
<div id="rb-lib" class="rb-pane<?=$activeTab==='lib'?' active':''?>">
  <div class="g2" style="align-items:start">

    <!-- Upload card -->
    <div class="card">
      <div class="card-h">
        <span class="ct">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M242.87-151.87q-37.78 0-64.39-26.61t-26.61-64.39v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5h474.26v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5q0 37.78-26.61 64.39t-64.39 26.61H242.87ZM434.5-633.56 365-564.07q-13.43 13.68-32.09 13.42-18.65-.26-32.32-13.94-12.92-13.67-13.3-31.94-.38-18.27 13.3-31.95l147.34-147.59q6.72-6.71 14.92-9.81 8.19-3.1 17.15-3.1 8.96 0 17.15 3.1 8.2 3.1 14.92 9.81l147.58 147.59q13.44 13.44 13.06 31.83-.38 18.39-13.06 31.82-13.67 13.68-32.32 14.06-18.66.38-32.33-13.3l-69.5-69.49v265.19q0 19.15-13.17 32.33-13.18 13.17-32.33 13.17t-32.33-13.17q-13.17-13.18-13.17-32.33v-265.19Z"/></svg>
          Upload Library
        </span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:11px;color:var(--muted)">Current version</span>
          <span class="ver-badge">v<?=htmlspecialchars($libVer)?></span>
          <span class="owner-only">Owner Only</span>
        </div>
      </div>
      <div class="card-b">

        <!-- BGMI / GLOBAL tab toggle -->
        <div style="display:flex;gap:0;margin-bottom:16px;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
          <button id="slotTabBgmi" onclick="switchSlotTab('bgmi')"
            style="flex:1;padding:10px;font-size:12px;font-weight:800;letter-spacing:.06em;background:var(--acc-bg);color:var(--acc-tx);border:none;cursor:pointer;transition:all .2s">BGMI (Slots 1–3)</button>
          <button id="slotTabGlobal" onclick="switchSlotTab('global')"
            style="flex:1;padding:10px;font-size:12px;font-weight:800;letter-spacing:.06em;background:var(--bg2);color:var(--muted);border:none;cursor:pointer;transition:all .2s">GLOBAL / TW / KR (Slots 4–6)</button>
        </div>

        <div id="slotPaneBgmi">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
            <?php foreach ([1,2,3] as $n): echo renderSlotCard($n, $slotInfo[$n], $libVerNext, $sv); endforeach; ?>
          </div>
        </div>
        <div id="slotPaneGlobal" style="display:none">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
            <?php foreach ([4,5,6] as $n): echo renderSlotCard($n, $slotInfo[$n], $libVerNext, $sv); endforeach; ?>
          </div>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

        <!-- Manual version bump (without uploading file) -->
        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Force Primary Version (no file upload)</div>
        <div style="display:flex;gap:8px" id="setLibVerForm">
          <input type="text" class="fi" id="setLibVerInp" value="<?=htmlspecialchars($libVerNext)?>" placeholder="<?=htmlspecialchars($libVerNext)?>" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="setLibVer()">Set Version</button>
        </div>
      </div>
    </div>

    <!-- Endpoints & Links -->
    <div class="card">
      <div class="card-h"><span class="ct"><?= phIcon('link') ?> Lib Endpoints &amp; Links</span></div>
      <div class="card-b">
        <?= urlRow('Lib version.txt', $libVerTxtUrl, $sv) ?>
        <?= urlRow('Secure lib download endpoint', $secLibUrl, $sv) ?>
        <?= urlRow('Lib unlock endpoint', $secUnlockUrl, $sv) ?>
        <?= urlRow('Lib kill-check endpoint', $libKillUrl, $sv) ?>
      </div>
    </div>

    <!-- Uploaded libs list -->
    <div class="card">
      <div class="card-h">
        <span class="ct"><?= phIcon('package') ?> Uploaded Libraries (<?=count($libs)?>)</span>
        <span style="font-size:10px;color:var(--muted);"><?=$panelBase?>/server/libs/</span>
      </div>
      <?php if (empty($libs)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M242.87-71.87q-37.78 0-64.39-26.61t-26.61-64.39v-634.26q0-37.78 26.61-64.39t64.39-26.61H525.8q18.16 0 34.69 6.84 16.53 6.83 29.21 19.51L781.78-669.7q12.68 12.68 19.51 29.21 6.84 16.53 6.84 34.69v442.93q0 37.78-26.61 64.39t-64.39 26.61H242.87Zm274.26-570.76q0 19.15 13.17 32.33 13.18 13.17 32.33 13.17h154.5l-200-200v154.5Z"/></svg>
          <div style="font-size:12px">No libraries uploaded yet</div>
        </div>
      <?php else: ?>
        <div class="tw">
          <table>
            <thead><tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>URL</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($libs as $lib): ?>
              <tr>
                <td><code style="font-size:11px"><?=htmlspecialchars($lib['name'])?></code></td>
                <td style="font-size:11px"><?=fmtSz((int)$lib['size'])?></td>
                <td style="font-size:10px;color:var(--muted)"><?=date('d M H:i',(int)$lib['time'])?></td>
                <td>
                  <button class="btn btn-ghost btn-xs"
                    onclick="copyText('<?=$secLibUrl?>?f=<?=urlencode($lib['name'])?>','Secure URL')">Copy Secure URL</button>
                </td>
                <td>
                  <button class="btn btn-red btn-xs"
                    onclick="rbDelLib('<?=htmlspecialchars(addslashes($lib['name']))?>')">Delete</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /g-left -->
</div>

<!-- ══════════════════════════════════════════════════════
     TAB 2 — LIB KILLER
════════════════════════════════════════════════════════ -->
<div id="rb-libkill" class="rb-pane<?=$activeTab==='libkill'?' active':''?>">
  <div class="g2" style="align-items:start">

    <!-- Status card -->
    <div class="card" style="border-color:<?=$libKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M111.63-115.93q-12.67 0-22.89-6.29T72.85-138.8q-5.72-10.2-6.22-22.16t6.22-23.34L440.22-820q6.71-11.43 17.55-17.03 10.84-5.6 22.25-5.6t22.23 5.6q10.82 5.6 17.53 17.03l367.37 635.7q6.72 11.38 6.22 23.34-.5 11.96-6.22 22.16-5.72 10.19-15.91 16.53-10.2 6.34-22.87 6.34H111.63ZM480-240.72q17.24 0 29.22-11.98 11.98-11.97 11.98-29.21 0-17.24-11.98-29.1-11.98-11.86-29.22-11.86t-29.22 11.86q-11.98 11.86-11.98 29.1 0 17.24 11.98 29.21 11.98 11.98 29.22 11.98ZM480-360q17 0 28.5-11.5T520-400v-117.37q0-17-11.5-28.5t-28.5-11.5q-17 0-28.5 11.5t-11.5 28.5V-400q0 17 11.5 28.5T480-360Z"/></svg>
          Lib Killer Status
        </span>
        <span class="kill-badge <?=$libKillOn?'kill-on':'kill-off'?>">
          <span class="kill-dot"></span>
          <?=$libKillOn?'ACTIVE — Killing v&lt; '.$libKillBw:'DISABLED'?>
        </span>
      </div>
      <div class="card-b">
        <?php if ($libKillOn): ?>
        <?php endif; ?>

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Kill check URL</div>
          <div class="code-box">
            <?=htmlspecialchars($libKillUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($libKillUrl)?>','Kill URL')">Copy</button>
          </div>
          
        </div>

        <!-- Set kill version -->
        <div class="fg">
          <label class="fl">Kill All Libs Below Version</label>
          <div style="display:flex;gap:8px">
            <input type="text" class="fi" id="libKillBwInp"
                   value="<?=htmlspecialchars($libKillBw)?>"
                   placeholder="e.g. <?=htmlspecialchars($libVerNext)?>"
                   style="flex:1">
            <button class="btn btn-red" onclick="setLibKiller()" style="white-space:nowrap">
              Activate Kill
            </button>
          </div>
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">
            Example: set <code>4.2</code> → all libs compiled with version <code>&lt; 4.2</code> will crash.
            Current lib version: <code>v<?=htmlspecialchars($libVer)?></code>
          </div>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — killed lib opens this, e.g. Telegram)</span></label>
          <input type="text" class="fi" id="libKillRdrInp"
                 value="<?=htmlspecialchars($libKillRdr)?>"
                 placeholder="https://t.me/YourChannel">

        </div>

        <?php if ($libKillOn): ?>
        <button class="btn btn-ghost btn-full" onclick="resetLibKiller()" style="margin-top:8px">
          Disable Lib Killer
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- How it works -->
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB 3 — APK MANAGER
════════════════════════════════════════════════════════ -->
<div id="rb-apk" class="rb-pane<?=$activeTab==='apk'?' active':''?>">
  <div class="g2" style="align-items:start">

    <!-- Upload card -->
    <div class="card">
      <div class="card-h">
        <span class="ct">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M242.87-151.87q-37.78 0-64.39-26.61t-26.61-64.39v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5h474.26v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5q0 37.78-26.61 64.39t-64.39 26.61H242.87ZM434.5-633.56 365-564.07q-13.43 13.68-32.09 13.42-18.65-.26-32.32-13.94-12.92-13.67-13.3-31.94-.38-18.27 13.3-31.95l147.34-147.59q6.72-6.71 14.92-9.81 8.19-3.1 17.15-3.1 8.96 0 17.15 3.1 8.2 3.1 14.92 9.81l147.58 147.59q13.44 13.44 13.06 31.83-.38 18.39-13.06 31.82-13.67 13.68-32.32 14.06-18.66.38-32.33-13.3l-69.5-69.49v265.19q0 19.15-13.17 32.33-13.18 13.17-32.33 13.17t-32.33-13.17q-13.17-13.18-13.17-32.33v-265.19Z"/></svg>
          Upload APK
        </span>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:11px;color:var(--muted)">Current APK version</span>
          <span class="ver-badge">v<?=htmlspecialchars($apkVer)?></span>
          <span class="owner-only">Owner Only</span>
        </div>
      </div>
      <div class="card-b">
        <form method="POST" enctype="multipart/form-data">
          <?=csrfField()?>
          <input type="hidden" name="active_tab" value="apk">
          <div class="fg">
            <label class="fl">APK File <span style="color:var(--muted)">.apk only — Max 200MB</span></label>
            <div class="logo-drop" onclick="document.getElementById('apkFileInp').click()" id="apkDrop">
              <div id="apkPrevWrap" style="margin-bottom:10px;display:none">
                <div id="apkFileName" style="font-size:13px;color:var(--acc);font-weight:700"></div>
                <div id="apkFileSize" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
              </div>
              <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M242.87-151.87q-37.78 0-64.39-26.61t-26.61-64.39v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5h474.26v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5q0 37.78-26.61 64.39t-64.39 26.61H242.87ZM434.5-633.56 365-564.07q-13.43 13.68-32.09 13.42-18.65-.26-32.32-13.94-12.92-13.67-13.3-31.94-.38-18.27 13.3-31.95l147.34-147.59q6.72-6.71 14.92-9.81 8.19-3.1 17.15-3.1 8.96 0 17.15 3.1 8.2 3.1 14.92 9.81l147.58 147.59q13.44 13.44 13.06 31.83-.38 18.39-13.06 31.82-13.67 13.68-32.32 14.06-18.66.38-32.33-13.3l-69.5-69.49v265.19q0 19.15-13.17 32.33-13.18 13.17-32.33 13.17t-32.33-13.17q-13.17-13.18-13.17-32.33v-265.19Z"/></svg>

              <input type="file" id="apkFileInp" name="apk_file" accept=".apk" onchange="prevApk(this)" style="display:none">
            </div>
          </div>
          <div class="fg">
            <label class="fl">New Version <span style="color:var(--muted)">(saved to version.txt + DB)</span></label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="text" class="fi" name="apk_new_ver" id="apkNewVer"
                     value="<?=htmlspecialchars($apkVerNext)?>"
                     placeholder="<?=htmlspecialchars($apkVerNext)?>"
                     style="flex:1">
              <button type="button" class="btn btn-ghost btn-sm"
                      onclick="document.getElementById('apkNewVer').value='<?=htmlspecialchars($apkVerNext)?>'">
                Auto +1
              </button>
            </div>
            <div style="margin-top:6px;font-size:11px;color:var(--muted)">
              Current: <code>v<?=htmlspecialchars($apkVer)?></code> → Suggested next: <code>v<?=htmlspecialchars($apkVerNext)?></code>
            </div>
          </div>
          <button type="submit" class="btn btn-acc btn-full" style="margin-top:4px">
            <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M242.87-151.87q-37.78 0-64.39-26.61t-26.61-64.39v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5h474.26v-74.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17t32.33 13.17q13.17 13.18 13.17 32.33v74.5q0 37.78-26.61 64.39t-64.39 26.61H242.87ZM434.5-633.56 365-564.07q-13.43 13.68-32.09 13.42-18.65-.26-32.32-13.94-12.92-13.67-13.3-31.94-.38-18.27 13.3-31.95l147.34-147.59q6.72-6.71 14.92-9.81 8.19-3.1 17.15-3.1 8.96 0 17.15 3.1 8.2 3.1 14.92 9.81l147.58 147.59q13.44 13.44 13.06 31.83-.38 18.39-13.06 31.82-13.67 13.68-32.32 14.06-18.66.38-32.33-13.3l-69.5-69.49v265.19q0 19.15-13.17 32.33-13.18 13.17-32.33 13.17t-32.33-13.17q-13.17-13.18-13.17-32.33v-265.19Z"/></svg>
            UPLOAD APK
          </button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

        <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Force Version (no upload)</div>
        <div style="display:flex;gap:8px">
          <input type="text" class="fi" id="setApkVerInp" value="<?=htmlspecialchars($apkVerNext)?>" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="setApkVer()">Set Version</button>
        </div>

        <?php if ($apkUrl): ?>
        <div style="margin-top:14px">
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Current APK Download URL</div>
          <div class="code-box" style="font-size:10px">
            <?=htmlspecialchars($apkUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($apkUrl)?>','APK URL')">Copy</button>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- APK endpoints & links -->
    <div class="card">
      <div class="card-h"><span class="ct"><?= phIcon('link') ?> APK Endpoints &amp; Links</span></div>
      <div class="card-b">
        <?= urlRow('APK version.txt', $apkVerTxtUrl, $sv) ?>
        <?= urlRow('APK kill / update-check endpoint', $apkKillUrl, $sv) ?>
      </div>
    </div>

    <!-- APK list -->
    <div class="card">
      <div class="card-h">
        <span class="ct"><?= phIcon('package') ?> Uploaded APKs (<?=count($apks)?>)</span>
        <span style="font-size:10px;color:var(--muted);"><?=$panelBase?>/server/apks/</span>
      </div>
      <?php if (empty($apks)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted)">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" style="width:32px;height:32px;opacity:.3;margin-bottom:8px"><path d="M242.87-71.87q-37.78 0-64.39-26.61t-26.61-64.39v-634.26q0-37.78 26.61-64.39t64.39-26.61H525.8q18.16 0 34.69 6.84 16.53 6.83 29.21 19.51L781.78-669.7q12.68 12.68 19.51 29.21 6.84 16.53 6.84 34.69v442.93q0 37.78-26.61 64.39t-64.39 26.61H242.87Zm274.26-570.76q0 19.15 13.17 32.33 13.18 13.17 32.33 13.17h154.5l-200-200v154.5Z"/></svg>
          <div style="font-size:12px">No APKs uploaded yet</div>
        </div>
      <?php else: ?>
        <div class="tw">
          <table>
            <thead><tr><th>Filename</th><th>Size</th><th>Uploaded</th><th>Download URL</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($apks as $apk): ?>
              <tr>
                <td><code style="font-size:11px"><?=htmlspecialchars($apk['name'])?></code></td>
                <td style="font-size:11px"><?=fmtSz((int)$apk['size'])?></td>
                <td style="font-size:10px;color:var(--muted)"><?=date('d M H:i',(int)$apk['time'])?></td>
                <td>
                  <button class="btn btn-ghost btn-xs"
                    onclick="copyText('<?=$panelBase?>/server/apks/<?=urlencode($apk['name'])?>','URL')">Copy URL</button>
                </td>
                <td>
                  <button class="btn btn-red btn-xs"
                    onclick="rbDelApk('<?=htmlspecialchars(addslashes($apk['name']))?>')">Delete</button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB 4 — APK KILLER
════════════════════════════════════════════════════════ -->
<div id="rb-apkkill" class="rb-pane<?=$activeTab==='apkkill'?' active':''?>">
  <div class="g2" style="align-items:start">

    <!-- APK Kill control -->
    <div class="card" style="border-color:<?=$apkKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M111.63-115.93q-12.67 0-22.89-6.29T72.85-138.8q-5.72-10.2-6.22-22.16t6.22-23.34L440.22-820q6.71-11.43 17.55-17.03 10.84-5.6 22.25-5.6t22.23 5.6q10.82 5.6 17.53 17.03l367.37 635.7q6.72 11.38 6.22 23.34-.5 11.96-6.22 22.16-5.72 10.19-15.91 16.53-10.2 6.34-22.87 6.34H111.63ZM480-240.72q17.24 0 29.22-11.98 11.98-11.97 11.98-29.21 0-17.24-11.98-29.1-11.98-11.86-29.22-11.86t-29.22 11.86q-11.98 11.86-11.98 29.1 0 17.24 11.98 29.21 11.98 11.98 29.22 11.98ZM480-360q17 0 28.5-11.5T520-400v-117.37q0-17-11.5-28.5t-28.5-11.5q-17 0-28.5 11.5t-11.5 28.5V-400q0 17 11.5 28.5T480-360Z"/></svg>
          APK Killer Control
        </span>
        <span class="kill-badge <?=$apkKillOn?'kill-on':'kill-off'?>">
          <span class="kill-dot"></span>
          <?=$apkKillOn?'ACTIVE':'DISABLED'?>
        </span>
      </div>
      <div class="card-b">
        <?php if ($apkKillOn): ?>
        <?php endif; ?>

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Kill check URL</div>
          <div class="code-box">
            <?=htmlspecialchars($apkKillUrl)?>
            <button class="cb-copy" onclick="copyText('<?=htmlspecialchars($apkKillUrl)?>','APK Kill URL')">Copy</button>
          </div>
          
        </div>

        <div class="fg">
          <label class="fl">Enable APK Killer</label>
          <div class="kill-switch<?=$apkKillOn?' on':''?>" id="apkKillSwitch">
            <div class="ks-track<?=$apkKillOn?' on':''?>" id="apkKillTrack" onclick="toggleApkKillBtn()">
              <div class="ks-knob"></div>
            </div>
            <div class="ks-label" id="apkKillLbl">
              <?php if($apkKillOn): ?><span class="st-on">KILLER ACTIVE</span>
              <?php else: ?><span class="st-off">KILLER OFF</span><?php endif; ?>
            </div>
          </div>
          <input type="hidden" id="apkKillState" value="<?=$apkKillOn?'1':'0'?>">
        </div>

        <div class="fg">
          <label class="fl">Target APK Version(s) to Kill
            <span style="color:var(--muted)">(comma-separated or * for all)</span>
          </label>
          <input type="text" class="fi" id="apkKillVerInp"
                 value="<?=htmlspecialchars($apkKillVer)?>"
                 placeholder="e.g. 1.4,1.3  or  *  for all">
        </div>

        <div class="fg">
          <label class="fl">Kill Popup Message <span style="color:var(--muted)">(shown in APK when killed)</span></label>
          <textarea class="fi" id="apkKillMsgInp" rows="3" placeholder="Your APK version has been expired..."><?=htmlspecialchars($apkKillMsg)?></textarea>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — killed users open this link)</span></label>
          <input type="text" class="fi" id="apkKillRdrInp"
                 value="<?=htmlspecialchars($apkKillRdr)?>"
                 placeholder="e.g. https://t.me/YourChannel  or  https://arkcheat...shop/update">
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">
            When set, the APK opens this URL (Telegram, update page, etc.) after showing the kill popup.
          </div>
        </div>

        <button class="btn btn-red btn-full" onclick="saveApkKiller()" style="margin-top:4px">
          SAVE APK KILLER SETTINGS
        </button>
      </div>
    </div>

    <!-- APK Kill how-it-works -->
    </div>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB 5 — MOD LIB KILL (version-only, no download)
════════════════════════════════════════════════════════ -->
<div id="rb-modkill" class="rb-pane<?=$activeTab==='modkill'?' active':''?>">
  <div class="g2" style="align-items:start">
    <div class="card" style="border-color:<?=$modKillOn?'rgba(255,77,109,.3)':'var(--border)'?>">
      <div class="card-h">
        <span class="ct">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M111.63-115.93q-12.67 0-22.89-6.29T72.85-138.8q-5.72-10.2-6.22-22.16t6.22-23.34L440.22-820q6.71-11.43 17.55-17.03 10.84-5.6 22.25-5.6t22.23 5.6q10.82 5.6 17.53 17.03l367.37 635.7q6.72 11.38 6.22 23.34-.5 11.96-6.22 22.16-5.72 10.19-15.91 16.53-10.2 6.34-22.87 6.34H111.63ZM480-240.72q17.24 0 29.22-11.98 11.98-11.97 11.98-29.21 0-17.24-11.98-29.1-11.98-11.86-29.22-11.86t-29.22 11.86q-11.98 11.86-11.98 29.1 0 17.24 11.98 29.21 11.98 11.98 29.22 11.98ZM480-360q17 0 28.5-11.5T520-400v-117.37q0-17-11.5-28.5t-28.5-11.5q-17 0-28.5 11.5t-11.5 28.5V-400q0 17 11.5 28.5T480-360Z"/></svg>
          Mod Lib Kill
        </span>
        <span class="kill-badge <?=$modKillOn?'kill-on':'kill-off'?>"><span class="kill-dot"></span><?=$modKillOn?'KILL-ALL ON':'VERSION MODE'?></span>
      </div>
      <div class="card-b">

        <div style="margin-bottom:16px">
          <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Mod version URL</div>
          <div class="code-box"><?=htmlspecialchars($modKillUrl)?><button class="cb-copy" onclick="copyText('<?=htmlspecialchars($modKillUrl)?>','Mod URL')">Copy</button></div>
          
        </div>

        <div class="fg">
          <label class="fl">Current Mod Version <span style="color:var(--muted)">(anything older than this crashes)</span></label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" class="fi" id="modVerInp" value="<?=htmlspecialchars($modVer)?>" placeholder="e.g. <?=htmlspecialchars($modVerNext)?>" style="flex:1">
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('modVerInp').value='<?=htmlspecialchars($modVerNext)?>'">Auto +1</button>
          </div>
          <div style="margin-top:6px;font-size:11px;color:var(--muted)">Current: <code>v<?=htmlspecialchars($modVer)?></code> → Bump to <code>v<?=htmlspecialchars($modVerNext)?></code> to kill all older mod libs.</div>
        </div>

        <div class="fg">
          <label class="fl">Redirect URL on Kill <span style="color:var(--muted)">(optional — Telegram etc.)</span></label>
          <input type="text" class="fi" id="modRdrInp" value="<?=htmlspecialchars($modRdr)?>" placeholder="https://t.me/YourChannel">
        </div>

        <div class="fg">
          <label class="fl">Kill ALL mod libs now <span style="color:var(--muted)">(ignores version — every mod lib crashes)</span></label>
          <div class="kill-switch<?=$modKillOn?' on':''?>" id="modKillSwitch">
            <div class="ks-track<?=$modKillOn?' on':''?>" id="modKillTrack" onclick="toggleModKill()"><div class="ks-knob"></div></div>
            <div class="ks-label" id="modKillLbl">
              <?php if($modKillOn): ?><span class="st-on">KILL-ALL ACTIVE</span><?php else: ?><span class="st-off">VERSION MODE</span><?php endif; ?>
            </div>
          </div>
          <input type="hidden" id="modKillState" value="<?=$modKillOn?'1':'0'?>">
        </div>

        <button class="btn btn-red btn-full" onclick="saveModKill()">SAVE MOD LIB KILL</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     TAB 6 — APP CONFIG
════════════════════════════════════════════════════════ -->
<div id="rb-appconfig" class="rb-pane<?=$activeTab==='appconfig'?' active':''?>">
  <div class="g2" style="align-items:start">

    <!-- Game versions -->
    <div class="card">
      <div class="card-h">
        <span class="ct"><?= phIcon('device-mobile') ?> App Config — Visible Game Versions</span>
        <span class="owner-only">Owner Only</span>
      </div>
      <div class="card-b">
        <?php
        $vDefs = [
            'bgmi' => ['label'=>'BGMI',   'color'=>'#4DDD6A', 'pkg'=>'com.pubg.imobile'],
            'gl'   => ['label'=>'Global', 'color'=>'#FF7A18', 'pkg'=>'com.tencent.ig'],
            'kr'   => ['label'=>'Korea',  'color'=>'#4DC8FF', 'pkg'=>'com.pubg.krmobile'],
            'tw'   => ['label'=>'Taiwan', 'color'=>'#FF4D8B', 'pkg'=>'com.rekoo.pubgm'],
        ];
        foreach ($vDefs as $key => $def):
            $chk = in_array($key, $appVers);
        ?>
        <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;cursor:pointer;margin-bottom:8px;
                      background:<?=$chk?'rgba(77,221,106,.05)':'rgba(255,77,109,.04)'?>;
                      border:1px solid <?=$chk?'rgba(77,221,106,.2)':'rgba(255,77,109,.15)'?>">
          <input type="checkbox" id="vc_<?=$key?>" <?=$chk?'checked':''?>
                 style="width:16px;height:16px;accent-color:<?=$def['color']?>;cursor:pointer">
          <div style="flex:1">
            <div style="font-size:12px;font-weight:700;color:var(--text)"><?=htmlspecialchars($def['label'])?></div>
            <div style="font-size:10px;color:var(--muted)"><?=htmlspecialchars($def['pkg'])?></div>
          </div>
          <span style="width:8px;height:8px;border-radius:50%;background:<?=$chk?$def['color']:'#555'?>;display:inline-block"></span>
        </label>
        <?php endforeach; ?>
        <button class="btn btn-acc btn-full" style="margin-top:14px" onclick="saveAppConfig()">SAVE VISIBLE VERSIONS</button>
        <div style="margin-top:16px"><?= urlRow('App Config endpoint (app fetches this)', $appconfigUrl, $sv) ?></div>
      </div>
    </div>

    <!-- Slot names editor -->
    <div class="card">
      <div class="card-h"><span class="ct"><?= phIcon('note-pencil') ?> Slot Names &amp; Descriptions (shown in app)</span></div>
      <div class="card-b">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
          <?php foreach ($slotInfo as $n => $s): ?>
          <div style="border:1px solid var(--border);border-radius:10px;padding:12px;background:var(--bg2)">
            <div style="font-size:10px;font-weight:800;color:<?=htmlspecialchars($s['color'])?>;margin-bottom:8px;text-transform:uppercase;font-family:var(--mono)">
              <?=htmlspecialchars($s['cat'])?> — Slot <?=$n?>
            </div>
            <input type="text" class="fi" id="slot_name_<?=$n?>" value="<?=htmlspecialchars(getSetting('slot_name_'.$n,''))?>"
              placeholder="<?=htmlspecialchars($s['defName'])?>" style="margin-bottom:6px;font-size:12px">
            <input type="text" class="fi" id="slot_desc_<?=$n?>" value="<?=htmlspecialchars(getSetting('slot_desc_'.$n,''))?>"
              placeholder="<?=htmlspecialchars($s['defDesc'])?>" style="font-size:11px">
          </div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-acc btn-sm" style="margin-top:14px" onclick="saveSlotNames()">Save Slot Names</button>
      </div>
    </div>

  </div>
</div>

</div></div>
<!-- ── JS ─────────────────────────────────────────────── -->
<script>
const RB_CSRF = '<?=htmlspecialchars($csrf,ENT_QUOTES)?>';

// ── Tab switching (persists across reloads via URL hash) ──
function rbTab(name, btn) {
  document.querySelectorAll('.rb-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.rb-tab').forEach(b => b.classList.remove('active','danger'));
  const pane = document.getElementById('rb-'+name);
  if (pane) pane.classList.add('active');
  const isDanger = name === 'libkill' || name === 'apkkill' || name === 'modkill';
  if (!btn) btn = document.querySelector('.rb-tab[data-tab="'+name+'"]');
  if (btn) { btn.classList.add('active'); if (isDanger) btn.classList.add('danger'); }
  location.hash = name;
}

// On load, restore the tab from the URL hash (survives location.reload())
document.addEventListener('DOMContentLoaded', function () {
  const h = (location.hash || '').replace('#','');
  if (h && document.getElementById('rb-'+h)) rbTab(h, null);
});

// ── File preview (legacy single-upload; slots use inline handlers) ──
function prevLib(inp) {
  if (!inp.files || !inp.files[0]) return;
  const f = inp.files[0];
  const w = document.getElementById('libPrevWrap');
  const n = document.getElementById('libFileName');
  const s = document.getElementById('libFileSize');
  if (w) w.style.display = 'block';
  if (n) n.textContent = '' + f.name;
  if (s) s.textContent = (f.size/1024).toFixed(0) + ' KB';
}
function prevApk(inp) {
  if (!inp.files || !inp.files[0]) return;
  const f = inp.files[0];
  document.getElementById('apkPrevWrap').style.display = 'block';
  document.getElementById('apkFileName').textContent   = '' + f.name;
  document.getElementById('apkFileSize').textContent   = (f.size/1024/1024).toFixed(1) + ' MB';
}

// ── Ajax helper ────────────────────────────────────────
async function rbAjax(section, extra) {
  const body = new URLSearchParams({ ajax:'1', csrf:RB_CSRF, section, ...extra });
  const r    = await fetch('manage_rebranding.php', { method:'POST', body });
  return await r.json();
}

// ── Set base domain ────────────────────────────────────
async function saveBase() {
  const b = document.getElementById('baseUrlInp').value.trim();
  const d = await rbAjax('set_base', { base_url: b });
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) setTimeout(()=>location.reload(), 900);
}

// ── Set lib version (no upload) ────────────────────────
async function setLibVer() {
  const v = document.getElementById('setLibVerInp').value.trim();
  if (!v) return;
  const d = await rbAjax('set_lib_version', { new_lib_version: v });
  toast(d.msg, d.ok ? 'ok' : 'err');
}

// ── Set APK version (no upload) ───────────────────────
async function setApkVer() {
  const v = document.getElementById('setApkVerInp').value.trim();
  if (!v) return;
  const d = await rbAjax('set_apk_version', { new_apk_version: v });
  toast(d.msg, d.ok ? 'ok' : 'err');
}

// ── Lib killer ─────────────────────────────────────────
async function setLibKiller() {
  const kb = document.getElementById('libKillBwInp').value.trim();
  const kr = document.getElementById('libKillRdrInp')?.value.trim() || '';
  if (!kb) { toast('Enter a version first','err'); return; }
  if (!confirm(`Kill ALL libs below v${kb}?\nThis will crash ALL existing users on older builds!`)) return;
  const d = await rbAjax('lib_killer', { kill_below: kb, kill_redirect: kr });
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) { location.hash = 'libkill'; setTimeout(()=>location.reload(), 1200); }
}
async function resetLibKiller() {
  if (!confirm('Disable Lib Killer? Old libs will work again.')) return;
  const d = await rbAjax('lib_killer_reset', {});
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) { location.hash = 'libkill'; setTimeout(()=>location.reload(), 1200); }
}

// ── APK killer ─────────────────────────────────────────
function toggleApkKillBtn() {
  const inp   = document.getElementById('apkKillState');
  const track = document.getElementById('apkKillTrack');
  const wrap  = document.getElementById('apkKillSwitch');
  const lbl   = document.getElementById('apkKillLbl');
  const isOn  = inp.value === '1';
  inp.value = isOn ? '0' : '1';
  track.classList.toggle('on', !isOn);
  wrap.classList.toggle('on', !isOn);
  lbl.innerHTML = isOn
    ? '<span class="st-off">KILLER OFF</span>'
    : '<span class="st-on">KILLER ACTIVE</span>';
}
async function saveApkKiller() {
  const on  = document.getElementById('apkKillState').value;
  const kv  = document.getElementById('apkKillVerInp').value.trim();
  const km  = document.getElementById('apkKillMsgInp').value.trim();
  const kr  = document.getElementById('apkKillRdrInp').value.trim();
  if (on === '1' && !kv) { toast('Enter target version(s) to kill','err'); return; }
  if (on === '1' && !confirm(`Kill APK version(s): "${kv}"?\nAffected users will see the popup and lose app access!`)) return;
  const d = await rbAjax('apk_killer', { kill_on:on, kill_version:kv, kill_msg:km, kill_redirect:kr });
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) { location.hash = 'apkkill'; setTimeout(()=>location.reload(), 1200); }
}

// ── Mod Lib Kill ───────────────────────────────────────
function toggleModKill() {
  const inp=document.getElementById('modKillState');
  const track=document.getElementById('modKillTrack');
  const wrap=document.getElementById('modKillSwitch');
  const lbl=document.getElementById('modKillLbl');
  const isOn=inp.value==='1';
  inp.value=isOn?'0':'1';
  track.classList.toggle('on',!isOn);
  wrap.classList.toggle('on',!isOn);
  lbl.innerHTML=isOn?'<span class="st-off">VERSION MODE</span>':'<span class="st-on">KILL-ALL ACTIVE</span>';
}
async function saveModKill() {
  const mv=document.getElementById('modVerInp').value.trim();
  const mr=document.getElementById('modRdrInp').value.trim();
  const mk=document.getElementById('modKillState').value;
  if(!mv){ toast('Enter a mod version','err'); return; }
  if(mk==='1' && !confirm('Kill ALL mod libs now? Every mod lib will crash immediately.')) return;
  const d=await rbAjax('modlib_save',{mod_version:mv,mod_redirect:mr,mod_kill:mk});
  toast(d.msg, d.ok?'ok':'err');
  if(d.ok){ location.hash='modkill'; setTimeout(()=>location.reload(),1200); }
}

// ── Delete lib ─────────────────────────────────────────
async function rbDelLib(name) {
  if (!confirm(`Delete library: ${name}?`)) return;
  const d = await rbAjax('delete_lib', { fname: name });
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) setTimeout(()=>location.reload(), 800);
}
async function rbDelApk(name) {
  if (!confirm(`Delete APK: ${name}?`)) return;
  const d = await rbAjax('delete_apk', { fname: name });
  toast(d.msg, d.ok ? 'ok' : 'err');
  if (d.ok) setTimeout(()=>location.reload(), 800);
}

// ── Slot tab switcher (BGMI / GLOBAL) ───────────────────────────────────────
function switchSlotTab(cat) {
  const bgmiBtn   = document.getElementById('slotTabBgmi');
  const globalBtn = document.getElementById('slotTabGlobal');
  const bgmiPane  = document.getElementById('slotPaneBgmi');
  const glPane    = document.getElementById('slotPaneGlobal');
  if (!bgmiPane || !glPane) return;
  if (cat === 'bgmi') {
    bgmiPane.style.display = ''; glPane.style.display = 'none';
    if (bgmiBtn)   { bgmiBtn.style.background='#4DDD6A22'; bgmiBtn.style.color='#4DDD6A'; }
    if (globalBtn) { globalBtn.style.background='var(--bg2)'; globalBtn.style.color='var(--muted)'; }
  } else {
    bgmiPane.style.display = 'none'; glPane.style.display = '';
    if (globalBtn) { globalBtn.style.background='#FF7A1822'; globalBtn.style.color='#FF7A18'; }
    if (bgmiBtn)   { bgmiBtn.style.background='var(--bg2)'; bgmiBtn.style.color='var(--muted)'; }
  }
}

// ── Save visible game versions ───────────────────────────────────────────────
async function saveAppConfig() {
  const all = ['bgmi','gl','kr','tw'];
  const vers = all.filter(k => { const el = document.getElementById('vc_'+k); return el && el.checked; });
  const d = await rbAjax('save_appconfig', { versions: vers.join(',') });
  toast(d.msg, d.ok ? 'ok' : 'err');
}

// ── Save slot names / descriptions ──────────────────────────────────────────
async function saveSlotNames() {
  const data = {};
  [1,2,3,4,5,6].forEach(n => {
    const ne = document.getElementById('slot_name_'+n);
    const de = document.getElementById('slot_desc_'+n);
    if (ne) data['slot_name_'+n] = ne.value;
    if (de) data['slot_desc_'+n] = de.value;
  });
  const d = await rbAjax('save_slot_names', data);
  toast(d.msg, d.ok ? 'ok' : 'err');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
