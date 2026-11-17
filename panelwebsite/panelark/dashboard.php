<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__.'/app/Views/Layout/header.php';

// ── Scope: owner sees the whole branch, admin/reseller see their own keys ──
$br    = currentBranch();
$own   = $ulevel > 1;
$scope = $own ? 'registrator=?' : 'branch=?';
$sp    = [$own ? $uname : $br];

$k = db()->one("SELECT COUNT(*) total,
        COALESCE(SUM(status=0),0) blocked,
        COALESCE(SUM(status=1 AND expired_date IS NOT NULL AND expired_date<NOW()),0) expired,
        COALESCE(SUM(status=1 AND (expired_date IS NULL OR expired_date>=NOW()) AND device_count=0),0) unused,
        COALESCE(SUM(status=1 AND (expired_date IS NULL OR expired_date>=NOW()) AND device_count>0),0) inuse,
        COALESCE(SUM(DATE(created_at)=CURDATE()),0) today,
        COALESCE(SUM(status=1 AND expired_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 3 DAY)),0) soon
    FROM keys_code WHERE $scope", $sp);
$totalKeys = (int)$k->total;  $kBlocked = (int)$k->blocked; $kExpired = (int)$k->expired;
$kUnused   = (int)$k->unused; $kInUse   = (int)$k->inuse;
$todayKeys = (int)$k->today;  $expiringSoon = (int)$k->soon;
$activeKeys = $kUnused + $kInUse;
$activeRate = $totalKeys > 0 ? min(100, (int)round($activeKeys / $totalKeys * 100)) : 0;

