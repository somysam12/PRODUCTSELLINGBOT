<?php
require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

$wcLog = dirname(__DIR__).'/uploads/wipe_check_debug.log';
$ulLog = dirname(__DIR__).'/uploads/upload_debug.log';

echo "========== WIPE_CHECK LOG (latest 60 lines) ==========\n";
if (!file_exists($wcLog)) {
    echo "No wipe_check log yet — APK has not called wipe_check.php since panel update.\n";
    echo "Deploy the new panel zip first, then wait 60s for APK heartbeat.\n";
} else {
    $lines = file($wcLog);
    echo implode('', array_slice(array_reverse($lines), 0, 60));
}

echo "\n========== FILE_UPLOAD LOG ==========\n";
if (!file_exists($ulLog)) {
    echo "No upload log yet.\n";
} else {
    $lines = file($ulLog);
    echo implode('', array_slice(array_reverse($lines), 0, 40));
}