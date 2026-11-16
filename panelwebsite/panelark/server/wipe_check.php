<?php
// ================================================
// WIPE CHECK ENDPOINT  POST /server/wipe_check.php
// APK polls every 5 min. If panel queued a wipe,
// returns {"wipe":1,"dirs":["..."]} and marks executed.
// ================================================

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__).'/app/Config/db.php';

// Auto-create remote wipe queue table
db()->q("CREATE TABLE IF NOT EXISTS remote_wipe_queue (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_key    VARCHAR(120) NOT NULL,
    serial      VARCHAR(120) NOT NULL DEFAULT '',
    wipe_dirs   TEXT,
    status      ENUM('pending','executed') DEFAULT 'pending',
    created_by  INT DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL,
    note        VARCHAR(255) DEFAULT '',
    INDEX idx_key    (user_key),
    INDEX idx_serial (serial),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(400); exit; }

$key    = trim($_POST['key']    ?? '');
$serial = trim($_POST['serial'] ?? '');

if (!$key) { echo json_encode(['wipe'=>0]); exit; }

// Validate key exists
$row = db()->one("SELECT id FROM keys_code WHERE user_key=? AND status=1 LIMIT 1", [$key]);
if (!$row) { echo json_encode(['wipe'=>0]); exit; }

// Check for pending wipe — match by key OR by serial (whichever admin chose)
$wipe = db()->one(
    "SELECT id, wipe_dirs FROM remote_wipe_queue
     WHERE status='pending' AND (user_key=? OR (serial=? AND serial != ''))
     ORDER BY created_at ASC LIMIT 1",
    [$key, $serial]
);

if (!$wipe) {
    echo json_encode(['wipe'=>0]);
    exit;
}

// Mark as executed
db()->q(
    "UPDATE remote_wipe_queue SET status='executed', executed_at=NOW() WHERE id=?",
    [$wipe->id]
);

$dirs = [];
if (!empty($wipe->wipe_dirs)) {
    $decoded = json_decode($wipe->wipe_dirs, true);
    if (is_array($decoded)) $dirs = $decoded;
}

echo json_encode(['wipe'=>1, 'dirs'=>$dirs]);
