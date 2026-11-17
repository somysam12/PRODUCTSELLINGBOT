<?php
// ── AUTH + POST HANDLING (before any HTML → enables PRG redirect) ──
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
$ulevel = (int)($_SESSION['ulevel'] ?? 3);
$uname  = $_SESSION['uname'] ?? 'User';

$oneDevMode = getSetting('one_device_mode','0') === '1';
$maxCap     = max(1,(int)getSetting('max_devices_cap','10'));
$keyPrefix  = getSetting('key_prefix','HB');
$customKeyLevel = (int)getSetting('custom_key_level','1'); // which roles may set a custom key (1=owner only)

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $error = ''; $success = [];
    if (!verifyCsrf($_POST['csrf']??'')) { $error='Invalid token.'; }
    else {
        $duration  = trim($_POST['duration']??'30 Days');
        $maxDev    = $oneDevMode ? 1 : min($maxCap, max(1,(int)($_POST['max_devices']??1)));
        $qty       = max(1,min(10,(int)($_POST['qty']??1))); // max 10
        $customKey = trim($_POST['custom_key']??'');
        $isCustom  = !empty($_POST['is_custom']) && $customKey!=='' && $qty===1 && $ulevel <= $customKeyLevel;

        $priceRow = db()->one("SELECT hours,price FROM prices WHERE duration=?",[$duration]);
        if (!$priceRow) { $error='Invalid duration.'; }
        else {
            $hours  = (int)$priceRow->hours;
            $cost   = (float)$priceRow->price * $maxDev * $qty;
            $expiry = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

            // Balance check for non-owner
            if ($ulevel > 1) {
                $bal = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$_SESSION['uid']])->balance??0);
                if ($bal < $cost) { $error="Insufficient balance. Need \${$cost}, have \${$bal}."; }
            }

            if (!$error) {
                for ($i=0; $i<$qty; $i++) {
                    $key = ($isCustom && $i===0) ? $customKey : generateKey($keyPrefix);
                    while(db()->one("SELECT id FROM keys_code WHERE user_key=?",[$key])) $key=generateKey($keyPrefix);
                    db()->q("INSERT INTO keys_code(user_key,game,duration,max_devices,status,registrator,expired_date,branch) VALUES(?,?,?,?,1,?,?,?)",
                        [$key,'PUBG',$hours,$maxDev,$uname,$expiry,currentBranch()]);
                    $success[] = $key;
                }
                if ($ulevel > 1) db()->q("UPDATE users SET balance=balance-? WHERE id=?",[$cost,$_SESSION['uid']]);
                logAct($_SESSION['uid'],$uname,'GENERATE_KEY',count($success)." keys Dur:{$duration} Dev:{$maxDev}");
            }
        }
    }
    // PRG: stash result in session flash and redirect (GET) so refresh/resume never re-POSTs
    $_SESSION['gen_flash'] = ['success'=>$success,'error'=>$error];
    header('Location: /generate.php'); exit;
}

// GET: pull one-time flash result (survives the redirect, cleared after read)
$success = []; $error = '';
if (!empty($_SESSION['gen_flash'])) {
    $success = $_SESSION['gen_flash']['success'] ?? [];
    $error   = $_SESSION['gen_flash']['error']   ?? '';
    unset($_SESSION['gen_flash']);
}

$pageTitle = 'Generate Key';
$activeNav = 'generate';
require_once __DIR__.'/app/Views/Layout/header.php';

$keyFormat = getSetting('key_format','classic');
$prices    = db()->all("SELECT * FROM prices ORDER BY hours ASC");
$priceMap  = [];
foreach ($prices as $pr) $priceMap[$pr->duration] = $pr->price;
$myBal     = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$_SESSION['uid']])->balance??0);
$genHistory= db()->all("SELECT * FROM keys_code WHERE registrator=? ORDER BY created_at DESC LIMIT 8",[$uname]);
?>

<?php if($error): ?><div class="alert a-err"><?= phIcon('prohibit') ?> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if(!empty($success)): ?>
<section class="gsec" style="border-color:var(--acc-20)">
  <div class="gsec-h">
    <span class="gnum"><?= phIcon('check') ?></span>
    <h3><?= count($success) ?> key<?= count($success)>1?'s':'' ?> ready</h3>
    <button class="btn btn-sm" onclick="downloadKeys()"><?= phIcon('download-simple') ?> Download</button>
    <button class="btn btn-solid btn-sm" onclick="copyAllKeys()"><?= phIcon('copy') ?> Copy all</button>
  </div>
  <div id="generatedKeys" class="code-box" style="white-space:pre-line;font-size:13px"><?= implode("\n",array_map('htmlspecialchars',$success)) ?></div>
</section>
<?php endif; ?>

