<?php
require_once dirname(__DIR__).'/Config/db.php';

// ─── CSRF ────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(CSRF_TOKEN_LEN));
    }
    return $_SESSION['csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf" value="'.csrfToken().'">';
}
function verifyCsrf(string $t): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// ─── IP DETECTION ────────────────────────────────
function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',',$_SERVER[$k])[0]);
            if (filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
                return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ─── DDOS / RATE LIMIT ───────────────────────────
function ddosCheck(): void {
    $ip = clientIp();
    // Check blocked IPs
    $b = db()->one("SELECT id FROM blocked_ips WHERE ip=? AND (expires_at IS NULL OR expires_at>NOW())",[$ip]);
    if ($b) {
        if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'],'application/json')) {
            http_response_code(403);
            die(json_encode(['ok'=>false,'msg'=>'Your IP has been blocked. You cannot use this panel anymore.']));
        }
        http_response_code(403);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied</title><style>
        *{margin:0;padding:0;box-sizing:border-box}body{background:#07090f;color:#f0f4ff;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .box{text-align:center;padding:40px}.ico{font-size:44px;margin-bottom:20px}
        .t{font-size:22px;font-weight:900;color:#ff4d6d;margin-bottom:10px}
        .s{font-size:13px;color:#5a6a8a;line-height:1.8}
        </style></head><body><div class="box">
        <div class="ico">🚫</div>
        <div class="t">Access Denied</div>
        <div class="s">Your IP has been blocked.<br>You cannot use this panel anymore.<br><br>Contact admin if you think this is a mistake.</div>
        </div></body></html>');
    }

    $ep = $_SERVER['REQUEST_URI'] ?? '/';
    $row = db()->one("SELECT id,req_count,window_start FROM rate_limits WHERE ip=? AND endpoint=?",[$ip,$ep]);
    if ($row) {
        if (strtotime($row->window_start) < time()-DDOS_WINDOW_SEC) {
            db()->q("UPDATE rate_limits SET req_count=1,window_start=NOW() WHERE id=?",[$row->id]);
        } else {
            if ($row->req_count >= DDOS_REQ_LIMIT) {
                db()->q("INSERT IGNORE INTO blocked_ips(ip,reason,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))",
                    [$ip,'Rate limit exceeded']);
                tgDdosAlert($ip, $ep);
                http_response_code(429);
                die(json_encode(['ok'=>false,'msg'=>'Too many requests.']));
            }
            db()->q("UPDATE rate_limits SET req_count=req_count+1 WHERE id=?",[$row->id]);
        }
    } else {
        db()->q("INSERT INTO rate_limits(ip,endpoint) VALUES(?,?)",[$ip,$ep]);
    }
}

// ─── AUTH ─────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['uid']) && !empty($_SESSION['uname']);
}

function requireLogin(): void {
    if (!isLoggedIn()) { header('Location: /'); exit; }
    // Absolute session: expires SESSION_LIFETIME after LOGIN, regardless of
    // activity or refreshes. This matches the on-screen countdown exactly.
    $start = $_SESSION['login_at'] ?? $_SESSION['last_act'] ?? time();
    if ((time() - $start) > SESSION_LIFETIME) {
        session_unset(); session_destroy();
        header('Location: /?timeout=1'); exit;
    }
    $_SESSION['last_act'] = time();
    if (!isset($_SESSION['login_at'])) $_SESSION['login_at'] = $start; // heal old sessions
    checkPanelLicense(); // Check license validity
}

function requireLevel(int $level): void {
    requireLogin();
    if ((int)($_SESSION['ulevel'] ?? 99) > $level) {
        header('Location: /dashboard.php'); exit;
    }
}

function attemptLogin(string $username, string $pass): array {
    $ip = clientIp();
    $u  = db()->one("SELECT * FROM users WHERE username=?",[$username]);
    if (!$u) return ['ok'=>false,'msg'=>'Invalid username or password.'];

    if ($u->locked_until && strtotime($u->locked_until) > time()) {
        $m = ceil((strtotime($u->locked_until)-time())/60);
        return ['ok'=>false,'msg'=>"Account locked. Retry in {$m} min."];
    }
    // If we got past the check above while locked_until is still set, the lockout
    // window has EXPIRED — clear it and reset the attempt counter so the user gets
    // a fresh set of 5 attempts and can never be permanently stuck. (We only reset
    // on an expired lock, so normal failed attempts still accumulate toward a lock.)
    if ($u->locked_until) {
        db()->q("UPDATE users SET login_attempts=0, locked_until=NULL WHERE id=?", [$u->id]);
        $u->login_attempts = 0;
        $u->locked_until   = null;
    }
    if ($u->status == 0) return ['ok'=>false,'msg'=>'Account banned. Contact admin.'];

    if (!password_verify($pass, $u->password)) {
        $att = $u->login_attempts + 1;
        if ($att >= MAX_LOGIN_ATTEMPTS) {
            $lock = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES*60);
            db()->q("UPDATE users SET login_attempts=?,locked_until=? WHERE id=?",[$att,$lock,$u->id]);
            return ['ok'=>false,'msg'=>'Too many attempts. Locked for '.LOCKOUT_MINUTES.' min.'];
        }
        db()->q("UPDATE users SET login_attempts=? WHERE id=?",[$att,$u->id]);
        return ['ok'=>false,'msg'=>'Invalid username or password.'];
    }

    // ── Single-device panel login (opt-in; OWNER is exempt; fail-open) ──
    if ((int)$u->level > 1 && getSetting('single_device_login','0') === '1') {
        try {
            $dev = $_COOKIE['hb_device'] ?? '';
            if ($dev === '' || strlen($dev) < 24) {
                $dev = bin2hex(random_bytes(16));
                setcookie('hb_device', $dev, time()+60*60*24*365, '/', '', false, true);
            }
            $tok   = hash('sha256', $dev);
            $bound = $u->login_device ?? '';
            if ($bound === '' || $bound === null) {
                db()->q("UPDATE users SET login_device=? WHERE id=?", [$tok, $u->id]);   // bind first device
            } elseif (!hash_equals((string)$bound, $tok)) {
                return ['ok'=>false,'msg'=>'This account is locked to another device. Use "Reset Device" on the login page to move it.'];
            }
        } catch (Throwable $e) { /* columns missing → don't block login */ }
    }

    db()->q("UPDATE users SET login_attempts=0,locked_until=NULL,last_login=NOW() WHERE id=?",[$u->id]);
    // Owner always has unlimited balance
    if((int)$u->level === 1) {
        db()->q("UPDATE users SET balance=999999999.99 WHERE id=? AND level=1",[$u->id]);
    }
    session_regenerate_id(true);
    $_SESSION['uid']    = $u->id;
    $_SESSION['uname']  = $u->username;
    $_SESSION['ulevel'] = $u->level;
    $_SESSION['branch'] = $u->branch ?? 'main';
    $_SESSION['last_act'] = time();
    $_SESSION['login_at'] = time(); // fixed session start — 30-min timer counts from here, refresh doesn't reset it
    logAct($u->id, $u->username, 'LOGIN', 'Logged in', $ip);
    return ['ok'=>true];
}

// ─── SETTINGS ─────────────────────────────────────
function getSetting(string $key, string $def=''): string {
    $r = db()->one("SELECT svalue FROM panel_settings WHERE skey=?",[$key]);
    return $r ? ($r->svalue ?? $def) : $def;
}

// Ensure a directory exists AND is writable by the web server. Creates it if
// missing and escalates the mode (0755 → 0775 → 0777) until a real write test
// passes — so uploads work on any host without manual chmod. Returns true if
// the directory ended up writable.
function ensureWritableDir(string $dir): bool {
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir)) return false;
    if (is_writable($dir)) return true;
    foreach ([0775, 0777] as $mode) {
        @chmod($dir, $mode);
        if (is_writable($dir)) return true;
    }
    // last resort: real write test (is_writable can be unreliable on some hosts)
    $t = $dir.'/.permtest';
    if (@file_put_contents($t, 'ok') !== false) { @unlink($t); return true; }
    return false;
}

