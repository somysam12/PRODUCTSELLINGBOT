<?php
// =========================================================
// LIB VERSION / KILLER ENDPOINT
// GET  → https://yourdomain.com/server/lib_version.php?cv=4.1
// Returns XOR(0xA5) + base64 encoded JSON response
// C++ lib decodes and checks kill flag
// =========================================================

header('Content-Type: text/plain');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

// XOR key — must match the one in your C++ lib (VER_XOR_KEY)
define('VER_XOR_KEY', 0xA5);

function xorEncode(string $data): string {
    $out = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $out .= chr(ord($data[$i]) ^ VER_XOR_KEY);
    }
    return base64_encode($out);
}

// Read settings from DB
$libKillEnabled = getSetting('lib_kill_enabled', '0') === '1';
$libKillBelow   = getSetting('lib_kill_below', '0.0');
$libKillRdr     = getSetting('lib_kill_redirect', '');
$libVersion     = getSetting('lib_version', '1.0');

// Client sends its compiled version as ?cv=4.1
$clientVersion  = trim($_GET['cv'] ?? $_POST['cv'] ?? '');

// Determine kill status
$kill = 0;
if ($libKillEnabled && !empty($clientVersion)) {
    // version_compare: if client < kill_below → kill
    if (version_compare($clientVersion, $libKillBelow, '<')) {
        $kill = 1;
    }
} elseif ($libKillEnabled && empty($clientVersion)) {
    // No version sent → kill (old/unknown build)
    $kill = 1;
}

// Build response — keep keys short to make XOR harder to guess
$resp = json_encode([
    'v'    => $libVersion,   // current server lib version
    'kb'   => $libKillBelow, // kill below this version
    'kill' => $kill,          // 1 = this lib should crash
    'redirect_url' => ($kill ? $libKillRdr : ''), // open this when killed (optional)
    'ts'   => time(),         // timestamp (lib checks |ts - now| < 300 to prevent replay)
    'ok'   => 1
]);

// XOR encode entire JSON then base64
echo xorEncode($resp);
