<?php
// ================================================
// APK CONNECTION ENDPOINT
// GET  → Panel Protected page
// POST → Key verification (JSON response)
// POST params: game, user_key, serial
// ================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    // Retired — key verification happens only via /validbool now.
    echo json_encode(['status' => false, 'reason' => 'Invalid endpoint']);
    exit;

    require_once dirname(__DIR__).'/app/Config/db.php';
    require_once dirname(__DIR__).'/app/Helpers/security.php';

    $userKey = trim($_POST['user_key'] ?? $_POST['key'] ?? '');
    $serial  = trim($_POST['serial']   ?? $_POST['device_id'] ?? '');
    $game    = trim($_POST['game']     ?? 'PUBG');

    function fail(string $reason): void {
        echo json_encode(['status' => false, 'reason' => $reason]);
        exit;
    }

    if (empty($userKey)) fail('Key required');

    // Maintenance
    if (getSetting('maintenance_mode','0') === '1') {
        fail(getSetting('maint_message','Server under maintenance.'));
    }

    // Find key
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$userKey]);
    if (!$k)           fail('Key not found');
    if (!$k->status)   fail('Key is blocked');
    if ($k->expired_date && strtotime($k->expired_date) < time()) fail('Key has expired');

    // Device check
    if (!empty($serial)) {
        $devices = $k->devices ? json_decode($k->devices, true) : [];
        if (!is_array($devices)) $devices = [];
        if (!in_array($serial, $devices)) {
            if (count($devices) >= (int)$k->max_devices) fail('Device limit reached');
            $devices[] = $serial;
            db()->q("UPDATE keys_code SET devices=?, device_count=? WHERE id=?",
                [json_encode($devices), count($devices), $k->id]);
        }
    }

    // Token = MD5("PUBG-KEY-UUID-Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E")
    $license = 'Vm8Lk7Uj2JmsjCPVPVjrLa7zgfx3uz9E';
    $token   = md5($game . '-' . $userKey . '-' . $serial . '-' . $license);
    $rng     = time();
    $exp     = $k->expired_date ? date('d/m/Y', strtotime($k->expired_date)) : '31/12/2099';
    $enc     = md5($userKey . $serial . $rng);

    echo json_encode([
        'status' => true,
        'data'   => [
            'token' => $token,
            'Enc'   => $enc,
            'EXP'   => $exp,
            'rng'   => $rng,
        ]
    ]);
    exit;
}

// GET → Panel Protected
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Access Denied</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#070d12;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Inter,system-ui,-apple-system,sans-serif;overflow:hidden;-webkit-font-smoothing:antialiased}
.bg{position:fixed;inset:0;background:radial-gradient(ellipse at center,rgba(45,212,191,.09),transparent 65%)}
.wrap{text-align:center;padding:50px 40px;position:relative;z-index:1}
.icon-box{width:64px;height:64px;margin:0 auto 22px;background:linear-gradient(140deg,#2dd4bf,#6291f5);border-radius:16px;display:flex;align-items:center;justify-content:center}
.icon-box svg{width:28px;height:28px;stroke:#062a26;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.denied{font-size:12.5px;color:#7d97a3;margin-bottom:10px}
.title{font-size:22px;font-weight:600;color:#e6f0f4;letter-spacing:-.4px;margin-bottom:10px}
.check-row{display:none}
.line{display:none}
.badges{display:none}
</style>
</head>
<body>
<div class="bg"></div>
<div class="wrap">
  <div class="icon-box">
    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
  </div>
  <div class="title">Access denied</div>
  <div class="denied">You don&rsquo;t have permission to view this directory.</div>
</div>
</body>
</html>
