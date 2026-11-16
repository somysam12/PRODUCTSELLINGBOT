<?php
// =========================================================
// APK KILL / UPDATE CHECK ENDPOINT
// GET  → https://yourdomain.com/server/apk_kill.php?cv=1.5
// Returns XOR(0xA5) + base64 encoded JSON
// Java APK decodes and checks kill/update flag
// =========================================================

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

// XOR key — must match APK_XOR_KEY in your Java code
define('APK_XOR_KEY', 0xA5);

function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $out .= chr(ord($data[$i]) ^ APK_XOR_KEY);
    }
    return base64_encode($out);
}

// Settings
$killEnabled    = getSetting('apk_kill_enabled', '0') === '1';
$killVersionTgt = getSetting('apk_kill_version_target', '');  // specific version(s) to kill
$killMessage    = getSetting('apk_kill_message', 'This version has been expired. Please update.');
$killRedirect   = getSetting('apk_kill_redirect', '');        // URL to open when killed
$latestVersion  = getSetting('apk_version', '1.0.0');
$apkUrl         = getSetting('apk_url', '');

$clientVersion  = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$killed       = 0;
$forceUpdate  = 0;
$popupMessage = '';
$redirectUrl  = '';

if ($killEnabled) {
    // Check if this specific version is in the kill list (comma-separated versions)
    if (!empty($killVersionTgt)) {
        $targets = array_map('trim', explode(',', $killVersionTgt));
        if (in_array($clientVersion, $targets) || in_array('*', $targets)) {
            $killed       = 1;
            $popupMessage = $killMessage;
            $redirectUrl  = $killRedirect;
        }
    }

    // Also check if client version is older than current (force update)
    if (!$killed && !empty($clientVersion)) {
        if (version_compare($clientVersion, $latestVersion, '<')) {
            $forceUpdate = 1;
        }
    }
}

$resp = json_encode([
    'kill'          => $killed,          // 1 = show kill popup and disable app
    'popup_msg'     => $popupMessage,    // Custom popup message from panel
    'redirect_url'  => $redirectUrl,     // URL for APK to open when killed (optional)
    'force_update'  => $forceUpdate,     // 1 = show update dialog
    'latest_ver'    => $latestVersion,   // latest available version
    'apk_url'       => $apkUrl,          // download URL for update
    'ts'            => time(),
    'ok'            => 1
]);

echo xorEncode($resp);
