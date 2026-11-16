<?php
/**
 * ────────────────────────────────────────────────────────────────
 *  ONE-CLICK PERMISSION SETUP
 * ────────────────────────────────────────────────────────────────
 *  Visit this once after uploading:  https://yourdomain/setup_permissions.php
 *  It creates every folder the panel writes to and sets the right
 *  permissions automatically — no more manual chmod on cPanel.
 *
 *  Owner-only. Delete it after running if you like (not required).
 * ────────────────────────────────────────────────────────────────
 */
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { http_response_code(403); exit('Owner only.'); }

header('Content-Type: text/plain; charset=utf-8');

// Folders that must be WRITABLE by the web server (uploads, generated files, sessions)
$writable = [
    __DIR__.'/uploads',
    __DIR__.'/uploads/logos',
    __DIR__.'/writable',
    __DIR__.'/writable/uploads',
    __DIR__.'/writable/uploads/logos',
    __DIR__.'/writable/sessions',
    __DIR__.'/server',
    __DIR__.'/server/libs',
    __DIR__.'/server/apks',
];

// Code folders — only need to be readable/traversable (755)
$readonly = [
    __DIR__.'/app',
    __DIR__.'/secure',
];

echo "KuroFileArk — Permission Setup\n";
echo str_repeat('=', 40)."\n\n";

$ok = 0; $fail = 0;

// 1) Create + chmod writable dirs to 0755 (works on most hosts). If the host
//    runs PHP as a different user than the file owner (common on shared cPanel),
//    0755 isn't enough for uploads, so we try 0775 then 0777 as fallbacks and
//    verify with an actual write test.
foreach ($writable as $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $done = false;
    foreach ([0755, 0775, 0777] as $mode) {
        @chmod($dir, $mode);
        // verify we can actually write
        $test = $dir.'/.permtest';
        if (@file_put_contents($test, 'ok') !== false) {
            @unlink($test);
            printf("  [WRITABLE %o] %s\n", $mode, str_replace(__DIR__, '.', $dir));
            $done = true; $ok++;
            break;
        }
    }
    if (!$done) { printf("  [FAILED]      %s  (set to 777 manually in cPanel)\n", str_replace(__DIR__,'.',$dir)); $fail++; }
}

echo "\n";

// 2) Code folders → 0755 (readable, not world-writable — safer)
foreach ($readonly as $dir) {
    if (is_dir($dir)) {
        @chmod($dir, 0755);
        printf("  [READ 0755]   %s\n", str_replace(__DIR__, '.', $dir));
        $ok++;
    }
}

// 3) Protect the session dir from web access
$sessHt = __DIR__.'/writable/sessions/.htaccess';
if (!is_file($sessHt)) { @file_put_contents($sessHt, "Require all denied\n"); }

echo "\n".str_repeat('=', 40)."\n";
echo "Done. OK: $ok   Failed: $fail\n";
if ($fail) {
    echo "\nSome folders couldn't be made writable automatically.\n";
    echo "In cPanel File Manager, set those to 0777 manually.\n";
} else {
    echo "\nAll good — uploads, logos, libs, apks and sessions are ready.\n";
    echo "You can delete this file now if you want.\n";
}
logAct($_SESSION['uid'] ?? null, $_SESSION['uname'] ?? 'owner', 'RUN_PERMISSION_SETUP', "OK:$ok Fail:$fail");
