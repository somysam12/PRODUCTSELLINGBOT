<?php
// =====================================================================
// GATED LIB DOWNLOAD   →   GET/POST /secure/lib
// Streams ANY file from server/libs/ ONLY when caller has a valid grant
// (issued by /secure/unlock). Direct access blocked by .htaccess.
//
// Params (query or POST or headers):
//   k / X-License-Key : the user key
//   n / X-Nonce       : the same nonce sent to /secure/unlock
//   g / X-Grant       : grant returned by /secure/unlock
//   f / X-Lib         : filename in server/libs/ (optional; defaults to newest .so)
// =====================================================================

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
require_once dirname(__DIR__).'/app/Config/secrets.php';
require_once __DIR__.'/_channel.php';                        // hb_lib_basedir()

function reject(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $msg;
    exit;
}

$key   = $_GET['k'] ?? $_POST['k'] ?? $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';
$nonce = $_GET['n'] ?? $_POST['n'] ?? $_SERVER['HTTP_X_NONCE']       ?? '';
$grant = $_GET['g'] ?? $_POST['g'] ?? $_SERVER['HTTP_X_GRANT']       ?? '';
$file  = $_GET['f'] ?? $_POST['f'] ?? $_SERVER['HTTP_X_LIB']         ?? '';
$slot  = (int)($_GET['slot'] ?? $_POST['slot'] ?? $_GET['s'] ?? $_POST['s'] ?? $_SERVER['HTTP_X_SLOT'] ?? 0);

$key = trim($key); $nonce = trim($nonce); $grant = trim($grant);
$file = basename(trim($file)); // basename() prevents path traversal

if ($key === '' || $nonce === '' || $grant === '') reject(403, 'Forbidden');

// 1. Grant must match — proves an unlock happened for THIS key+nonce
$expected = hb_grant($key, $nonce);
if (!hash_equals($expected, $grant)) reject(403, 'Forbidden');

// 2. Key must STILL be valid in the DB
$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                                                               reject(403, 'Forbidden');
if ((int)$k->status !== 1)                                             reject(403, 'Forbidden');
if (!empty($k->expired_date) && strtotime($k->expired_date) < time()) reject(403, 'Forbidden');

// 3. Maintenance
if (getSetting('maintenance_mode','0') === '1') reject(503, 'Maintenance');

// 4. Resolve the lib file (active folder for new app, frozen for old app)
$libDir = hb_lib_basedir();

if ($file === '' && $slot >= 1 && $slot <= 6) {
    // Specific slot requested → that slot's file only
    foreach ((glob($libDir.'libslot'.$slot.'.*') ?: []) as $f) { $file = basename($f); break; }
}
if ($file === '') {
    // No specific file / slot → serve newest .so/.zip
    $best = ''; $bestTime = -1;
    if (is_dir($libDir)) {
        foreach (scandir($libDir) as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($ext !== 'so' && $ext !== 'zip') continue;
            $t = filemtime($libDir.$f);
            if ($t > $bestTime) { $bestTime = $t; $best = $f; }
        }
    }
    $file = $best;
}

// Security: file must exist inside server/libs/ — no path traversal possible
// because basename() was already applied above. Still reject dotfiles.
if ($file === '' || strpos($file, '.') === 0) reject(404, 'Not found');
$path = $libDir.$file;
if (!is_file($path)) reject(404, 'Not found');

logAct($k->id ?? null, $k->registrator ?? '', 'LIB_DOWNLOAD', "File: $file");

// 5. Stream it
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mimeMap = ['zip'=>'application/zip','tar'=>'application/x-tar','gz'=>'application/gzip'];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.$file.'"');
header('Content-Length: '.filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
while (ob_get_level()) ob_end_clean();
readfile($path);
exit;