// ─── BRANCH ISOLATION ─────────────────────────────
// Current logged-in user's branch. 'main' is the normal panel.
function currentBranch(): string {
    return $_SESSION['branch'] ?? 'main';
}
// SQL fragment to scope a query to the caller's branch. $col = the branch column
// on the table being queried (defaults to `branch`). Returns e.g. " AND branch='main'".
// Params are appended to $params by reference so everything stays bound/safe.
function branchScope(string $col='branch', array &$params=[]): string {
    $params[] = currentBranch();
    return " AND {$col}=? ";
}
// Resolve the branch a NEW account should inherit — from its creator's branch.
function branchOfUser(string $username): string {
    $r = db()->one("SELECT branch FROM users WHERE username=? LIMIT 1",[$username]);
    return $r->branch ?? 'main';
}
// Resolve branch from a referral code (so registrations inherit the creator's branch).
function branchOfRefCode(string $code): string {
    $r = db()->one("SELECT branch FROM referral_codes WHERE code=? LIMIT 1",[$code]);
    return $r->branch ?? 'main';
}
function setSetting(string $key, string $val): void {
    db()->q("INSERT INTO panel_settings(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=?",
        [$key,$val,$val]);
}

// Base URL used to build ALL generated links (connect / kill / secure / heartbeat).
// Owner can override it (Manage Rebranding / Server & Maintenance) so the panel works
// on any domain/subdomain without editing code. Falls back to the config constant.
function panelBase(): string {
    $b = trim(getSetting('panel_base_url',''));
    if ($b === '') $b = PANEL_URL;
    return rtrim($b, '/');
}

