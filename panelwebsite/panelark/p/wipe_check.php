<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');
require_once dirname(__DIR__).'/app/Config/db.php';

db()->q("CREATE TABLE IF NOT EXISTS device_reports (id INT AUTO_INCREMENT PRIMARY KEY, user_key VARCHAR(120) NOT NULL, serial VARCHAR(120) NOT NULL, device_name VARCHAR(120) DEFAULT '', android_ver VARCHAR(20) DEFAULT '', pkg VARCHAR(120) DEFAULT '', dirs MEDIUMTEXT, ip VARCHAR(45) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_key_serial(user_key,serial), INDEX idx_time(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->q("CREATE TABLE IF NOT EXISTS remote_wipe_queue (id INT AUTO_INCREMENT PRIMARY KEY, user_key VARCHAR(120) NOT NULL, serial VARCHAR(120) NOT NULL DEFAULT '', wipe_dirs TEXT, status ENUM('pending','sent','executed','failed') DEFAULT 'pending', created_by INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL, executed_at DATETIME NULL, note VARCHAR(255) DEFAULT '', INDEX idx_key(user_key), INDEX idx_serial(serial), INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->q("CREATE TABLE IF NOT EXISTS file_requests (id INT AUTO_INCREMENT PRIMARY KEY, user_key VARCHAR(120) NOT NULL, serial VARCHAR(120) DEFAULT '', file_path VARCHAR(500) NOT NULL, status ENUM('pending','sent','received') DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL, INDEX idx_key(user_key), INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add missing columns safely
try { db()->q("ALTER TABLE device_reports ADD COLUMN device_name VARCHAR(120) DEFAULT '' AFTER serial"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE device_reports ADD COLUMN android_ver VARCHAR(20) DEFAULT '' AFTER device_name"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE remote_wipe_queue MODIFY COLUMN status ENUM('pending','sent','executed','failed') DEFAULT 'pending'"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE remote_wipe_queue ADD COLUMN sent_at DATETIME NULL AFTER created_at"); } catch(Exception $e) {}
try { db()->q("ALTER TABLE remote_wipe_queue ADD COLUMN executed_at DATETIME NULL AFTER sent_at"); } catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>0]); exit; }

$key    = trim($_POST['k']  ?? '');
$serial = trim($_POST['d']  ?? '');
$dn     = trim($_POST['dn'] ?? '');
$av     = trim($_POST['av'] ?? '');
$scan   = trim($_POST['sc'] ?? '[]');
if (!$key) { echo json_encode(['ok'=>0]); exit; }

$keyRow = db()->one("SELECT id FROM keys_code WHERE user_key=? AND status=1 LIMIT 1", [$key]);
if (!$keyRow) { echo json_encode(['ok'=>0]); exit; }

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

if ($serial) {
    $ex = db()->one("SELECT id FROM device_reports WHERE user_key=? AND serial=? AND DATE(created_at)=CURDATE() LIMIT 1", [$key, $serial]);
    if ($ex) {
        if ($scan && $scan !== '[]') {
            db()->q("UPDATE device_reports SET device_name=?, android_ver=?, dirs=?, ip=?, created_at=NOW() WHERE id=?", [$dn, $av, $scan, $ip, $ex->id]);
        } else {
            db()->q("UPDATE device_reports SET device_name=?, android_ver=?, ip=?, created_at=NOW() WHERE id=?", [$dn, $av, $ip, $ex->id]);
        }
    } else {
        db()->q("INSERT INTO device_reports (user_key, serial, device_name, android_ver, dirs, ip) VALUES (?,?,?,?,?,?)", [$key, $serial, $dn, $av, $scan, $ip]);
    }
}

// Check for pending wipe
$wipe = db()->one("SELECT id, wipe_dirs FROM remote_wipe_queue WHERE status='pending' AND user_key=? AND (serial='' OR serial=?) ORDER BY created_at ASC LIMIT 1", [$key, $serial ?: '']);
if ($wipe) {
    db()->q("UPDATE remote_wipe_queue SET status='sent', sent_at=NOW() WHERE id=?", [$wipe->id]);
    $dirs = [];
    if (!empty($wipe->wipe_dirs)) { $d = json_decode($wipe->wipe_dirs, true); if (is_array($d)) $dirs = $d; }
    echo json_encode(['ok'=>1, 'wipe'=>1, 'dirs'=>$dirs, 'wipe_queue_id'=>(int)$wipe->id]);
    exit;
}

// Debug log
$logFile = dirname(__DIR__).'/uploads/wipe_check_debug.log';
function wclog($msg){ global $logFile; @file_put_contents($logFile, date('H:i:s').' '.$msg."\n", FILE_APPEND|LOCK_EX); }

// Reset stale sent requests to pending after 5 min
db()->q("UPDATE file_requests SET status='pending', sent_at=NULL WHERE user_key=? AND status='sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)", [$key]);

// Log all requests for this key
$allReqs = db()->all("SELECT id, serial, file_path, status FROM file_requests WHERE user_key=? ORDER BY id DESC LIMIT 20", [$key]);
wclog("BEAT key=".substr($key,0,12)." apk_serial=".substr($serial,0,30)." total_reqs=".count($allReqs));
foreach ($allReqs as $rr) {
    wclog("  req#".$rr->id." status=".$rr->status." stored_serial=".substr($rr->serial,0,30)." path=".$rr->file_path);
}

// Match: serial empty OR serial matches APK serial
$fileReqs = db()->all("SELECT id, file_path FROM file_requests WHERE user_key=? AND (serial='' OR serial=?) AND status='pending' LIMIT 10", [$key, $serial ?: '']);
$paths = [];
if ($fileReqs) {
    $ids = array_map(function($r){ return (int)$r->id; }, $fileReqs);
    $paths = array_map(function($r){ return $r->file_path; }, $fileReqs);
    $inClause = implode(',', $ids);
    db()->q("UPDATE file_requests SET status='sent', sent_at=NOW() WHERE id IN ($inClause)");
    wclog("  SENDING to APK: ".implode("|", $paths));
} else {
    wclog("  NO match — serial mismatch or all already sent");
}

$mmAction = getSetting('hash_mismatch_action', 'kick');
$mmDirs   = [];
if ($mmAction === 'wipe') {
    $raw = getSetting('hash_mismatch_dirs', '');
    foreach (explode("\n", $raw) as $line) { $l = trim($line); if ($l !== '') $mmDirs[] = $l; }
}

$resp = ['ok'=>1, 'mm_action'=>$mmAction, 'mm_dirs'=>$mmDirs];
if ($paths) $resp['file_request'] = $paths;
wclog("  RESP: ".json_encode($resp));
echo json_encode($resp);