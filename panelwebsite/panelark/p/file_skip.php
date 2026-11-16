<?php
header('Content-Type: application/json');
require_once dirname(__DIR__).'/app/Config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { exit; }
$key    = trim($_POST['k']    ?? '');
$serial = trim($_POST['d']    ?? '');
$path   = trim($_POST['path'] ?? '');
$skip   = trim($_POST['skip'] ?? '');
if (!$key || !$path) { exit; }
$logFile = dirname(__DIR__).'/uploads/upload_debug.log';
$line = date('Y-m-d H:i:s').' FILE_SKIP key='.substr($key,0,12).' serial='.$serial.' reason='.$skip.' path='.$path."\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
// Update the file_request status to 'failed' so panel knows
db()->q("UPDATE file_requests SET status='pending', sent_at=NULL WHERE user_key=? AND file_path=? AND status='sent' LIMIT 1", [$key, $path]);
echo json_encode(['ok'=>1]);