// ─── LOGGING ──────────────────────────────────────
function logAct(?int $uid, ?string $uname, string $action, string $detail='', string $ip=''): void {
    // Stamp the acting user's branch so hidden-branch activity stays isolated.
    $branch = $_SESSION['branch'] ?? null;
    if ($branch === null && $uname) { $branch = branchOfUser($uname); }
    if ($branch === null) $branch = 'main';
    db()->q("INSERT INTO activity_logs(user_id,username,action,detail,ip,branch) VALUES(?,?,?,?,?,?)",
        [$uid,$uname,$action,$detail,$ip ?: clientIp(),$branch]);
}

// ─── REFERRAL CODE GENERATOR ──────────────────────
function generateRefCode(int $len=6): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    do {
        $code = '';
        for ($i=0;$i<$len;$i++) $code .= $chars[random_int(0,strlen($chars)-1)];
        $exists = db()->one("SELECT id FROM referral_codes WHERE code=?",[$code]);
    } while ($exists);
    return $code;
}

// ─── KEY GENERATOR ────────────────────────────────
// Supports any prefix (case preserved) + selectable formats.
// Formats: classic | compact | dash | underscore | short | tiny
function generateKey(string $prefixOverride=''): string {
    $prefix = $prefixOverride !== '' ? $prefixOverride : getSetting('key_prefix','HB');
    $format = getSetting('key_format','classic');

    $U = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';                              // upper + digits
    $L = 'abcdefghijklmnopqrstuvwxyz';                                        // lowercase
    $D = '0123456789';                                                       // digits
    $A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';   // full mixed

    $rnd = function(string $set, int $n): string {
        $s=''; $m=strlen($set)-1;
        for ($i=0;$i<$n;$i++) $s .= $set[random_int(0,$m)];
        return $s;
    };

    switch ($format) {
        case 'compact':     // ArkCheat2384grhefgjh
            return $prefix.$rnd($D,4).$rnd($L,8);
        case 'dash':        // ArkCheat-832837-dhd
            return $prefix.'-'.$rnd($D,6).'-'.$rnd($L,3);
        case 'underscore':  // ArkCheat_72387_ieujre
            return $prefix.'_'.$rnd($D,5).'_'.$rnd($L,6);
        case 'short':       // ArkCheat + 4 mixed
            return $prefix.$rnd($A,4);
        case 'tiny':        // shortest — 6 random chars, no prefix
            return $rnd($A,6);
        case 'classic':
        default:            // PREFIX-XXXX-XXXX-XXXX-XXXX (upper)
            $key = $prefix.'-';
            for ($g=0;$g<4;$g++) { $key .= $rnd($U,4); if ($g<3) $key .= '-'; }
            return $key;
    }
}

