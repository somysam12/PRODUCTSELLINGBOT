<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── ALL AJAX HANDLERS (before HTML) ──────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    if ($ulv !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    $sec = $_POST['section'] ?? '';

    if ($sec === 'maintenance') {
        $on  = ($_POST['maintenance_mode']??'0')==='1'?'1':'0';
        setSetting('maintenance_mode',$on);
        setSetting('maint_message', trim($_POST['maint_message']??''));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','MAINTENANCE_'.($on?'ON':'OFF'),'');
        echo json_encode(['ok'=>true,'msg'=>'Maintenance '.($on?'ENABLED':'DISABLED').' successfully!','status'=>$on]);
        exit;
    }
    if ($sec === 'server') {
        setSetting('server_url', trim($_POST['server_url']??''));
        setSetting('apk_version', trim($_POST['apk_version']??''));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_SERVER_SETTINGS','');
        echo json_encode(['ok'=>true,'msg'=>'Server settings saved!']);
        exit;
    }
    if ($sec === 'base') {
        $b = trim($_POST['base_url']??'');
        if ($b !== '' && !preg_match('#^https?://[^\s/]+#i', $b)) {
            echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
        }
        setSetting('panel_base_url', rtrim($b,'/'));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_BASE_URL',$b?:'(default)');
        echo json_encode(['ok'=>true,'msg'=>$b?"Base domain set to ".rtrim($b,'/'):'Base domain reset to default.']);
        exit;
    }
    if ($sec === 'ddos') {
        setSetting('ddos_protection', ($_POST['ddos_protection']??'0')==='1'?'1':'0');
        setSetting('max_login_attempts', max(3,(int)($_POST['max_login_attempts']??5)));
        setSetting('lockout_minutes', max(1,(int)($_POST['lockout_minutes']??15)));
        echo json_encode(['ok'=>true,'msg'=>'DDoS settings saved!']);
        exit;
    }
    if ($sec === 'license') {
        $licKey = strtoupper(trim($_POST['license_key']??''));
        if (empty($licKey)) { echo json_encode(['ok'=>false,'msg'=>'Enter license key']); exit; }
        $ex = db()->one("SELECT id FROM panel_settings WHERE skey='panel_license_key'");
        if ($ex) db()->q("UPDATE panel_settings SET svalue=? WHERE skey='panel_license_key'",[$licKey]);
        else     db()->q("INSERT INTO panel_settings(skey,svalue) VALUES('panel_license_key',?)",[$licKey]);
        db()->q("UPDATE panel_settings SET svalue='' WHERE skey='lic_cache'");
        db()->q("UPDATE panel_settings SET svalue='0' WHERE skey='lic_cache_time'");
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_LICENSE_KEY','');
        echo json_encode(['ok'=>true,'msg'=>'License key saved!']);
        exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown section']); exit;
}

// ── PAGE ──────────────────────────────────────
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Server & Maintenance';
$activeNav = 'panelset';
require_once __DIR__.'/app/Views/Layout/header.php';

$maintMode    = getSetting('maintenance_mode','0')==='1';
$maintMsg     = getSetting('maint_message','Server is under maintenance. Please try again later.');
$serverUrl    = getSetting('server_url','');
$panelBaseSet = getSetting('panel_base_url','');
$apkVer       = getSetting('apk_version','1.0.0');
$ddosProt     = getSetting('ddos_protection','1')==='1';
$maxAttempts  = getSetting('max_login_attempts','5');
$lockoutMins  = getSetting('lockout_minutes','15');
$curLicKey    = getSetting('panel_license_key','');
$licValid     = null; $licDaysLeft = 0; $licExpiry = '';
if (!empty($curLicKey)) {
    $cached = getSetting('lic_cache','');
    if ($cached) {
        $licData = json_decode($cached,true);
        $licValid    = $licData['valid'] ?? null;
        $licDaysLeft = $licData['days_left'] ?? 0;
        $licExpiry   = $licData['expires_at'] ?? '';
    }
}

// User counts
$ownerCount    = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=1 AND branch=?",[currentBranch()])->c??0);
$adminCount    = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=2 AND branch=?",[currentBranch()])->c??0);
$resellerCount = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level=3 AND branch=?",[currentBranch()])->c??0);

$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="strip">
  <div class="met"><div class="met-ic" style="--c:var(--amber-tx);--cb:var(--amber-bg)"><?= phIcon('crown') ?></div><div class="met-x"><b><?=$ownerCount?></b><span>Owners</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--blue-tx);--cb:var(--blue-bg)"><?= phIcon('identification-badge') ?></div><div class="met-x"><b><?=$adminCount?></b><span>Admins</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--violet-tx);--cb:var(--violet-bg)"><?= phIcon('storefront') ?></div><div class="met-x"><b><?=$resellerCount?></b><span>Resellers</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--acc-tx);--cb:var(--acc-bg)"><?= phIcon('android') ?></div><div class="met-x"><b><?=htmlspecialchars($apkVer)?></b><span>APK version</span></div></div>
