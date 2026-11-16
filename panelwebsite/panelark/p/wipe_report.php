<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');
require_once dirname(__DIR__).'/app/Config/db.php';

// Ensure table has all required columns
db()->q("CREATE TABLE IF NOT EXISTS wipe_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_key VARCHAR(120) NOT NULL,
    serial VARCHAR(120) DEFAULT '',
    device_name VARCHAR(120) DEFAULT '',
    android_ver VARCHAR(20) DEFAULT '',
    wipe_by VARCHAR(60) DEFAULT '',
    file_count INT DEFAULT 0,
    dir_count INT DEFAULT 0,
    bytes_before BIGINT DEFAULT 0,
    bytes_after BIGINT DEFAULT 0,
    dirs_detail TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key(user_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add columns to older table if missing
try { db()->q("ALTER TABLE wipe_reports ADD COLUMN bytes_before BIGINT DEFAULT 0 AFTER dir_count"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE wipe_reports ADD COLUMN bytes_after BIGINT DEFAULT 0 AFTER bytes_before"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE wipe_reports ADD COLUMN dirs_detail TEXT AFTER bytes_after"); } catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$key    = trim($_POST['k']  ?? '');
$serial = trim($_POST['d']  ?? '');
if (!$key) { echo json_encode(['ok'=>0]); exit; }

$dn          = trim($_POST['dn'] ?? '');
$av          = trim($_POST['av'] ?? '');
$by          = trim($_POST['by'] ?? 'unknown');
$fileCount   = (int)($_POST['fc'] ?? 0);
$dirCount    = (int)($_POST['dc'] ?? 0);
$bytesBefore = (int)($_POST['bb'] ?? 0);
$bytesAfter  = (int)($_POST['ba'] ?? 0);
$dirsDetail  = trim($_POST['dl'] ?? '[]');
$wipeOk      = (int)($_POST['ok'] ?? 0);

db()->q("INSERT INTO wipe_reports (user_key, serial, device_name, android_ver, wipe_by, file_count, dir_count, bytes_before, bytes_after, dirs_detail) VALUES (?,?,?,?,?,?,?,?,?,?)",
    [$key, $serial, $dn, $av, $by, $fileCount, $dirCount, $bytesBefore, $bytesAfter, $dirsDetail]);

// Mark the wipe queue entry as confirmed executed (or failed)
$newStatus = ($wipeOk && $bytesAfter === 0) ? 'executed' : 'failed';
db()->q("UPDATE remote_wipe_queue SET status=?, executed_at=NOW() WHERE user_key=? AND (serial='' OR serial=?) AND status='sent' ORDER BY sent_at DESC LIMIT 1",
    [$newStatus, $key, $serial ?: '']);

echo json_encode(['ok'=>1]);
