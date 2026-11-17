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

<?php
// level of each creator in one query (was one query per row)
$creatorLvl = [];
if ($refCodes) {
    $names = array_values(array_unique(array_map(fn($r) => $r->created_by, $refCodes)));
    $in = implode(',', array_fill(0, count($names), '?'));
    foreach (db()->all("SELECT username, level FROM users WHERE username IN ($in)", $names) as $cu) $creatorLvl[$cu->username] = (int)$cu->level;
}
$usedCnt = count(array_filter($refCodes, fn($r) => (int)$r->is_used === 1));
?>
<?php if($success): ?>
<div class="alert a-ok" style="justify-content:space-between;flex-wrap:wrap">
  <span><?= $success ?></span>
  <button onclick="copyText('<?= htmlspecialchars($code??'') ?>','Code')" class="btn btn-solid btn-sm"><?= phIcon('copy') ?> Copy code</button>
</div>
<?php endif; ?>
<?php if($error): ?>
<div class="alert a-err"><?= phIcon('prohibit') ?> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="strip">
  <div class="met"><div class="met-ic" style="--c:var(--sky-tx);--cb:var(--sky-bg)"><?= phIcon('ticket') ?></div><div class="met-x"><b><?= $totalRef ?></b><span>Codes</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--acc-tx);--cb:var(--acc-bg)"><?= phIcon('check-circle') ?></div><div class="met-x"><b><?= $totalRef - $usedCnt ?></b><span>Available</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--rose-tx);--cb:var(--rose-bg)"><?= phIcon('user-plus') ?></div><div class="met-x"><b><?= $usedCnt ?></b><span>Used</span></div></div>
  <?php if($ulevel>1): ?>
  <div class="met"><div class="met-ic" style="--c:var(--amber-tx);--cb:var(--amber-bg)"><?= phIcon('hourglass-medium') ?></div><div class="met-x"><b><?= $refLimitRem ?> / <?= (int)($myUser->referral_limit??0) ?></b><span>Your limit left</span></div></div>
  <?php endif; ?>
</div>

<div class="work">
  <!-- CODES -->
  <div class="card work-main" style="margin:0">
    <div class="card-h">
      <span class="ct"><?= phIcon('ticket') ?> Codes</span>
      <div class="sbar" style="flex:1 1 180px;max-width:300px">
        <?= phIcon('magnifying-glass') ?>
        <input aria-label="Search codes" placeholder="Search" value="<?= htmlspecialchars($q) ?>" onkeydown="if(event.key==='Enter')location.href='/referral.php?q='+encodeURIComponent(this.value)">
      </div>
      <?php if($ulevel===1): ?>
      <button class="btn btn-red btn-sm" onclick="confirmDo('Clear ALL referral history?',clearAll)"><?= phIcon('trash') ?> Clear all</button>
      <?php endif; ?>
    </div>
    <div id="refTable">
      <?php if(empty($refCodes)): ?>
      <div class="empty">No referral codes yet.</div>
      <?php else: foreach($refCodes as $rc):
        $lvl = (int)$rc->level; $lvlName = [1=>'Owner',2=>'Admin',3=>'Reseller'][$lvl] ?? 'Reseller';
        $lvlCls = [1=>'b-owner',2=>'b-admin',3=>'b-reseller'][$lvl] ?? 'b-reseller';
      ?>
      <div class="li" id="rr-<?= $rc->id ?>">
        <button type="button" class="btn icon-btn" onclick="copyText('<?= htmlspecialchars($rc->code, ENT_QUOTES) ?>','Code')" title="Copy code" aria-label="Copy code"><?= phIcon('copy') ?></button>
        <div class="li-x">
          <b class="mono" style="font-size:14px;letter-spacing:2px"><?= htmlspecialchars($rc->code) ?></b>
          <span>₹<?= number_format((float)$rc->saldo,2) ?> · <?= htmlspecialchars($rc->acc_expire) ?><?= ($lvl===2 && (int)($rc->referral_limit??0)>0) ? ' · '.(int)$rc->referral_limit.' referrals' : '' ?> · by <?= htmlspecialchars($rc->created_by) ?><?= $rc->used_by ? ' · used by '.htmlspecialchars($rc->used_by) : '' ?></span>
        </div>
        <span class="bx <?= $lvlCls ?>"><?= $lvlName ?></span>
        <span class="bx <?= $rc->is_used ? 'b-ban' : 'b-active' ?>"><?= $rc->is_used ? 'Used' : 'Available' ?></span>
        <?php if(!$rc->is_used): ?>
        <button type="button" class="btn btn-red icon-btn" onclick="confirmDo('Delete this code?',()=>delRef(<?= $rc->id ?>))" title="Delete code" aria-label="Delete code"><?= phIcon('trash') ?></button>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- NEW CODE -->
  <aside class="work-side">
    <form method="POST" id="refForm" class="gsec" style="margin:0">
      <?= csrfField() ?>
      <input type="hidden" name="gen_ref" value="1">
      <input type="hidden" name="custom_code" id="refCodeInput" value="">
      <div class="gsec-h"><span class="gnum"><?= phIcon('plus') ?></span><h3>New code</h3></div>

      <?php if($code): ?><div class="ref-pill" id="refPreview" style="width:100%;justify-content:center;margin-bottom:14px"><?= htmlspecialchars($code) ?></div><?php endif; ?>

      <?php if($ulevel===1): ?>
      <label class="glabel">Account level</label>
      <select name="acc_level" id="accLevel" data-ui="tiles" onchange="onLevelChange(this.value)">
        <option value="3" selected data-t="Reseller">Level 3 — Reseller</option>
        <option value="2" data-t="Admin">Level 2 — Admin</option>
        <option value="1" data-t="Owner">Level 1 — Owner</option>
      </select>
      <div class="fg" id="refLimitWrap" style="display:none;margin-top:14px">
        <label class="glabel" for="refLimitInput">Codes this admin can create</label>
        <input type="number" name="ref_limit" id="refLimitInput" data-ui="stepper" value="5" min="0" step="1">
      </div>
      <?php elseif($ulevel===2): ?>
      <input type="hidden" name="acc_level" value="3">
      <?php endif; ?>

      <label class="glabel" style="margin-top:14px">Account valid for</label>
      <select name="acc_expire" data-ui="tiles">
        <?php foreach($expireOptions as $o): ?>
        <option<?= $o==='90 Days' ? ' selected' : '' ?>><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
      </select>

      <label class="glabel" for="saldoInp" style="margin-top:14px">Starting balance ($)</label>
      <input class="fi" type="number" name="saldo" id="saldoInp" value="10000" min="0" step="0.01" inputmode="decimal">

      <button type="submit" class="btn btn-solid btn-full" style="margin-top:16px"><?= phIcon('sparkle') ?> Generate code</button>
    </form>
  </aside>
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