</div>

<!-- Maintenance -->
<section class="gsec">
  <div class="gsec-h">
    <span class="gnum"><?= phIcon('build') ?></span>
    <h3>Maintenance mode</h3>
    <span id="maintBadge" class="<?=$maintMode?'pill p-warn':'pill p-on'?>"><span class="dot"></span><?=$maintMode?'ON':'OFF'?></span>
  </div>
  <div class="work" style="grid-template-columns:minmax(0,1fr) 260px;gap:14px">
    <div>
      <label class="glabel" for="maintMsg">Message shown to devices</label>
      <textarea class="fi" id="maintMsg" rows="3" style="min-height:0"><?=htmlspecialchars($maintMsg)?></textarea>
    </div>
    <div style="display:grid;gap:10px;align-content:end">
      <button class="btn btn-warn" onclick="saveMaint(1)"><?= phIcon('power') ?> Turn on</button>
      <button class="btn btn-solid" onclick="saveMaint(0)"><?= phIcon('check-circle') ?> Turn off</button>
    </div>
  </div>
</section>

<div class="g2" style="align-items:start">
  <!-- Server -->
  <section class="gsec" style="margin:0">
    <div class="gsec-h"><span class="gnum"><?= phIcon('hard-drives') ?></span><h3>Server</h3></div>

    <label class="glabel" for="baseUrl">Base domain</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
      <input class="fi fi-mono" id="baseUrl" style="flex:1;min-width:180px" value="<?=htmlspecialchars($panelBaseSet)?>" placeholder="<?=htmlspecialchars(rtrim(PANEL_URL,'/'))?>">
      <button class="btn" onclick="saveBase()">Save</button>
    </div>
    <div class="rowi" style="margin-bottom:16px"><div class="rowi-ic"><?= phIcon('globe') ?></div><div class="rowi-x"><b>Active base</b><code><?=htmlspecialchars(panelBase())?></code></div></div>

    <?php if(empty($serverUrl)): ?>
    <label class="glabel" for="serverUrl">APK connect URL (locks after the first save)</label>
    <input class="fi fi-mono" id="serverUrl" placeholder="https://yourdomain.com/server/" oninput="updatePreview(this.value)" style="margin-bottom:6px">
    <div class="fi-hint" id="urlPreview" style="margin:0 0 12px;word-break:break-all"></div>
    <label class="glabel" for="apkVer">APK version</label>
    <input class="fi fi-mono" id="apkVer" value="<?=htmlspecialchars($apkVer)?>" style="margin-bottom:14px">
    <button class="btn btn-solid btn-full" onclick="saveServer()"><?= phIcon('lock') ?> Save &amp; lock URL</button>
    <?php else: ?>
    <label class="glabel">APK / lib connect URL</label>
    <div class="rowi" id="urlLockedView" style="margin-bottom:12px">
      <div class="rowi-ic"><?= phIcon('link') ?></div>
      <div class="rowi-x"><code style="font-size:12.5px"><?=htmlspecialchars($serverUrl)?></code></div>
      <button onclick="copyText('<?=htmlspecialchars(addslashes($serverUrl))?>','Connect URL')" class="btn icon-btn" title="Copy" aria-label="Copy URL"><?= phIcon('copy') ?></button>
      <button onclick="document.getElementById('urlEditView').style.display='block';this.parentElement.style.display='none'" class="btn icon-btn" title="Edit" aria-label="Edit URL"><?= phIcon('pencil-simple-line') ?></button>
    </div>
    <div id="urlEditView" style="display:none;margin-bottom:12px">
      <input class="fi fi-mono" id="serverUrl" value="<?=htmlspecialchars($serverUrl)?>" oninput="updatePreview(this.value)" style="margin-bottom:8px">
      <div class="fi-hint" id="urlPreview" style="margin:0 0 8px;word-break:break-all"></div>
      <button class="btn btn-solid btn-sm" onclick="saveServer()">Save new URL</button>
    </div>
    <label class="glabel" for="apkVer">APK version</label>
    <div style="display:flex;gap:8px">
      <input class="fi fi-mono" id="apkVer" value="<?=htmlspecialchars($apkVer)?>" style="flex:1;min-width:0">
      <button class="btn" onclick="saveApkVer()">Save</button>
    </div>
    <?php endif; ?>
  </section>

  <div>
    <!-- Protection -->
    <section class="gsec">
      <div class="gsec-h">
        <span class="gnum"><?= phIcon('shield-check') ?></span><h3>DDoS protection</h3>
        <label class="tog"><input type="checkbox" id="ddosTog" <?=$ddosProt?'checked':''?> onchange="saveDdos()"><span class="togsl"></span></label>
      </div>
      <div class="gpair">
        <div><label class="glabel" for="maxAttempts">Login attempts</label><input type="number" id="maxAttempts" data-ui="stepper" value="<?=$maxAttempts?>" min="3" max="20"></div>
        <div><label class="glabel" for="lockoutMins">Lockout minutes</label><input type="number" id="lockoutMins" data-ui="stepper" value="<?=$lockoutMins?>" min="1" max="60"></div>
      </div>
      <button class="btn btn-full" style="margin-top:14px" onclick="saveDdos()"><?= phIcon('floppy-disk') ?> Save protection</button>
    </section>

    <!-- License -->
    <section class="gsec" style="margin:0">
      <div class="gsec-h">
        <span class="gnum"><?= phIcon('seal-check') ?></span><h3>Panel license</h3>
        <?php if($licValid===true): ?><span class="bx b-active">Active · <?=$licDaysLeft?>d left</span>
        <?php elseif($licValid===false): ?><span class="bx b-ban">Expired</span>
        <?php elseif(!empty($curLicKey)): ?><span class="bx b-reseller">Not verified</span><?php endif; ?>
      </div>
      <?php if($licValid===false): ?>
      <div class="alert a-err"><?= phIcon('warning') ?> License expired — enter a new key.</div>
      <?php elseif($licValid===true && $licDaysLeft<=7): ?>
      <div class="alert a-warn"><?= phIcon('warning') ?> License expires in <?=$licDaysLeft?> days.</div>
      <?php endif; ?>
      <?php if($curLicKey): ?>
      <div class="rowi" style="margin-bottom:12px">
        <div class="rowi-ic"><?= phIcon('key') ?></div>
        <div class="rowi-x"><b>Current key</b><code><?=htmlspecialchars($curLicKey)?></code></div>
        <?php if($licExpiry): ?><span class="bx <?=$licDaysLeft<=7?'b-ban':'b-unused'?>"><?=date('d M Y',strtotime($licExpiry))?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <label class="glabel" for="newLicKey">New license key</label>
      <div style="display:flex;gap:8px">
        <input class="fi fi-mono" type="text" id="newLicKey" placeholder="HBL-XXXXX-XXXXX-XXXXX-XXXXX" oninput="this.value=this.value.toUpperCase()" style="flex:1;min-width:0">
        <button onclick="saveLicense()" class="btn">Save</button>
      </div>
    </section>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

