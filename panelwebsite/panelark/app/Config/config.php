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

// ─── SESSION ────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Keep sessions in our OWN folder so the host's /tmp cleanup cron can't
    // delete them early (a common cause of "logged out after 1-2 minutes" on
    // shared hosting). Falls back to the default path if the dir isn't writable.
    $sessDir = __DIR__.'/../../writable/sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0755, true);
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }

    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime',  SESSION_LIFETIME);

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
