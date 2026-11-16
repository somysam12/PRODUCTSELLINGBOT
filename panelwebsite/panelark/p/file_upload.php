<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once dirname(__DIR__).'/app/Config/db.php';

db()->q("CREATE TABLE IF NOT EXISTS received_files (id INT AUTO_INCREMENT PRIMARY KEY, user_key VARCHAR(120) NOT NULL, serial VARCHAR(120) DEFAULT '', device_name VARCHAR(120) DEFAULT '', orig_path VARCHAR(500) DEFAULT '', stored_name VARCHAR(300) NOT NULL, file_size BIGINT DEFAULT 0, mime_type VARCHAR(100) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_key(user_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function flog($msg) {
    $logFile = dirname(__DIR__).'/uploads/upload_debug.log';
    @file_put_contents($logFile, date('Y-m-d H:i:s').' '.$msg."\n", FILE_APPEND | LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flog("REJECTED: not POST");
    http_response_code(405); exit;
}

$key      = trim($_POST['k']  ?? '');
$serial   = trim($_POST['d']  ?? '');
$dn       = trim($_POST['dn'] ?? '');
$origPath = trim($_POST['p']  ?? '');

flog("ATTEMPT: key=".substr($key,0,12)." serial=$serial path=$origPath file_error=".($_FILES['f']['error'] ?? 'NO_FILE'));

if (!$key) { flog("FAIL: no key"); echo json_encode(['ok'=>0,'msg'=>'no key']); exit; }
$keyRow = db()->one("SELECT id FROM keys_code WHERE user_key=? AND status=1 LIMIT 1", [$key]);
if (!$keyRow) { flog("FAIL: invalid key"); echo json_encode(['ok'=>0,'msg'=>'invalid key']); exit; }

if (empty($_FILES['f']) || $_FILES['f']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['f']['error'] ?? 'missing';
    flog("FAIL: no file or upload error code=$err");
    echo json_encode(['ok'=>0, 'msg'=>'no file, err='.$err]); exit;
}

$uploadDir = dirname(__DIR__).'/uploads/device_files/';
if (!is_dir($uploadDir)) {
    @mkdir(dirname(__DIR__).'/uploads/', 0755, true);
    @mkdir($uploadDir, 0755, true);
}
@chmod($uploadDir, 0755);
if (!is_writable($uploadDir)) {
    flog("FAIL: upload dir not writable");
    echo json_encode(['ok'=>0, 'msg'=>'upload dir not writable']); exit;
}

$safeKey    = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
$safeSerial = preg_replace('/[^a-zA-Z0-9_-]/', '', $serial ?: 'unknown');
$origName   = basename($_FILES['f']['name']);
$safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$storedName = $safeKey . '_' . $safeSerial . '_' . time() . '_' . $safeName;
$dest       = $uploadDir . $storedName;

if (!move_uploaded_file($_FILES['f']['tmp_name'], $dest)) {
    flog("FAIL: move_uploaded_file failed");
    echo json_encode(['ok'=>0, 'msg'=>'save failed']); exit;
}

$fsize = filesize($dest);
$mime  = mime_content_type($dest) ?: 'application/octet-stream';

db()->q("INSERT INTO received_files (user_key, serial, device_name, orig_path, stored_name, file_size, mime_type) VALUES (?,?,?,?,?,?,?)",
    [$key, $serial, $dn, $origPath, $storedName, $fsize, $mime]);

if ($origPath) {
    db()->q("UPDATE file_requests SET status='received' WHERE user_key=? AND file_path=? AND status='sent' LIMIT 1", [$key, $origPath]);
}

flog("SUCCESS: saved=$storedName size=$fsize mime=$mime");
echo json_encode(['ok'=>1]);