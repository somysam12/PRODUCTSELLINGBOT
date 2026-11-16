<?php
// ── AUTH + POST HANDLING (must run BEFORE any HTML output) ──
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
$ulevel = (int)($_SESSION['ulevel'] ?? 3);
$uname  = $_SESSION['uname'] ?? 'User';

// Resellers cannot access referral codes (owner + admin only)
if ($ulevel > 2) { header('Location: /dashboard.php'); exit; }

$success=''; $error='';

// Handle delete AJAX (JSON, before output)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $rid = (int)($_POST['rid']??0);
    if ($_POST['action']==='delete') {
        $whereOwn = $ulevel>1 ? " AND created_by='".addslashes($uname)."'" : '';
        db()->q("DELETE FROM referral_codes WHERE id=?{$whereOwn}",[$rid]);
        logAct($_SESSION['uid'],$uname,'DELETE_REF',"Ref ID: $rid");
        echo json_encode(['ok'=>true,'msg'=>'Deleted.']); exit;
    }
    if ($_POST['action']==='clear_all' && $ulevel===1) {
        db()->q("DELETE FROM referral_codes WHERE is_used=0");
        logAct($_SESSION['uid'],$uname,'CLEAR_REF','All unused codes');
        echo json_encode(['ok'=>true,'msg'=>'Cleared.']); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown']); exit;
}

// Generate referral → PRG redirect on success (prevents duplicate-resubmit)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['gen_ref'])) {
    if (!verifyCsrf($_POST['csrf']??'')) { header('Location: /referral.php?err=token'); exit; }
    $myUser = db()->one("SELECT referral_limit,referrals_used FROM users WHERE id=?",[$_SESSION['uid']]);
    $rem = (int)($myUser->referral_limit??0) - (int)($myUser->referrals_used??0);
    if ($ulevel > 1 && $rem <= 0) {
        header('Location: /referral.php?err=limit'); exit;
    }
    // Custom code optional; otherwise always fresh & unique (generateRefCode checks DB)
    $custom  = strtoupper(preg_replace('/[^A-Z0-9]/','',$_POST['custom_code']??''));
    $saldo   = max(0,(float)($_POST['saldo']??10000));
    $expire  = trim($_POST['acc_expire']??'90 Days');
    $level   = (int)($_POST['acc_level']??3);
    if ($ulevel===2) $level=3;  // admin can only create resellers

    // Referral allowance granted to the new account. Only meaningful for admins
    // (resellers can't issue codes at all), so force it to 0 for every other level.
    $refLimit = ($level===2) ? max(0,(int)($_POST['ref_limit']??0)) : 0;

    if ($custom) {
        $code = $custom;
        if (db()->one("SELECT id FROM referral_codes WHERE code=?",[$code])) {
            header('Location: /referral.php?err=dup&c='.urlencode($code)); exit;
        }
    } else {
        $code = generateRefCode(6);  // guaranteed unique
    }
    db()->q("INSERT INTO referral_codes(code,created_by,level,referral_limit,saldo,acc_expire,branch) VALUES(?,?,?,?,?,?,?)",
        [$code,$uname,$level,$refLimit,$saldo,$expire,currentBranch()]);
    db()->q("UPDATE users SET referrals_used=referrals_used+1 WHERE id=?",[$_SESSION['uid']]);
    tgReferralAlert($uname,$code,$saldo,$level,(int)($myUser->referral_limit??0),currentBranch());
    logAct($_SESSION['uid'],$uname,'GEN_REF',"Code:{$code} Level:{$level}".($refLimit?" RefLimit:{$refLimit}":''));
    header('Location: /referral.php?ok='.urlencode($code)); exit;
}

// Read PRG result from query string
$code = '';
if (!empty($_GET['ok'])) {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','',$_GET['ok']));
    $success = "Referral code <strong>{$code}</strong> created!";
} elseif (!empty($_GET['err'])) {
    $e = $_GET['err'];
    if ($e==='token')      $error = 'Invalid token. Please try again.';
    elseif ($e==='limit')  $error = 'Referral limit reached. Contact your admin.';
    elseif ($e==='dup')    $error = "Code '".preg_replace('/[^A-Za-z0-9]/','',$_GET['c']??'')."' already exists.";
}

// ── PAGE RENDER ──────────────────────────────────────────
$pageTitle = 'Referral Codes';
$activeNav = 'referral';
require_once __DIR__.'/app/Views/Layout/header.php';

$myUser = db()->one("SELECT referral_limit,referrals_used FROM users WHERE id=?",[$_SESSION['uid']]);
$refLimitRem = (int)($myUser->referral_limit??0) - (int)($myUser->referrals_used??0);

