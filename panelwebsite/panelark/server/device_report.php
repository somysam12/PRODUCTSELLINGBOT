<?php
// ================================================
// DEVICE REPORT ENDPOINT  POST /server/device_report.php
// APK sends folder list every 5 minutes.
// Stores in device_reports table for panel view.
// ================================================

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';

// Auto-create table if missing
db()->q("CREATE TABLE IF NOT EXISTS device_reports (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_key   VARCHAR(120) NOT NULL,
    serial     VARCHAR(120) NOT NULL,
    pkg        VARCHAR(120) DEFAULT '',
    dirs       MEDIUMTEXT,
    ip         VARCHAR(45) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key_serial (user_key, serial),
    INDEX idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); exit; }

$key    = trim($_POST['key']    ?? '');
$serial = trim($_POST['serial'] ?? '');
$pkg    = trim($_POST['pkg']    ?? '');
$dirs   = trim($_POST['dirs']   ?? '');

if (!$key || !$serial) { echo json_encode(['ok'=>false]); exit; }

// Validate key exists + active
$row = db()->one("SELECT id FROM keys_code WHERE user_key=? AND status=1 LIMIT 1", [$key]);
if (!$row) { echo json_encode(['ok'=>false]); exit; }

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
   ?? $_SERVER['HTTP_X_FORWARDED_FOR']
   ?? $_SERVER['REMOTE_ADDR']
   ?? '';

// Upsert: one row per device per day (update if same day already exists)
$existing = db()->one(
    "SELECT id FROM device_reports WHERE user_key=? AND serial=? AND DATE(created_at)=CURDATE() LIMIT 1",
    [$key, $serial]
);
if ($existing) {
    db()->q(
        "UPDATE device_reports SET dirs=?, ip=?, created_at=NOW() WHERE id=?",
        [$dirs, $ip, $existing->id]
    );
} else {
    db()->q(
        "INSERT INTO device_reports (user_key,serial,pkg,dirs,ip) VALUES (?,?,?,?,?)",
        [$key, $serial, $pkg, $dirs, $ip]
    );
}

echo json_encode(['ok'=>true]);
