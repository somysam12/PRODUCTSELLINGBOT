<?php
// =====================================================================
// /validbool — ENCRYPTED key verification (AES-256-GCM + HMAC)
// ---------------------------------------------------------------------
// Request  (POST): p1 = AES( {k,d,t,pkg,...} ), p2 = HMAC_SHA256(p1)
// Response (AES):  { q, x, z, w, u, v }  — nothing readable:
//   q = 7 valid · 2 invalid/blocked/expired/device-limit · 1 maintenance
//   x = expiry ^ XKEY1   z = now ^ XKEY2   w/u/v = random junk
// The response is AES-authenticated: a fake/patched server cannot forge a
// valid q=7 without the key, so the app's "valid" is SERVER-decided only.
// The device id is stored in the DB on first verify.
// =====================================================================

// ── DEBUG SWITCH ─────────────────────────────────────────────────────
// Set to true, try to log in ONCE from the lib, then open:
//     https://yourdomain.com/vb_debug.log
// It shows EXACTLY which step failed. Set back to false + delete the log
// when done. Debug logging never changes the response the lib receives.
define('VB_DEBUG', false);
function vb_log(string $step): void {
    if (!VB_DEBUG) return;
    @file_put_contents(
        __DIR__.'/vb_debug.log',
        '['.date('H:i:s').'] '.$step."\n",
        FILE_APPEND
    );
}

require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
require_once __DIR__.'/app/Config/secrets.php';   // hb_aes_*, HB_HMAC_KEY, HB_XKEY1/2

header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

// Set by the request decoder below: 'aes' (v4 encrypted) or 'v2' (HMAC-only).
// The response is returned in the SAME format the lib spoke, so a v4 lib gets an
// AES-encrypted reply while an older v3 lib still gets its plaintext reply.
$GLOBALS['vb_mode'] = 'aes';
function vb_out(int $q, int $expUnix = 0): void {
    $mode = $GLOBALS['vb_mode'] ?? 'aes';
    vb_log("RESULT → returning SIGNED q=$q (mode=$mode; lib verifies signature".($mode==='aes'?' + GCM tag':'').")");
    $payload = json_encode([
        'q' => $q,
        'x' => ($expUnix ^ HB_XKEY1),
        'z' => (time()   ^ HB_XKEY2),
        'w' => random_int(100000, 999999),
        'u' => bin2hex(random_bytes(5)),
        'v' => bin2hex(random_bytes(4)),
    ]);
    echo ($mode === 'aes') ? hb_sign_aes($payload) : hb_sign($payload);
    exit;
}
function vb_fake(): void {
    vb_log("RESULT → returning FAKE js (request could not be authenticated/decrypted)");
    echo '/* v'.rand(1,9).'.'.rand(0,99).' */';
    exit;
}

