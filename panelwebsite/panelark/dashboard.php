<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__.'/app/Views/Layout/header.php';

// ── Stats (all branch-scoped so hidden branches never leak into totals) ──
$br = currentBranch();
$totalKeys  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[$br])->c ?? 0);
$activeKeys = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND status=1 AND (expired_date IS NULL OR expired_date>NOW())",[$br])->c ?? 0);
$totalUsers = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE branch=?",[$br])->c ?? 0);
$totalRefs  = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=?",[$br])->c ?? 0);
$usedRefs   = (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=? AND is_used=1",[$br])->c ?? 0);
$blockedIPs = (int)(db()->one("SELECT COUNT(*) c FROM blocked_ips WHERE expires_at IS NULL OR expires_at>NOW()")->c ?? 0);
$todayKeys  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND DATE(created_at)=CURDATE()",[$br])->c ?? 0);
$totalBal   = (float)(db()->one("SELECT COALESCE(SUM(balance),0) t FROM users WHERE branch=?",[$br])->t ?? 0);
$expiringSoon = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND status=1 AND expired_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 3 DAY)",[$br])->c ?? 0);

if ($ulevel > 1) {
    $totalKeys  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=?",[$uname])->c ?? 0);
    $activeKeys = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=? AND status=1 AND (expired_date IS NULL OR expired_date>NOW())",[$uname])->c ?? 0);
}

// Recent keys (branch-scoped)
$recentKeys = ($ulevel===1)
    ? db()->all("SELECT * FROM keys_code WHERE branch=? ORDER BY created_at DESC LIMIT 8",[$br])
    : db()->all("SELECT * FROM keys_code WHERE registrator=? ORDER BY created_at DESC LIMIT 8",[$uname]);

// Recent logs (branch-scoped)
$recentLogs = db()->all("SELECT * FROM activity_logs WHERE branch=? ORDER BY created_at DESC LIMIT 6",[$br]);