<div class="work">
  <form method="POST" id="genForm" class="work-main">
    <?= csrfField() ?>

    <section class="gsec">
      <div class="gsec-h"><span class="gnum">1</span><h3>Duration</h3></div>
      <select name="duration" id="durationSel" data-ui="tiles" onchange="updateEst()">
        <?php foreach($prices as $pr): ?>
        <option value="<?= htmlspecialchars($pr->duration) ?>" data-price="<?= $pr->price ?>" data-t="<?= htmlspecialchars($pr->duration) ?>" data-s="$<?= $pr->price ?> / device"><?= htmlspecialchars($pr->duration) ?> — $<?= $pr->price ?>/device</option>
        <?php endforeach; ?>
      </select>
    </section>

    <section class="gsec">
      <div class="gsec-h"><span class="gnum">2</span><h3>Devices<?= $ulevel<=2 ? ' &amp; quantity' : '' ?></h3><?php if($oneDevMode): ?><span class="bx b-exp">One-device mode</span><?php endif; ?></div>
      <div class="gpair">
        <div>
          <label class="glabel" for="maxDevices">Devices per key<?= $oneDevMode ? '' : ' (max '.$maxCap.')' ?></label>
          <input type="number" name="max_devices" id="maxDevices" data-ui="stepper" value="1" min="1" max="<?= $oneDevMode?'1':$maxCap ?>" <?= $oneDevMode?'readonly':'' ?> oninput="updateEst()">
        </div>
        <?php if($ulevel<=2): ?>
        <div>
          <label class="glabel" for="qtyInp">Number of keys (max 10)</label>
          <input type="number" name="qty" id="qtyInp" data-ui="stepper" value="1" min="1" max="10" oninput="updateEst()">
        </div>
        <?php else: ?>
        <input type="hidden" name="qty" id="qtyInp" value="1">
        <?php endif; ?>
      </div>
    </section>

    <?php if($ulevel <= $customKeyLevel): ?>
    <section class="gsec">
      <div class="gsec-h">
        <span class="gnum">3</span><h3>Custom key</h3><small>single key only</small>
        <label class="tog"><input type="checkbox" id="custTog" name="is_custom" value="1" onchange="toggleCustom()"><span class="togsl"></span></label>
      </div>
      <div id="custWrap" style="display:none">
        <input class="fi fi-mono" type="text" name="custom_key" aria-label="Custom key" autocomplete="off" spellcheck="false">
      </div>
    </section>
    <?php endif; ?>
  </form>

  <aside class="work-side">
    <div class="receipt">
      <div class="rc-t">Summary</div>
      <div class="rc-row"><span>Duration</span><b id="rcDur">—</b></div>
      <div class="rc-row"><span>Devices per key</span><b id="rcDev">1</b></div>
      <div class="rc-row"><span>Keys</span><b id="rcQty">1</b></div>
      <div class="rc-total"><span>Total</span><b id="rcTotal">$0.00</b></div>
      <input type="hidden" id="estimation">
      <button type="submit" form="genForm" class="btn btn-solid btn-full"><?= phIcon('sparkle') ?> Generate <span id="rcBtnN">key</span></button>
      <?php if($ulevel>1): ?><div class="rc-note"><span>Your balance</span><b>$<?= number_format($myBal,2) ?></b></div><?php endif; ?>
    </div>

    <div class="card" style="margin:0">
      <div class="card-h">
        <span class="ct"><?= phIcon('clock') ?> Recent</span>
        <a href="/keys.php" class="btn btn-sm">All keys <?= phIcon('arrow-right') ?></a>
      </div>
      <?php if(empty($genHistory)): ?>
        <div class="empty">No keys generated yet.</div>
      <?php else: foreach($genHistory as $h):
        $exp = $h->expired_date && strtotime($h->expired_date) < time();
        $hh  = (int)$h->duration;
        if(!$h->status) { $st='Blocked'; $sc='b-ban'; } elseif($exp) { $st='Expired'; $sc='b-exp'; }
        elseif(!$h->device_count) { $st='Unused'; $sc='b-unused'; } else { $st='Active'; $sc='b-active'; }
      ?>
        <div class="li">
          <button type="button" class="btn icon-btn" onclick="copyText('<?= htmlspecialchars($h->user_key, ENT_QUOTES) ?>','Key')" title="Copy key" aria-label="Copy key"><?= phIcon('copy') ?></button>
          <div class="li-x">
            <b class="mono"><?= htmlspecialchars($h->user_key) ?></b>
            <span><?= $hh<24 ? "{$hh}H" : round($hh/24)."D" ?> · <?= (int)$h->device_count ?>/<?= (int)$h->max_devices ?> devices</span>
          </div>
          <span class="bx <?= $sc ?>"><?= $st ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </aside>
</div>

<script>
const priceMap = <?= json_encode($priceMap) ?>;
function updateEst(){
  const dev = parseInt(document.getElementById('maxDevices').value)||1;
  const qty = parseInt(document.getElementById('qtyInp').value)||1;
  const dur = document.getElementById('durationSel').value;
  const unit= parseFloat(priceMap[dur]||0);
  const total = (dev*qty*unit).toFixed(2);
  document.getElementById('estimation').value = '$ '+total;
  document.getElementById('rcDur').textContent = dur;
  document.getElementById('rcDev').textContent = dev;
  document.getElementById('rcQty').textContent = qty;
  document.getElementById('rcTotal').textContent = '$'+total;
  document.getElementById('rcBtnN').textContent = qty>1 ? qty+' keys' : 'key';
}
updateEst();
function toggleCustom(){
  const t=document.getElementById('custTog'); if(!t) return;
  const on=t.checked;
  document.getElementById('custWrap').style.display=on?'block':'none';
  if(on) document.getElementById('qtyInp').value=1;
  updateEst();
}
function copyAllKeys(){
  const el=document.getElementById('generatedKeys');
  if(!el) return;
  copyText(el.textContent.trim(),'All keys');
}
// auto-copy freshly generated keys
(function(){ const el=document.getElementById('generatedKeys'); if(el){ try{ navigator.clipboard.writeText(el.textContent.trim()).then(()=>{ if(window.toast) toast('Keys auto-copied!','s'); }); }catch(e){} } })();
function downloadKeys(){
  const el=document.getElementById('generatedKeys');
  if(!el) return;
  const b=new Blob([el.textContent.trim()+'\n'],{type:'text/plain'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(b);
  a.download='keys-<?= date('Y-m-d') ?>.txt';
  a.click();
}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