// Keys created per day, last 7 days
$series = [];
foreach (db()->all("SELECT DATE(created_at) d, COUNT(*) c FROM keys_code
                    WHERE $scope AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                    GROUP BY DATE(created_at)", $sp) as $r) { $series[$r->d] = (int)$r->c; }
$days = []; $weekTotal = 0; $weekMax = 0;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day")); $c = $series[$d] ?? 0;
    $days[] = ['lbl' => date('D', strtotime($d)), 'c' => $c, 'today' => $i === 0];
    $weekTotal += $c; $weekMax = max($weekMax, $c);
}

$totalUsers = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE branch=?",[$br])->c ?? 0);
$totalRefs  = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=?",[$br])->c ?? 0);
$usedRefs   = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=? AND is_used=1",[$br])->c ?? 0);
$blockedIPs = (int)(db()->one("SELECT COUNT(*) c FROM blocked_ips WHERE expires_at IS NULL OR expires_at>NOW()")->c ?? 0);
// Owner: balance held by admins/resellers (the owner's own unlimited balance is excluded). Others: their own balance.
$balance = $own
    ? (float)(db()->one("SELECT balance b FROM users WHERE id=?",[(int)$_SESSION['uid']])->b ?? 0)
    : (float)(db()->one("SELECT COALESCE(SUM(balance),0) t FROM users WHERE branch=? AND level>1",[$br])->t ?? 0);

$recentKeys = db()->all("SELECT * FROM keys_code WHERE $scope ORDER BY created_at DESC LIMIT 7", $sp);
$recentLogs = $own
    ? db()->all("SELECT * FROM activity_logs WHERE username=? ORDER BY created_at DESC LIMIT 7", [$uname])
    : db()->all("SELECT * FROM activity_logs WHERE branch=? ORDER BY created_at DESC LIMIT 7", [$br]);

function keyStatus($key): array {
    if (!$key->status) return ['Blocked','b-ban'];
    if ($key->expired_date && strtotime($key->expired_date) < time()) return ['Expired','b-exp'];
    if (!$key->device_count) return ['Unused','b-unused'];
    return ['Active','b-active'];
}
function fmtDur(int $h): string { return $h < 24 ? "{$h}H" : round($h/24)."D"; }
function logIcon(string $a): string {
    $a = strtoupper($a);
    foreach (['LOGIN'=>'sign-in','GENERAT'=>'sparkle','BLOCK'=>'prohibit','BAN'=>'prohibit','DELETE'=>'trash','EXTEND'=>'clock-clockwise',
              'BALANCE'=>'wallet','USER'=>'user-plus','REF'=>'ticket','RESET'=>'arrow-counter-clockwise','UPDATE'=>'gear-six'] as $needle=>$ic) {
        if (strpos($a, $needle) !== false) return $ic;
    }
    return 'pulse';
}
function logLabel(string $a): string { return ucfirst(strtolower(str_replace('_',' ',$a))); }

$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$circ  = 326.73; // 2πr, r = 52
?>

<div class="dash-head">
  <div>
    <h1><?= $greet ?>, <?= htmlspecialchars($uname) ?></h1>
    <p><?= number_format($activeKeys) ?> active <?= $activeKeys===1?'key':'keys' ?><?php if($expiringSoon>0): ?> · <?= $expiringSoon ?> expiring within 3 days<?php endif; ?></p>
  </div>
  <div class="dash-acts">
    <a href="/keys.php" class="btn"><?= phIcon('key') ?> All keys</a>
    <a href="/generate.php" class="btn btn-ink"><?= phIcon('sparkle') ?> Generate key</a>
  </div>
</div>

<div class="dash-top">
  <!-- Key overview -->
  <section class="dcard">
    <div class="dcard-t"><h3>Key overview</h3><span><?= $own ? 'Your keys' : 'All keys' ?></span></div>
    <div class="ov">
      <div class="ring">
        <svg viewBox="0 0 132 132" aria-hidden="true">
          <defs><linearGradient id="ringG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#059669"/></linearGradient></defs>
          <circle class="trk" cx="66" cy="66" r="52"/>
          <circle class="val" cx="66" cy="66" r="52" stroke-dasharray="<?= $circ ?>" stroke-dashoffset="<?= round($circ * (1 - $activeRate/100), 2) ?>"/>
        </svg>
        <div class="ring-c"><b><?= $activeRate ?>%</b><small>active</small></div>
      </div>
      <div class="ov-r">
        <div class="ov-num count" id="statKeys"><?= number_format($totalKeys) ?></div>
        <div class="ov-lbl">Total keys</div>
        <div class="ov-row"><i style="background:var(--acc)"></i>In use<b><?= number_format($kInUse) ?></b></div>
        <div class="ov-row"><i style="background:#38a5d6"></i>Unused<b><?= number_format($kUnused) ?></b></div>
        <div class="ov-row"><i style="background:#d99a2b"></i>Expired<b><?= number_format($kExpired) ?></b></div>
        <div class="ov-row"><i style="background:#e0566b"></i>Blocked<b><?= number_format($kBlocked) ?></b></div>
      </div>
    </div>
  </section>

  <!-- 7-day chart -->
  <section class="dcard">
    <div class="dcard-t"><h3>Keys created</h3><span><?= number_format($weekTotal) ?> in 7 days</span></div>
    <div class="bars">
      <?php foreach ($days as $d): $h = $weekMax > 0 ? max(6, (int)round($d['c'] / $weekMax * 96)) : 6; ?>
      <div class="bar <?= $d['today'] ? 'today' : '' ?>">
        <em><?= $d['c'] ?></em>
        <i style="height:<?= $h ?>px"></i>
        <span><?= $d['today'] ? 'Today' : $d['lbl'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Highlights -->
  <div class="hl">
    <div class="hl-t hl-acc">
      <div class="hl-ic"><?= phIcon('sparkle') ?></div>
      <div><b class="count"><?= number_format($todayKeys) ?></b><span>Created today</span></div>
    </div>
    <div class="hl-t hl-ink">
      <div class="hl-ic"><?= phIcon('hourglass-medium') ?></div>
      <div><b class="count"><?= number_format($expiringSoon) ?></b><span>Expiring in 3 days</span></div>
    </div>
  </div>
</div>

<div class="mets">
  <div class="met">
    <div class="met-ic" style="--c:var(--violet-tx);--cb:var(--violet-bg)"><?= phIcon('users-three') ?></div>
    <div class="met-x"><b class="count" id="statUsers"><?= number_format($totalUsers) ?></b><span>Users</span></div>
  </div>
  <div class="met">
    <div class="met-ic" style="--c:var(--sky-tx);--cb:var(--sky-bg)"><?= phIcon('ticket') ?></div>
    <div class="met-x"><b class="count" id="statRefs"><?= number_format($totalRefs) ?></b><span>Referral codes · <?= $totalRefs - $usedRefs ?> open</span></div>
  </div>
  <div class="met">
    <div class="met-ic" style="--c:var(--amber-tx);--cb:var(--amber-bg)"><?= phIcon('wallet') ?></div>
    <div class="met-x"><b class="count" id="statBal"><?= number_format($balance, 0) ?></b><span><?= $own ? 'My balance' : 'Reseller balance' ?></span></div>
  </div>
  <div class="met">
    <div class="met-ic" style="--c:var(--rose-tx);--cb:var(--rose-bg)"><?= phIcon('shield-check') ?></div>
    <div class="met-x"><b class="count"><?= number_format($blockedIPs) ?></b><span>Blocked IPs</span></div>
  </div>
</div>

<div class="dash-bot">
  <!-- Recent keys -->
  <div class="card">
    <div class="card-h">
      <span class="ct"><?= phIcon('key') ?> Recent keys</span>
      <a href="/keys.php" class="btn btn-sm">View all <?= phIcon('arrow-right') ?></a>
    </div>
    <?php if (empty($recentKeys)): ?>
      <div class="empty">No keys yet — generate your first key.</div>
    <?php else: foreach ($recentKeys as $rk): [$st,$sc] = keyStatus($rk); ?>
      <div class="li">
        <div class="li-ic"><?= phIcon('key') ?></div>
        <div class="li-x">
          <b class="mono"><?= htmlspecialchars($rk->user_key) ?></b>
          <span><?= fmtDur((int)$rk->duration) ?> · <?= (int)$rk->device_count ?>/<?= (int)$rk->max_devices ?> devices<?= $own ? '' : ' · '.htmlspecialchars($rk->registrator) ?></span>
        </div>
        <span class="bx <?= $sc ?>"><?= $st ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Recent activity -->
  <div class="card">
    <div class="card-h">
      <span class="ct"><?= phIcon('pulse') ?> Recent activity</span>
      <?php if ($ulevel===1): ?><a href="/logs.php" class="btn btn-sm">View all <?= phIcon('arrow-right') ?></a><?php endif; ?>
    </div>
    <?php if (empty($recentLogs)): ?>
      <div class="empty">No activity yet.</div>
    <?php else: foreach ($recentLogs as $l): ?>
      <div class="li">
        <div class="li-ic"><?= phIcon(logIcon((string)$l->action)) ?></div>
        <div class="li-x">
          <b><?= htmlspecialchars(logLabel((string)$l->action)) ?></b>
          <span><?= htmlspecialchars($l->username ?? 'System') ?><?= !empty($l->detail) ? ' · '.htmlspecialchars($l->detail) : '' ?></span>
        </div>
        <span class="li-r"><?= date('d M, H:i', strtotime($l->created_at)) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
