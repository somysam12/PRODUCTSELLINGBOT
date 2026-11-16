<?php
// =========================================================
// MOD LIB — VERSION / KILL ENDPOINT (no download, version only)
// GET  → /server/modlib_version.php?cv=1.0
// Returns XOR(0xA5)+base64 JSON. The mod lib ONLY fetches its
// version here. Bump the version (or flip kill) → old mod libs
// receive kill=1 and crash; if a redirect is set they open it.
// =========================================================

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

define('MOD_XOR_KEY', 0xA5);   // must match the mod lib's XOR key
function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) $out .= chr(ord($data[$i]) ^ MOD_XOR_KEY);
    return base64_encode($out);
}

$modVer  = getSetting('modlib_version', '1.0');
$modKill  = getSetting('modlib_kill_enabled', '0') === '1';
$modRdr   = getSetting('modlib_kill_redirect', '');

$cv = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

$kill = 0;
if ($modKill) {
    $kill = 1;                                   // manual "kill all now"
} elseif ($cv === '') {
    $kill = 1;                                   // unknown build → kill
} elseif (version_compare($cv, $modVer, '<')) {
    $kill = 1;                                   // older than current → kill
}

$resp = json_encode([
    'v'            => $modVer,                    // current mod version
    'kill'         => $kill,                      // 1 = crash this mod lib
    'redirect_url' => ($kill ? $modRdr : ''),     // open when killed (optional)
    'ts'           => time(),
    'ok'           => 1,
]);

echo xorEncode($resp);
