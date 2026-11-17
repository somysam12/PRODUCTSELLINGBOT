<?php
// ================================================
// HB PANEL — Configuration
// Panel URL  : https://hbfree.vippanel.in/
// APK API URL: https://hbfree.vippanel.in/server/
// ================================================

// ─── DATABASE ───────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'battleg1_FROZEN');
define('DB_USER',    'battleg1_arkcheat');
define('DB_PASS',    '844121LuvKush');
define('DB_CHARSET', 'utf8mb4');

// ─── URLS ────────────────────────────────────────
define('PANEL_URL',  'https://arkcheat.battleground-hub.shop/');
define('SERVER_URL', 'https://arkcheat.battleground-hub.shop/server/');

// ─── SECURITY ───────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',    15);
define('SESSION_LIFETIME',   1800); // 30-minute idle window, then re-login
define('CSRF_TOKEN_LEN',     32);
define('DDOS_REQ_LIMIT',     120);
define('DDOS_WINDOW_SEC',    60);

// ─── TIMEZONE ───────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ─── SESSION FILE CLEANUP ───────────────────────
// PHP's own garbage collection does NOT run reliably when sessions live in a
// custom save_path (shared hosts often set gc_probability to 0). Session files
// then pile up forever until the folder/disk fills — at which point session_start()
// can no longer write and NOBODY can log in. This sweeper prevents that:
//   • throttled to run at most once every 5 minutes (one cheap filemtime check
//     on the common path), so it never slows normal requests;
//   • deletes dead session files (older than the longest possible cookie life);
//   • hard-caps the folder at 1000 sessions, deleting the oldest beyond that.
// Everything is best-effort (@) so a locked or full folder never breaks the page.
function hbSessionGc(string $dir): void {
    if ($dir === '' || !is_dir($dir)) return;
    $flag = $dir.'/.lastgc';
    $now  = time();
    $last = @filemtime($flag);
    if ($last && ($now - $last) < 300) return;   // throttle: once per 5 min
    @touch($flag);

    $files = @glob($dir.'/sess_*');
    if (!is_array($files) || !$files) return;

    $ttl   = 90000;   // ~25h — longer than the max (24h remember-me) cookie life
    $alive = [];
    foreach ($files as $f) {
        $m = @filemtime($f);
        if ($m === false) continue;
        if (($now - $m) > $ttl) { @unlink($f); }   // dead session → remove
        else { $alive[$f] = $m; }
    }

    // Hard cap — keep only the 1000 most recent live sessions.
    $cap = 1000;
    if (count($alive) > $cap) {
        asort($alive);                             // oldest first
        $excess = count($alive) - $cap;
        $i = 0;
        foreach ($alive as $f => $m) {
            if ($i++ >= $excess) break;
            @unlink($f);
        }
    }
}

// ─── SESSION ────────────────────────────────────
// Machine endpoints (the APK/loader, heartbeats, the Telegram webhook) never send
// a session cookie, so starting a session for them created a brand-new session
// file on EVERY request — thousands per hour — which is what filled
// writable/sessions and locked everyone out. They get no session at all now.
$hbScript  = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$hbUriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$hbMachRe  = '#/(server|secure)/[^/]+(\.php)?/?$|/p/(?!upload_log)[^/]+(\.php)?/?$|/validbool(\.php)?/?$|/api/bot\.php$#';
$hbMachine = (bool)(preg_match($hbMachRe, $hbScript) || preg_match($hbMachRe, $hbUriPath));
if ($hbMachine) {
    if (!isset($_SESSION)) $_SESSION = [];   // helpers read $_SESSION['branch'] ?? … safely
} elseif (session_status() === PHP_SESSION_NONE) {
    // Keep sessions in our OWN folder so the host's /tmp cleanup cron can't
    // delete them early (a common cause of "logged out after 1-2 minutes" on
    // shared hosting). Falls back to the default path if the dir isn't writable.
    $sessDir     = __DIR__.'/../../writable/sessions';
    $sessCustom  = false;
    if (!is_dir($sessDir)) @mkdir($sessDir, 0755, true);
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
        $sessCustom = true;
    }

    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime',  SESSION_LIFETIME);
    // Belt-and-suspenders: let PHP's own GC fire (~2% of requests) in case the
    // host didn't disable it; our hbSessionGc() sweep below is the real guard.
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor',     50);

    // Default: give the session cookie a REAL lifetime (not a browser-session
    // cookie). Without this the cookie dies the moment Chrome suspends the tab
    // or restarts — which is why login was being asked for after 1-2 minutes.
    // Now the cookie itself lives SESSION_LIFETIME seconds; the idle-timeout in
    // requireLogin() still enforces the actual 30-min inactivity window.
    $cookieLife = SESSION_LIFETIME;

    // Remember Me - check cookie before session_start (extends to 24h)
    if (isset($_COOKIE['hb_remember']) && !isset($_COOKIE['PHPSESSID'])) {
        $remData = json_decode(base64_decode($_COOKIE['hb_remember']??''), true);
        if ($remData && !empty($remData['until']) && $remData['until'] > time()) {
            ini_set('session.gc_maxlifetime', 86400);
            $cookieLife = 86400;
        }
    }

    session_set_cookie_params([
        'lifetime' => $cookieLife,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true,  // enable if the panel is HTTPS-only
    ]);
    session_start();

    // Sweep stale/overflowing session files so the folder can never fill up and
    // block logins. Throttled internally to once per 5 min, so it's near-free.
    if ($sessCustom) hbSessionGc($sessDir);

    // Slide the cookie expiry forward on every request so an ACTIVE user never
    // gets logged out mid-use; the hard idle check in requireLogin() still fires
    // after real inactivity.
    if (isset($_SESSION['uid'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $cookieLife,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

// ─── ERRORS ─────────────────────────────────────
error_reporting(0);
ini_set('display_errors', 0);