// User breakdown (owner only, within branch)
$ownerCount    = $ulevel===1 ? (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=1 AND branch=?",[$br])->c??0) : 0;
$adminCount    = $ulevel===1 ? (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=2 AND branch=?",[$br])->c??0) : 0;
$resellerCount = $ulevel===1 ? (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=3 AND branch=?",[$br])->c??0) : 0;

function keyStatus($key): array {
    if (!$key->status) return ['Blocked','b-ban'];
    if ($key->expired_date && strtotime($key->expired_date) < time()) return ['Expired','b-exp'];
    if (!$key->device_count) return ['Unused','b-unused'];
    return ['Active','b-active'];
}
function fmtDur(int $h): string {
    if ($h < 24) return "{$h}H";
    return round($h/24)."D";
}

$activeRate = $totalKeys > 0 ? min(100, round($activeKeys/$totalKeys*100)) : 0;
?>

<!-- ── Greeting ── -->
<?php
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<div style="margin-bottom:24px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px">
  <div>
    <h1 style="font-size:23px;font-weight:800;letter-spacing:-.6px;margin-bottom:5px"><?= $greet ?>, <?= htmlspecialchars($uname) ?></h1>
    <div style="font-size:13.5px;color:var(--muted)">
      You have <?= number_format($activeKeys) ?> active <?= $activeKeys===1?'key':'keys' ?>.
      <?php if($expiringSoon>0): ?><?= $expiringSoon ?> <?= $expiringSoon===1?'key expires':'keys expire' ?> in the next 3 days.<?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:9px;flex-wrap:wrap">
    <a href="/keys.php" class="btn btn-ghost btn-sm"><?= ico('keys') ?> All keys</a>
    <a href="/generate.php" class="btn btn-solid btn-sm"><?= ico('generate') ?> Generate key</a>
  </div>
</div>

<!-- ── Bento stats: one hero + supporting tiles ── -->
<?php
// shared icon-tile svg attrs
$svgA = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
?>
<div class="bento">
  <div class="b-hero">
    <div class="bh-top">
      <span class="bh-ic"><svg <?= $svgA ?>><path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L21 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/></svg></span>
      <span class="bh-lbl">Total keys</span>
    </div>
    <div class="hero-v" id="statKeys"><?= number_format($totalKeys) ?></div>
    <div class="bh-sub"><b><?= number_format($activeKeys) ?> active</b> · <?= $activeRate ?>% active rate</div>
    <div class="hero-bar"><i style="width:<?= $activeRate ?>%"></i></div>
    <div class="bh-f"><?= $todayKeys ?> <?= $todayKeys===1?'key':'keys' ?> created today</div>
  </div>

  <div class="b">
    <div class="b-top">
      <span class="b-ic" style="--c:var(--lilac);--cb:var(--violet-bg)"><svg <?= $svgA ?>><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <span class="b-lbl">Total users</span>
    </div>
    <div class="b-v" id="statUsers"><?= number_format($totalUsers) ?></div>
    <div class="b-f"><?php if($ulevel===1): ?><?= $ownerCount ?> owner · <?= $adminCount ?> admin · <?= $resellerCount ?> reseller<?php else: ?>Registered accounts<?php endif; ?></div>
  </div>

  <div class="b">
    <div class="b-top">
      <span class="b-ic" style="--c:var(--cyan);--cb:var(--sky-bg)"><svg <?= $svgA ?>><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg></span>
      <span class="b-lbl">Referral codes</span>
    </div>
    <div class="b-v" id="statRefs"><?= number_format($totalRefs) ?></div>
    <div class="b-f"><?= $usedRefs ?> used · <?= $totalRefs-$usedRefs ?> available</div>
  </div>

  <?php if ($ulevel===1): ?>
  <div class="b">
    <div class="b-top">
      <span class="b-ic" style="--c:var(--gold);--cb:var(--amber-bg)"><svg <?= $svgA ?>><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 1 1 1v-2"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></svg></span>
      <span class="b-lbl">Total balance</span>
    </div>
    <div class="b-v" id="statBal"><?= number_format($totalBal, 0) ?></div>
    <div class="b-f">Across all accounts</div>
  </div>
  <?php else: ?>
  <div class="b">
    <div class="b-top">
      <span class="b-ic" style="--c:var(--gold);--cb:var(--amber-bg)"><svg <?= $svgA ?>><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg></span>
      <span class="b-lbl">Blocked IPs</span>
    </div>
    <div class="b-v"><?= number_format($blockedIPs) ?></div>
    <div class="b-f">Automatic protection</div>
  </div>
  <?php endif; ?>

  <div class="b">
    <div class="b-top">
      <span class="b-ic" style="--c:var(--coral);--cb:var(--rose-bg)"><svg <?= $svgA ?>><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg></span>
      <span class="b-lbl">Expiring soon</span>
    </div>
    <div class="b-v"><?= number_format($expiringSoon) ?></div>
    <div class="b-f">Within the next 3 days</div>
  </div>
</div>

<style>
.bento{
  display:grid;grid-template-columns:1.35fr 1fr 1fr;
  grid-template-rows:auto auto;gap:16px;margin-bottom:24px;
}
/* hero */
.b-hero{
  grid-row:span 2;padding:26px;border-radius:var(--r);
  background:linear-gradient(165deg,var(--ink2),var(--ink));
  border:none;box-shadow:var(--ink-sh);color:var(--ink-tx);
  position:relative;overflow:hidden;display:flex;flex-direction:column;
}
.b-hero::after{content:'';position:absolute;bottom:-45px;right:-35px;width:190px;height:190px;border-radius:50%;
  background:radial-gradient(circle,rgba(52,211,153,.22),transparent 70%);pointer-events:none}
.bh-top{display:flex;align-items:center;gap:12px;position:relative;z-index:1}
.bh-ic{width:44px;height:44px;border-radius:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  background:rgba(255,255,255,.08);box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
.bh-ic svg{width:22px;height:22px;stroke:#34d399;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.bh-lbl{font-size:14px;color:var(--ink-mut);font-weight:600}
.hero-v{font-size:52px;font-weight:800;letter-spacing:-2.4px;line-height:1;margin:20px 0 8px;font-variant-numeric:tabular-nums;color:#fff;position:relative;z-index:1}
.bh-sub{font-size:13px;color:var(--ink-mut);position:relative;z-index:1}
.bh-sub b{color:#6ee7b7;font-weight:700}
.hero-bar{height:9px;border-radius:40px;background:rgba(255,255,255,.1);box-shadow:inset 0 1px 3px rgba(0,0,0,.35);overflow:hidden;margin:22px 0 0;position:relative;z-index:1}
.hero-bar i{display:block;height:100%;border-radius:40px;background:linear-gradient(90deg,#34d399,#10b981);box-shadow:0 0 10px rgba(52,211,153,.5)}
.bh-f{font-size:12.5px;color:var(--ink-mut);margin-top:12px;position:relative;z-index:1}
/* stat card */
.b{
  background:var(--card-bg);border:1px solid var(--line);border-radius:var(--r);
  padding:22px;box-shadow:var(--raise);transition:transform .2s,box-shadow .2s;
  display:flex;flex-direction:column;
}
.b:hover{transform:translateY(-3px);box-shadow:var(--pop)}
.b-top{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.b-ic{width:44px;height:44px;border-radius:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  background:var(--cb);color:var(--c);box-shadow:var(--raise-sm)}
.b-ic svg{width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.b-lbl{font-size:13px;color:var(--muted);font-weight:600}
.b-v{font-size:30px;font-weight:800;letter-spacing:-1px;line-height:1;font-variant-numeric:tabular-nums;color:var(--text);
     white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.b-f{font-size:12.5px;color:var(--muted);margin-top:auto;padding-top:12px;font-weight:500}
@media(max-width:900px){
  .bento{grid-template-columns:1fr 1fr}
  .b-hero{grid-row:auto;grid-column:span 2}
  .hero-v{font-size:44px}
}
@media(max-width:520px){
  .bento{grid-template-columns:1fr}
  .b-hero{grid-column:auto}
}
</style>

<!-- ── Recent Keys + Activity ── -->
<div class="g2">

  <!-- Recent Keys -->
  <div class="card" style="margin-bottom:0">
    <div class="card-h">
      <span class="ct">
        <?= ico('keys') ?> Recent Keys
      </span>
      <?php if ($ulevel===1): ?>
      <a href="/keys.php" class="btn btn-ghost btn-sm">View All →</a>
      <?php endif; ?>
    </div>
    <div class="tw">
      <table>
        <thead>
          <tr>
            <th>Key</th>
            <th>Duration</th>
            <th>Devices</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentKeys)): ?>
          <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--muted)">No keys yet</td></tr>
          <?php else: foreach ($recentKeys as $k):
            [$st,$sc] = keyStatus($k);
          ?>
          <tr>
            <td>
              <code class="kcode" style="font-size:11px"><?= htmlspecialchars(substr($k->user_key,0,20)).'...' ?></code>
            </td>
            <td style="font-size:12px"><?= fmtDur((int)$k->duration) ?></td>
            <td style="font-size:12px"><?= (int)$k->device_count ?>/<?= (int)$k->max_devices ?></td>
            <td><span class="bx <?= $sc ?>"><?= $st ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card" style="margin-bottom:0">
    <div class="card-h">
      <span class="ct">
        <?= ico('logs') ?> Recent Activity
      </span>
      <?php if ($ulevel===1): ?>
      <a href="/logs.php" class="btn btn-ghost btn-sm">View All →</a>
      <?php endif; ?>
    </div>
    <div class="tw">
      <table>
        <thead>
          <tr><th>Action</th><th>User</th><th>Time</th></tr>
        </thead>
        <tbody>
          <?php if (empty($recentLogs)): ?>
          <tr><td colspan="3" style="text-align:center;padding:32px;color:var(--muted)">No activity yet</td></tr>
          <?php else: foreach ($recentLogs as $l): ?>
          <tr>
            <td>
              <span class="bx b-admin" style="font-size:9px;padding:3px 8px"><?= htmlspecialchars($l->action) ?></span>
            </td>
            <td style="font-size:12px;color:var(--text2)"><?= htmlspecialchars($l->username ?? 'System') ?></td>
            <td style="font-size:11px;color:var(--muted);"><?= date('d M H:i', strtotime($l->created_at)) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