// Live preview sample for the panel UI (mirrors generateKey formats)
function keyFormatSample(string $prefix, string $format): string {
    $old = null; // build a deterministic-ish sample without touching DB settings
    switch ($format) {
        case 'compact':    return $prefix.'2384grhefgjh';
        case 'dash':       return $prefix.'-832937-dhd';
        case 'underscore': return $prefix.'_72387_ieujre';
        case 'short':      return $prefix.'A7b2';
        case 'tiny':       return 'X7b2K9';
        case 'classic':
        default:           return $prefix.'-A1B2-C3D4-E5F6-G7H8';
    }
}

// ─── MAINTENANCE CHECK ────────────────────────────
function maintenanceCheck(): void {
    if (getSetting('maintenance_mode','0') === '1') {
        $msg = getSetting('maint_message','Server under maintenance.');
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'maintenance'=>true,'msg'=>$msg]);
        exit;
    }
}

// ─── PANEL LICENSE CHECK ──────────────────────────
function checkPanelLicense(): void {
    // Check once per hour, but if expired - recheck every 5 mins for instant renew
    $cached     = getSetting('lic_cache','');
    $cachedTime = (int)getSetting('lic_cache_time','0');
    
    if ($cachedTime && $cached) {
        $data = json_decode($cached, true);
        $isExpired = !($data['valid'] ?? true);
        $cacheAge  = time() - $cachedTime;
        
        // If valid: cache 1 hour. If expired: recheck every 5 mins for instant renew
        $cacheLimit = $isExpired ? 300 : 3600;
        
        if ($cacheAge < $cacheLimit) {
            if ($isExpired) {
                showLicenseExpired($data['reason'] ?? 'License expired', $data['expires_at'] ?? '');
            }
            return;
        }
    }
    
    $masterUrl = getSetting('update_server_url','');
    $licKey    = getSetting('panel_license_key','');
    
    if (empty($masterUrl) || empty($licKey)) return; // Not configured = skip
    
    $domain = $_SERVER['HTTP_HOST'] ?? '';
    
    $ch = curl_init($masterUrl . '/license/check.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['panel_key'=>$licKey,'domain'=>$domain]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if (!$res || $code !== 200) return; // Network error = allow (fail open)
    
    $data = json_decode($res, true);
    if (!$data) return;
    
    // Cache the result
    db()->q("UPDATE panel_settings SET svalue=? WHERE skey='lic_cache'",        [json_encode($data)]);
    db()->q("UPDATE panel_settings SET svalue=? WHERE skey='lic_cache_time'",   [time()]);
    
    if (!($data['valid'] ?? true)) {
        showLicenseExpired($data['reason'] ?? 'License expired', $data['expires_at'] ?? '');
    }
}

function showLicenseExpired(string $reason, string $expiredAt): void {
    session_write_close();
    http_response_code(402);
    $exp = $expiredAt ? date('d M Y', strtotime($expiredAt)) : '';
    die('<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Panel Expired</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#070d12;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Inter,system-ui,-apple-system,sans-serif;padding:20px;overflow:hidden;-webkit-font-smoothing:antialiased}
.bg{position:fixed;inset:0;background:radial-gradient(ellipse at center,rgba(251,113,133,.08),transparent 65%)}
.box{background:#0e1820;border:1px solid rgba(255,255,255,.13);border-radius:16px;padding:36px 30px;max-width:410px;width:100%;text-align:center;box-shadow:0 30px 70px rgba(0,0,0,.55);position:relative;z-index:1}
.ico{width:56px;height:56px;margin:0 auto 20px;background:rgba(251,113,133,.13);border-radius:14px;display:flex;align-items:center;justify-content:center}
.ico svg{width:26px;height:26px;stroke:#fb7185;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.badge{display:inline-block;background:rgba(251,113,133,.13);border-radius:20px;padding:4px 13px;font-size:12px;color:#fb7185;font-weight:500;margin-bottom:14px}
h1{color:#e6f0f4;font-size:21px;font-weight:600;letter-spacing:-.4px;margin-bottom:9px}
.reason{font-size:13.5px;color:#7d97a3;margin-bottom:20px;line-height:1.6}
.exp-box{background:rgba(251,113,133,.08);border:1px solid rgba(251,113,133,.22);border-radius:10px;padding:12px 16px;font-size:13px;color:#fbb2be;margin-bottom:22px;display:flex;align-items:center;justify-content:center;gap:8px}
.divider{height:1px;background:rgba(255,255,255,.07);margin:0 0 20px}
.contact-label{font-size:12.5px;color:#7d97a3;margin-bottom:11px}
.tg-btn{display:inline-flex;align-items:center;gap:8px;background:#2dd4bf;border:none;border-radius:9px;padding:10px 20px;text-decoration:none;color:#062a26;font-weight:600;font-size:13.5px;margin-bottom:14px}
.thanks{font-size:12px;color:#55707c}
</style>
</head>
<body>
<div class="bg"></div>
<div class="box">
  <div class="ico">
    <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><line x1="12" y1="15" x2="12" y2="17"/></svg>
  </div>
  <div class="badge">Panel License Expired</div>
  <h1>🔒 Panel Expired</h1>
  <div class="reason">'.htmlspecialchars($reason).'</div>
  '.($exp ? '<div class="exp-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Expired on: '.$exp.'</div>' : '').'
  <div class="divider"></div>
  <div class="contact-label">Renew Your Panel</div>
  <a href="https://t.me/Hamzakhan24" class="tg-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
    Contact @Hamzakhan24
  </a>
  <div class="thanks">To Renew Your Panel Immediately.<br>Thank you 😊</div>
</div>
</body>
</html>');
}

// ─── TELEGRAM ALERTS ─────────────────────────────
// The panel-alert chat id belongs to the MAIN branch owner. Activity in any
// hidden/isolated branch must NEVER alert the main owner, so every alert is
// gated on the acting user's branch being 'main'.
function alertsAllowedForBranch(): bool {
    return (($_SESSION['branch'] ?? 'main') === 'main');
}
function sendTgAlert(string $msg): void {
    // Hard privacy gate: only the main branch may trigger the owner's alerts.
    if (!alertsAllowedForBranch()) return;
    $token = getSetting('tg_bot_token','');
    $chatId= getSetting('tg_chat_id','');
    if (empty($token) || empty($chatId)) return;
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id'=>$chatId,'text'=>$msg,'parse_mode'=>'HTML'];
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($data),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>5,
        CURLOPT_SSL_VERIFYPEER=>false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function tgReferralAlert(string $createdBy, string $code, float $saldo, int $level, int $userLimit, string $branch='main'): void {
    if (getSetting('tg_alerts_enabled','0') !== '1') return;
    // Never alert the main owner about hidden/isolated-branch referrals.
    if ($branch !== 'main') return;
    $lvlName = [1=>'Owner',2=>'Admin',3=>'Reseller'][$level] ?? 'Unknown';
    $time = date('d M Y, h:i:s A');
    $msg = "🚨 <b>Referral Created</b>\n".str_repeat('─',28)."\n";
    $msg .= "👤 <b>Created By:</b> {$createdBy}\n";
    $msg .= "🎫 <b>Code:</b> {$code}\n";
    $msg .= "💰 <b>Saldo:</b> {$saldo}\n";
    $msg .= "🔰 <b>Level:</b> {$level}\n";
    $msg .= "🔢 <b>User Limit:</b> {$userLimit}\n";
    $msg .= "🕐 <b>Time:</b> {$time}";
    sendTgAlert($msg);
}

function tgDdosAlert(string $ip, string $endpoint): void {
    if (getSetting('tg_alerts_enabled','0') !== '1') return;
    $time = date('d M Y, h:i:s A');
    $msg = "🛡️ <b>DDoS Attack Detected</b>\n".str_repeat('─',28)."\n";
    $msg .= "🌐 <b>IP:</b> {$ip}\n";
    $msg .= "📍 <b>Endpoint:</b> {$endpoint}\n";
    $msg .= "🕐 <b>Time:</b> {$time}\n";
    $msg .= "⚠️ IP has been auto-blocked.";
    sendTgAlert($msg);
}