async function saveMaint(on){
  const msg = document.getElementById('maintMsg').value;
  const r = await formPost('/panelset.php',{ajax:1,section:'maintenance',maintenance_mode:on,maint_message:msg,csrf});
  if(r.ok){
    toast(r.msg, 's');
    const badge = document.getElementById('maintBadge');
    if(on==1){ badge.className='pill p-warn'; badge.innerHTML='<span class="dot"></span>ON'; }
    else { badge.className='pill p-on'; badge.innerHTML='<span class="dot"></span>OFF'; }
  } else toast(r.msg,'e');
}

function updatePreview(val){
  const p = document.getElementById('urlPreview');
  if(p) p.textContent = val ? '→ ' + val : '';
}
async function saveApkVer(){
  const r = await formPost('/panelset.php',{ajax:1,section:'server',server_url:document.getElementById('serverUrl')?.value||'<?=addslashes($serverUrl)?>',apk_version:document.getElementById('apkVer').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
}
async function saveBase(){
  const b=document.getElementById('baseUrl').value.trim();
  const r=await formPost('/panelset.php',{ajax:1,section:'base',base_url:b,csrf});
  r.ok?toast(r.msg,'s'):toast(r.msg,'e');
  if(r.ok) setTimeout(()=>location.reload(),800);
}
async function saveServer(){
  const r = await formPost('/panelset.php',{ajax:1,section:'server',server_url:document.getElementById('serverUrl').value,apk_version:document.getElementById('apkVer').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
  if(r.ok) setTimeout(()=>location.reload(),700);
}

async function saveDdos(){
  const r = await formPost('/panelset.php',{ajax:1,section:'ddos',ddos_protection:document.getElementById('ddosTog').checked?'1':'0',max_login_attempts:document.getElementById('maxAttempts').value,lockout_minutes:document.getElementById('lockoutMins').value,csrf});
  r.ok ? toast(r.msg,'s') : toast(r.msg,'e');
}

async function saveLicense(){
  const key = document.getElementById('newLicKey').value.trim();
  if(!key){toast('Enter license key','e');return;}
  const r = await formPost('/panelset.php',{ajax:1,section:'license',license_key:key,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),1200);}
  else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
