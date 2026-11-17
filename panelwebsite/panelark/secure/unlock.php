<?php
// =====================================================================
// LIB UNLOCK ENDPOINT   →   POST /secure/unlock
// ---------------------------------------------------------------------
// The APK calls this AFTER login, as a SEPARATE request, before it is
// allowed to download the native lib. Even if someone patches the login
// screen, they never receive a valid grant here unless the key really
// exists & is valid in OUR database — so the lib stays locked.
//
// Request  (application/x-www-form-urlencoded):
//   p1 = AES-256-GCM( json{ k:key, d:deviceId, n:nonceHex, pkg:package } )
//   p2 = HMAC_SHA256(p1)
//
// Response (AES-256-GCM encrypted, base64url) — NO date / expiry / time:
//   { ok:1, g:grant, lib:filename, lh:libHash, a,b,c: junk }   (valid)
//   { ok:0, a,b,c: junk }                                       (invalid)
// grant = HMAC(key|nonce, UNLOCK_SECRET) — download endpoint recomputes it.
// =====================================================================

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';   // getSetting(), logAct()
require_once dirname(__DIR__).'/app/Config/secrets.php';
require_once __DIR__.'/_channel.php';                        // hb_lib_basedir()

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

function out(array $payload): void {
    echo hb_aes_encrypt(json_encode($payload));
    exit;
}
function deny(): void {
    // Uniform "invalid" shape + junk so failures look identical to captures
    out(['ok'=>0,
         'a'=>bin2hex(random_bytes(6)),
         'b'=>random_int(100000,999999),
         'c'=>bin2hex(random_bytes(4))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Look like a harmless static file to scanners
    echo '/* v'.rand(1,9).'.'.rand(0,99).' */';
    exit;
}

$p1 = trim($_POST['p1'] ?? '');
$p2 = trim($_POST['p2'] ?? '');
if (!$p1 || !$p2) deny();

// 1. Authenticate request
if (!hash_equals(hash_hmac('sha256', $p1, HB_HMAC_KEY), $p2)) deny();

// 2. Decrypt
$plain = hb_aes_decrypt($p1);
if (!$plain) deny();

$d     = json_decode($plain, true);
$key   = (string)($d['k']   ?? '');
$dev   = (string)($d['d']   ?? '');
$nonce = (string)($d['n']   ?? '');
$pkg   = (string)($d['pkg'] ?? '');
// Which lib slot the app wants (1-6). Accept it from the encrypted body first,
// then plain params, so every app build keeps working. 0 = "whatever is newest".
$slot  = (int)($d['slot'] ?? $d['s'] ?? $_POST['slot'] ?? $_POST['s'] ?? $_GET['slot'] ?? $_GET['s'] ?? 0);

// Nonce must be present (client-chosen freshness — NOT a timestamp)
if ($key === '' || $nonce === '' || strlen($nonce) < 8 || strlen($nonce) > 128) deny();

// 3. Maintenance
if (getSetting('maintenance_mode','0') === '1') deny();

// 4. Validate the key against OUR database (the real gate)
$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                                                              deny(); // unknown key
if ((int)$k->status !== 1)                                            deny(); // blocked/disabled
if (!empty($k->expired_date) && strtotime($k->expired_date) < time())deny(); // expired

// Activate on first use — start the validity clock now if it hasn't started.
if (empty($k->expired_date) && (int)($k->duration ?? 0) > 0) {
    db()->q("UPDATE keys_code SET expired_date = DATE_ADD(NOW(), INTERVAL duration HOUR), first_used = COALESCE(first_used, NOW()), is_used = 1 WHERE id=? AND expired_date IS NULL AND duration > 0", [$k->id]);
    $k->expired_date = date('Y-m-d H:i:s', time() + (int)$k->duration * 3600);
}

// 5. Device binding (optional but enforced when serial supplied)
if ($dev !== '') {
    $devices = $k->devices ? json_decode($k->devices, true) : [];
    if (!is_array($devices)) $devices = [];
    if (!in_array($dev, $devices, true)) {
        if (count($devices) >= (int)$k->max_devices) deny();  // device limit
        $devices[] = $dev;
        db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
            [json_encode($devices), count($devices), $k->id]);
    }
}

// 6. Package binding (first valid unlock binds the package)
if ((int)($k->is_used ?? 0) === 0) {
    db()->q("UPDATE keys_code SET is_used=1, first_used=NOW(), loader_pkg=? WHERE id=?",
        [$pkg, $k->id]);
} elseif (!empty($k->loader_pkg) && $pkg !== '' && $pkg !== $k->loader_pkg) {
    deny(); // different app = clone / crack
}

// 7. Pick the lib file to deliver + its hash
$libRow  = db()->one("SELECT lib_name, lib_hash FROM lib WHERE id=1 LIMIT 1");
$libHash = $libRow->lib_hash ?? '';
$libFile = pick_deliver_lib($slot);   // the requested slot's file (or newest)

// 8. Issue the grant (unguessable, contains NO date/time/expiry)
$grant = hb_grant($key, $nonce);

logAct($k->id ?? null, $k->registrator ?? '', 'LIB_UNLOCK', "Key OK · dev:".substr($dev,0,10));

out([
    'ok'   => 1,
    'g'    => $grant,
    'lib'  => $libFile,
    'slot' => $slot,
    'lh'   => $libHash,
    'a'   => bin2hex(random_bytes(5)),
    'b'   => random_int(100000, 999999),
    'c'   => bin2hex(random_bytes(4)),
]);

// ── helper: the file for a specific slot, else the newest lib ─────────
function pick_deliver_lib(int $slot = 0): string {
    $dir = hb_lib_basedir();   // active (new app) or frozen (old app) folder
    // Exact slot requested → serve ONLY that slot's file (libslot{N}.*)
    if ($slot >= 1 && $slot <= 6) {
        $hit = glob($dir.'libslot'.$slot.'.*') ?: [];
        foreach ($hit as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, ['so','zip','tar','gz','lib','dll','a','o','bin'], true)) return basename($f);
        }
        return '';   // slot empty → nothing to deliver (don't fall back to another slot)
    }
    // No slot given (legacy app) → newest lib, preferring slot 1
    $best = ''; $bestTime = -1;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ($ext !== 'so' && $ext !== 'zip') continue;
            $t = filemtime($dir.$f);
            if ($t > $bestTime) { $bestTime = $t; $best = $f; }
        }
    }
    return $best;
}