vb_log("==== new request  method=".$_SERVER['REQUEST_METHOD']."  ip=".($_SERVER['REMOTE_ADDR'] ?? '?'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { vb_log("FAIL: not a POST (lib must POST)"); vb_fake(); }

$p1 = trim($_POST['p1'] ?? '');
$p2 = trim($_POST['p2'] ?? '');
$p3 = trim($_POST['p3'] ?? '');          // plaintext build marker (deploy proof)
vb_log("p1 len=".strlen($p1)."  p2 len=".strlen($p2)."  build=".($p3 !== '' ? $p3 : '(none — OLD lib still deployed!)'));

// 1. Authenticate the request (HMAC) — wrong/missing key looks like a plain JS file
$expectHmac = hash_hmac('sha256', $p1, HB_HMAC_KEY);
if (!hash_equals($expectHmac, $p2)) {
    vb_log("FAIL: HMAC mismatch → HB_HMAC_KEY differs between lib and panel.");
    vb_log("      panel expected: ".$expectHmac);
    vb_log("      lib sent (p2) : ".$p2);
    vb_fake();
}
vb_log("OK: HMAC matched (HB_HMAC_KEY is identical on both sides)");

// 2. Decrypt/decode the payload. Try v4 AES-256-GCM first (the encrypted lib),
//    then fall back to v3 base64url-json (older HMAC-only lib). Whichever format
//    the request used, we reply in the SAME format (see $GLOBALS['vb_mode']).
$plain = null;
$aes = hb_aes_decrypt($p1);
if ($aes !== null && json_decode($aes, true) !== null) {
    $plain = $aes;
    $GLOBALS['vb_mode'] = 'aes';
    vb_log("OK: request is v4 AES-256-GCM (decrypted + tag verified).");
} else {
    $b = hb_b64url_decode($p1);
    if ($b !== null && json_decode($b, true) !== null) {
        $plain = $b;
        $GLOBALS['vb_mode'] = 'v2';
        vb_log("OK: request is v3 HMAC-only (base64url json).");
    } else {
        vb_log("FAIL: payload is neither v4 AES nor v3 base64url-json.");
        vb_log("      → keys mismatch or corrupt build. Check p3 marker above.");
        vb_fake();
    }
}
vb_log("OK: payload decoded → ".$plain);

$d   = json_decode($plain, true);
$key = (string)($d['k']   ?? '');
$dev = (string)($d['d']   ?? '');
$ts  = (int)   ($d['t']   ?? 0);
$pkg = (string)($d['pkg'] ?? '');
vb_log("parsed key=".substr($key,0,20)."  dev=".substr($dev,0,12)."  pkg=$pkg  ts=$ts (skew ".(time()-$ts)."s)");

// 3. Replay window (±300s) + basic sanity — 5 min tolerance covers device clock drift
if ($key === '') { vb_log("FAIL: empty key in payload"); vb_out(2); }
if ($ts && abs(time() - $ts) > 300) { vb_log("FAIL: clock skew ".(time()-$ts)."s > 300s → fix device time OR raise the window"); vb_out(2); }

// 4. Maintenance → soft (app can show your message elsewhere)
if (getSetting('maintenance_mode','0') === '1') { vb_log("INFO: maintenance mode ON → q=1"); vb_out(1); }

// 5. Key lookup + state
$k = db()->one("SELECT * FROM keys_code WHERE user_key=? LIMIT 1", [$key]);
if (!$k)                        { vb_log("FAIL: key NOT FOUND in keys_code table → wrong key / wrong DB"); vb_out(2); }
vb_log("key row found id=".$k->id."  status=".$k->status."  expired_date=".($k->expired_date ?? 'null')."  max_devices=".($k->max_devices ?? 'null')."  is_used=".($k->is_used ?? 'null')."  loader_pkg=".($k->loader_pkg ?? 'null'));
if ((int)$k->status !== 1)      { vb_log("FAIL: status is ".$k->status." (need 1=active). 2=blocked, 0=inactive → q=2"); vb_out(2); }
if (!empty($k->expired_date) && strtotime($k->expired_date) < time()) { vb_log("FAIL: key expired_date ".$k->expired_date." is in the past → q=2"); vb_out(2); }

// 6. Device binding — store the device id in the DB (first verify binds it)
if ($dev !== '') {
    $devices = $k->devices ? json_decode($k->devices, true) : [];
    if (!is_array($devices)) $devices = [];
    if (!in_array($dev, $devices, true)) {
        $maxDev = (int)($k->max_devices ?? 1);
        if ($maxDev < 1) $maxDev = 1;                    // treat 0/null as 1-device
        vb_log("device not yet bound. bound=".count($devices)."/".$maxDev);
        if (count($devices) >= $maxDev) { vb_log("FAIL: device limit reached (".count($devices)."/".$maxDev.") → q=2. Reset devices in panel or raise max_devices"); vb_out(2); }
        $devices[] = $dev;
        db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
            [json_encode($devices), count($devices), $k->id]);
        vb_log("device bound OK (now ".count($devices)."/".$maxDev.")");
    } else {
        vb_log("device already bound — ok");
    }
}

// 7. Package binding (first verify binds it; different app later = clone)
if ((int)($k->is_used ?? 0) === 0) {
    db()->q("UPDATE keys_code SET is_used=1, first_used=NOW(), loader_pkg=? WHERE id=?", [$pkg, $k->id]);
    vb_log("package bound (first use) → $pkg");
} elseif (!empty($k->loader_pkg) && $pkg !== '' && $pkg !== $k->loader_pkg) {
    vb_log("FAIL: package mismatch. bound='".$k->loader_pkg."' but lib sent '".$pkg."' → q=2 (clone/different apk)");
    vb_out(2);
}

logAct($k->id ?? null, $k->registrator ?? '', 'VALIDBOOL_OK', 'dev:'.substr($dev,0,10));
vb_log("ALL CHECKS PASSED → q=7 (login should succeed)");
vb_out(7, strtotime($k->expired_date ?? '0'));