$whereOwn = $ulevel>1 ? "WHERE created_by='".addslashes($uname)."'" : 'WHERE 1=1';
$whereOwn .= " AND branch='".addslashes(currentBranch())."'";  // branch isolation
$q = trim($_GET['q']??'');
$params = [];
if($q){ $whereOwn.=" AND (code LIKE ? OR used_by LIKE ? OR created_by LIKE ?)"; $params=["%$q%","%$q%","%$q%"]; }
$refCodes = db()->all("SELECT * FROM referral_codes $whereOwn ORDER BY created_at DESC LIMIT 100",$params);
$totalRef = count($refCodes);

$expireOptions = ['1 Week','15 Days','30 Days','60 Days','90 Days','180 Days','1 Year'];
$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$initCode = ''; // Generated fresh on button click
?>

<?php if($success): ?>
<div class="alert a-ok" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <span><?= $success ?></span>
  <button onclick="copyText('<?= htmlspecialchars($code??'') ?>','Code')" class="btn btn-acc btn-sm">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
    Copy Code
  </button>
</div>
<?php endif; ?>
<?php if($error): ?>
<div class="alert a-err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="g-left">
  <!-- GENERATE FORM -->
  <div class="card" style="height:fit-content">
    <div class="card-h"><span class="ct"><?=svgIcon('link',14)?> Generate Referral Code</span></div>
    <div class="card-b">
      <!-- Limit widget -->
      <?php if($ulevel>1): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:15px 17px;background:var(--acc-08);border:1px solid var(--acc-20);border-radius:var(--r2);margin-bottom:18px">
        <div>
          <div style="font-size:12.5px;color:var(--muted);margin-bottom:4px">Your limit</div>
          <div style="font-size:23px;font-weight:600;letter-spacing:-.6px;color:<?= $refLimitRem>0?'var(--acc)':'var(--coral)' ?>">
            <?= $refLimitRem ?> <span style="font-size:12.5px;font-weight:400;color:var(--muted)">remaining</span>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-size:12.5px;color:var(--muted);margin-bottom:4px">Total assigned</div>
          <div style="font-size:16px;font-weight:600;color:var(--text)"><?= (int)($myUser->referral_limit??0) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <form method="POST" id="refForm">
        <?= csrfField() ?>
        <input type="hidden" name="gen_ref" value="1">

        <div class="fg">
          <label class="fl">Generated Code <span style="color:var(--muted);font-weight:400">(auto)</span></label>
          <div style="display:flex;gap:8px;align-items:center">
            <div class="ref-pill" id="refPreview" style="color:<?= $code ? 'var(--acc)' : 'var(--muted)' ?>;letter-spacing:2px"><?= $code ?: 'AUTO' ?></div>
            <!-- left empty so every submit generates a fresh unique code server-side -->
            <input type="hidden" name="custom_code" id="refCodeInput" value="">
          </div>
          <div class="fi-hint">A fresh unique 6-character code is generated on each click.</div>
        </div>

        <div class="fg">
          <label class="fl">Starting Balance (Saldo)</label>
          <div style="position:relative">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);">$</span>
            <input class="fi" type="number" name="saldo" value="10000" min="0" step="0.01" style="padding-left:26px">
          </div>
        </div>

        <div class="fg">
          <label class="fl">Account Expiration</label>
          <select class="fi" name="acc_expire">
            <?php foreach($expireOptions as $o): ?>
            <option<?= $o==='90 Days' ? ' selected' : '' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="fi-hint">How long the new account stays active after registering.</div>
        </div>

        <?php if($ulevel===1): ?>
        <div class="fg">
          <label class="fl">Account Level</label>
          <select class="fi" name="acc_level" id="accLevel" onchange="onLevelChange(this.value)">
            <option value="1">Level 1 — Owner</option>
            <option value="2">Level 2 — Admin</option>
            <option value="3" selected>Level 3 — Reseller</option>
          </select>
        </div>

        <!-- Admin-only: how many referral codes the new admin may issue -->
        <div class="fg" id="refLimitWrap" style="display:none">
          <label class="fl">Referral Limit for this Admin</label>
          <input class="fi" type="number" name="ref_limit" id="refLimitInput" value="5" min="0" step="1">
          <div class="fi-hint">Number of referral codes this admin can create. Use <strong>0</strong> for none.</div>
        </div>
        <?php elseif($ulevel===2): // Admin can only create reseller ?>
        <input type="hidden" name="acc_level" value="3">
        <?php endif; ?>

        <button type="submit" class="btn btn-acc btn-full"><?=svgIcon('zap',14)?> Generate code</button>
      </form>
    </div>
  </div>

  <!-- CODE HISTORY -->
  <div class="card">
    <div class="card-h">
      <span class="ct"><?=svgIcon('key',14)?> Code History <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?= $totalRef ?>)</span></span>
      <?php if($ulevel===1): ?>
      <button class="btn btn-red btn-sm" onclick="confirmDo('Clear ALL referral history?',clearAll)"><?=svgIcon('trash',12)?> Clear All</button>
      <?php endif; ?>
    </div>
    <div class="card-b" style="padding:12px">
      <div class="sbar" style="margin-bottom:12px">
        <span class="sbar-i"><?=svgIcon('link',13)?></span>
        <input placeholder="Search codes, used by..." value="<?= htmlspecialchars($q) ?>" onkeydown="if(event.key==='Enter')location.href='/referral.php?q='+encodeURIComponent(this.value)">
      </div>
    </div>
    <div class="tw">
      <table id="refTable">
        <thead><tr>
          <th>#</th><th>Code</th><th>Saldo</th><th>Level</th><th>Expiry</th><th>Used</th><th>Used By</th><th>Created By</th><th></th>
        </tr></thead>
        <tbody>
          <?php if(empty($refCodes)): ?>
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted)">No referral codes yet</td></tr>
          <?php else: $i=1; foreach($refCodes as $rc): ?>
          <tr id="rr-<?= $rc->id ?>">
            <td style="color:var(--muted)"><?= $i++ ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="background:var(--acc-08);border:1px solid var(--acc-20);border-radius:var(--r4);padding:4px 11px;font-size:13px;color:var(--acc);letter-spacing:1px;font-weight:600"><?= htmlspecialchars($rc->code) ?></div>
                <button class="btn btn-ghost btn-xs" onclick="copyText('<?= htmlspecialchars($rc->code) ?>','Code')" title="Copy">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
              </div>
            </td>
            <td style="color:var(--acc);font-weight:700">$<?= number_format((float)$rc->saldo,2) ?></td>
            <td>
              <?php if($rc->level==1): ?><span class="bx b-owner">Owner</span>
              <?php elseif($rc->level==2): ?>
                <span class="bx b-admin">Admin</span>
                <?php if((int)($rc->referral_limit??0) > 0): ?>
                <div style="font-size:11px;color:var(--muted);margin-top:4px"><?= (int)$rc->referral_limit ?> referrals</div>
                <?php endif; ?>
              <?php else: ?><span class="bx b-reseller">Reseller</span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px;color:var(--muted2)"><?= htmlspecialchars($rc->acc_expire) ?></td>
            <td>
              <?php if($rc->is_used): ?>
                <span class="bx b-ban" style="font-size:9px">Used</span>
              <?php else: ?>
                <span class="bx b-active" style="font-size:9px">Available</span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px"><?= $rc->used_by ? '<code style="font-size:10px">'.htmlspecialchars($rc->used_by).'</code>' : '<span style="color:var(--muted)">—</span>' ?></td>
            <td>
              <?php $cl = ['1'=>'b-owner','2'=>'b-admin','3'=>'b-reseller'][db()->one("SELECT level FROM users WHERE username=?",[$rc->created_by])->level??'3']??'b-reseller'; ?>
              <span class="bx <?= $cl ?>" style="font-size:9px"><?= htmlspecialchars($rc->created_by) ?></span>
            </td>
            <td>
              <?php if(!$rc->is_used): ?>
              <button class="btn btn-red btn-xs" onclick="confirmDo('Delete this code?',()=>delRef(<?= $rc->id ?>))"><?=svgIcon('trash',12)?></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
async function delRef(id){
  const r = await formPost('/referral.php',{action:'delete',rid:id,csrf});
  if(r.ok){ document.getElementById('rr-'+id)?.remove(); toast(r.msg,'s'); }
  else toast(r.msg,'e');
}
async function clearAll(){
  const r = await formPost('/referral.php',{action:'clear_all',csrf});
  if(r.ok){ location.reload(); }
  else toast(r.msg,'e');
}
</script>
<script>
// Referral limit only applies to admins — resellers can't issue codes,
// and owners already have an unlimited allowance.
function onLevelChange(val){
  const wrap = document.getElementById('refLimitWrap');
  if(wrap) wrap.style.display = (val === '2') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('accLevel');
  if(sel) onLevelChange(sel.value);   // respect the level restored on back-nav
});

function doGenerate(){
  const chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let code='';
  for(let i=0;i<6;i++) code+=chars[Math.floor(Math.random()*chars.length)];
  document.getElementById('refCodeInput').value=code;
  document.getElementById('refForm').submit();
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
