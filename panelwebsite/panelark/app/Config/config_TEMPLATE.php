<?php
// Rename to config.php and fill your DB credentials
// SKIP uploading this file if your live config.php already works!
define('DB_HOST','localhost');
define('DB_NAME','YOUR_DB_NAME'); // e.g. battleg1_FROZEN
define('DB_USER','YOUR_DB_USER');
define('DB_PASS','YOUR_DB_PASS');
define('DB_CHARSET','utf8mb4');
define('PANEL_URL','https://frozenfire.battleground-hub.shop/');
define('SERVER_URL','https://frozenfire.battleground-hub.shop/server/');
define('MAX_LOGIN_ATTEMPTS',5);define('LOCKOUT_MINUTES',15);define('SESSION_LIFETIME',3600);define('CSRF_TOKEN_LEN',32);
date_default_timezone_set('Asia/Kolkata');
if(session_status()===PHP_SESSION_NONE){ini_set('session.cookie_httponly',1);ini_set('session.use_strict_mode',1);ini_set('session.gc_maxlifetime',SESSION_LIFETIME);session_start();}error_reporting(0);ini_set('display_errors',0);