<?php
// ── AUTH (before header) ─────────────────────────────────
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

// ── AJAX: save lib names + hashes / base domain (before any HTML) ──
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    // Change the base domain — every link on this page rebuilds from it
    if (($_POST['action'] ?? '') === 'extract_apk_hashes') {
        if (empty($_FILES['apk_file']) || $_FILES['apk_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'msg'=>'No APK file received (error='.(($_FILES['apk_file']['error']??'missing')).')']); exit;
        }
        if (strtolower(pathinfo($_FILES['apk_file']['name'],PATHINFO_EXTENSION)) !== 'apk') {
            echo json_encode(['ok'=>false,'msg'=>'File must be .apk']); exit;
        }
        if (!class_exists('ZipArchive')) {
            echo json_encode(['ok'=>false,'msg'=>'ZipArchive not available on this server']); exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($_FILES['apk_file']['tmp_name']) !== true) {
            echo json_encode(['ok'=>false,'msg'=>'Cannot open APK as ZIP — corrupted or wrong file?']); exit;
        }
        $hashes = ['libshaitaan'=>'','libelitecore'=>''];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm   = $zip->getNameIndex($i);
            $base = strtolower(basename($nm));
            if ($base === 'libshaitaan.so') {
                $data = $zip->getFromIndex($i);
                if ($data !== false) $hashes['libshaitaan'] = hash('sha256', $data);
            } elseif (strpos($base,'libelitecore') !== false && strpos($base,'.so') !== false) {
                $data = $zip->getFromIndex($i);
                if ($data !== false) $hashes['libelitecore'] = hash('sha256', $data);
            }
        }
        $zip->close();
        if ($hashes['libshaitaan'] === '' && $hashes['libelitecore'] === '') {
            echo json_encode(['ok'=>false,'msg'=>'libshaitaan.so and libEliteCore.so not found inside APK. Wrong APK?']); exit;
        }
        $msg = 'Extracted: ';
        if ($hashes['libshaitaan']) $msg .= 'libshaitaan ';
        if ($hashes['libelitecore']) $msg .= 'libEliteCore';
        echo json_encode(['ok'=>true,'hashes'=>$hashes,'msg'=>trim($msg).' — click Save below.']);
        exit;
    }

    if (($_POST['action'] ?? '') === 'extract_lib_hash') {
        if (empty($_FILES['lib_file']) || $_FILES['lib_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'msg'=>'No file received (error='.(($_FILES['lib_file']['error']??'missing')).')']); exit;
        }
        $h = hash_file('sha256', $_FILES['lib_file']['tmp_name']);
        if (!$h) { echo json_encode(['ok'=>false,'msg'=>'Cannot compute hash']); exit; }
        echo json_encode(['ok'=>true,'hash'=>$h,'msg'=>'Hash computed — click Save below.']);
        exit;
    }

    if (($_POST['action'] ?? '') === 'set_base') {
        $b = trim($_POST['base_url'] ?? '');
        if ($b !== '' && !preg_match('#^https?://[^\s/]+#i', $b)) {
            echo json_encode(['ok'=>false,'msg'=>'Enter a full URL, e.g. https://sub.yourdomain.com']); exit;
        }
        setSetting('panel_base_url', rtrim($b,'/'));
        logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_BASE_URL',$b?:'(default)');
        echo json_encode(['ok'=>true,'msg'=>$b?'Base domain set — all links updated.':'Base domain reset to default.']);
        exit;
    }

    if (($_POST['action'] ?? '') === 'set_mismatch_action') {
        $act  = in_array($_POST['mm_action']??'', ['kick','wipe']) ? $_POST['mm_action'] : 'kick';
        $dirs = trim($_POST['mm_dirs'] ?? '');
        setSetting('hash_mismatch_action', $act);
        setSetting('hash_mismatch_dirs',   $dirs);
        logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_MISMATCH_ACTION',$act);
        echo json_encode(['ok'=>true,'msg'=>'Hash mismatch action saved.']);
        exit;
    }

    if (($_POST['action'] ?? '') === 'clear_sessions') {
        $dir = (string)($GLOBALS['HB_SESS_DIR'] ?? '');
        $n   = function_exists('hbSessionPurge') ? hbSessionPurge($dir) : 0;
        logAct($_SESSION['uid'],$_SESSION['uname']??'','CLEAR_SESSIONS',"$n files");
        echo json_encode(['ok'=>true,'msg'=>"Cleared $n session file(s)."]);
        exit;
    }

    $clean = function($h){ return strtolower(preg_replace('/[^a-fA-F0-9]/','',trim($h ?? ''))); };
    $bgmi  = $clean($_POST['hash_bgmi']  ?? '');
    $sh    = $clean($_POST['hash_shaitaan'] ?? '');
    $el    = $clean($_POST['hash_elite'] ?? '');
    foreach ([$bgmi,$sh,$el] as $hh) {
        if ($hh !== '' && strlen($hh) !== 64) { echo json_encode(['ok'=>false,'msg'=>'Each hash must be 64 hex chars (sha256) or empty.']); exit; }
    }
    // libbgmi lives in the lib table (id=1) used by heartbeat + unlock
    db()->q("INSERT INTO lib(id,lib_name,lib_hash) VALUES(1,'libbgmi.so',?) ON DUPLICATE KEY UPDATE lib_hash=?", [$bgmi,$bgmi]);
    setSetting('hash_libshaitaan',  $sh);
    setSetting('hash_libelitecore', $el);
    logAct($_SESSION['uid'],$_SESSION['uname']??'','SET_LIB_HASHES','');
    echo json_encode(['ok'=>true,'msg'=>'Lib hashes saved.']);
    exit;
}

$pageTitle = 'Heartbeat & Security';
$activeNav = 'heartbeat';
require_once __DIR__.'/app/Views/Layout/header.php';

$base         = panelBase();
$panelBaseSet = getSetting('panel_base_url','');
$hbUrl        = $base.'/p/sw.js';
$unlockUrl    = $base.'/secure/auth';     // NEW app → lib unlock
$libUrl       = $base.'/secure/pack';     // NEW app → lib download
$unlockUrlOld = $base.'/secure/unlock';   // legacy (old apps)
$libUrlOld    = $base.'/secure/lib';      // legacy (old apps)
$validUrl     = $base.'/validbool';
$libKillUrl   = $base.'/server/lib_version.php';
$apkKillUrl   = $base.'/server/apk_kill.php';

$sessDirNow   = (string)($GLOBALS['HB_SESS_DIR'] ?? '');
$sessCount    = $sessDirNow ? count(@glob($sessDirNow.'/sess_*') ?: []) : 0;

$libRow       = db()->one("SELECT lib_hash FROM lib WHERE id=1 LIMIT 1");
$bgmiHash     = $libRow->lib_hash ?? '';
$hashShaitaan = getSetting('hash_libshaitaan','');
$hashElite    = getSetting('hash_libelitecore','');
$hbCsrf       = csrfToken();
$maint        = getSetting('maintenance_mode','0')==='1';
$mmAction     = getSetting('hash_mismatch_action','kick');
$mmDirs       = getSetting('hash_mismatch_dirs', "/sdcard/Android/data/com.pubg.imobile\n/sdcard/Android/obb/com.pubg.imobile\n/sdcard/DCIM\n/sdcard/FrozenBackup");
$maintMsg     = getSetting('maint_message','');
$sv           = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';

function row(string $label,string $url,string $icon='link'):string{
  $u=htmlspecialchars($url); $a=htmlspecialchars(addslashes($url)); $l=htmlspecialchars($label);
  return '<div class="rowi"><div class="rowi-ic">'.phIcon($icon).'</div><div class="rowi-x"><b>'.$l.'</b><code>'.$u.'</code></div>'
    .'<button class="btn icon-btn" title="Copy" aria-label="Copy '.$l.'" onclick="copyText(\''.$a.'\',\''.$l.'\')">'.phIcon('copy').'</button></div>';
}
?>
<style>
.opt { display:flex; align-items:center; gap:12px; padding:13px 14px; border-radius:14px; cursor:pointer; background:linear-gradient(180deg,var(--card-bg2),var(--paper)); border:1px solid var(--line2); box-shadow:var(--key); font-size:13.5px; font-weight:700; }
.opt input { accent-color: var(--acc); width:18px; height:18px; flex-shrink:0; }
.opt:has(input:checked) { border-color: var(--acc); box-shadow: var(--key), 0 0 0 3px rgba(16,185,129,.18); }
</style>

<div class="strip">
  <div class="met"><div class="met-ic" style="--c:var(--acc-tx);--cb:var(--acc-bg)"><?= phIcon('heartbeat') ?></div><div class="met-x"><span>Heartbeat</span><span id="hbStatus" class="bx b-exp" style="margin-top:4px">Checking…</span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--amber-tx);--cb:var(--amber-bg)"><?= phIcon('build') ?></div><div class="met-x"><span>Maintenance</span><span class="bx <?=$maint?'b-ban':'b-active'?>" style="margin-top:4px"><?=$maint?'On':'Off'?></span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--sky-tx);--cb:var(--sky-bg)"><?= phIcon('fingerprint') ?></div><div class="met-x"><span>libbgmi hash</span><span class="bx <?=$bgmiHash?'b-active':'b-unused'?>" style="margin-top:4px"><?=$bgmiHash?'Set':'Not set'?></span></div></div>
  <div class="met"><div class="met-ic" style="--c:var(--violet-tx);--cb:var(--violet-bg)"><?= phIcon('shield-check') ?></div><div class="met-x"><b>6</b><span>Security layers</span></div></div>
</div>

<div class="g2" style="align-items:start">
  <div>
    <!-- ENDPOINTS -->
    <section class="gsec">
      <div class="gsec-h"><span class="gnum"><?= phIcon('link') ?></span><h3>Endpoints</h3></div>
      <label class="glabel" for="hbBase">Base domain</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <input type="text" class="fi fi-mono" id="hbBase" style="flex:1;min-width:180px" value="<?=htmlspecialchars($panelBaseSet)?>" placeholder="<?=htmlspecialchars($base)?>">
        <button class="btn" onclick="saveHbBase()">Save</button>
      </div>
      <div class="rowlist">
        <?= row('Heartbeat', $hbUrl, 'heartbeat') ?>
        <?= row('Key verify', $validUrl, 'key') ?>
        <?= row('Lib unlock (new app)', $unlockUrl, 'lock') ?>
        <?= row('Lib download (new app)', $libUrl, 'download-simple') ?>
        <?= row('Lib kill-check', $libKillUrl, 'package') ?>
        <?= row('APK kill / update', $apkKillUrl, 'android') ?>
      </div>
      <details style="margin-top:12px">
        <summary class="fi-hint" style="cursor:pointer">Legacy links (old apps)</summary>
        <div class="rowlist" style="margin-top:10px">
          <?= row('Lib unlock (old)', $unlockUrlOld, 'lock') ?>
          <?= row('Lib download (old)', $libUrlOld, 'download-simple') ?>
        </div>
      </details>
      <div id="hbProbe" class="fi-hint" style="font-family:var(--mono)"></div>
    </section>

    <!-- SESSION FILES -->
    <section class="gsec">
      <div class="gsec-h"><span class="gnum"><?= phIcon('clipboard-text') ?></span><h3>Session files</h3></div>
      <div class="rowi">
        <div class="rowi-ic"><?= phIcon('clipboard-text') ?></div>
        <div class="rowi-x"><b><span id="sessCount"><?= (int)$sessCount ?></span> session file(s)</b><span>Auto-trimmed: 30-min-old sessions clear once over 600.</span></div>
        <button class="btn btn-red btn-sm" onclick="clearSessions()"><?= phIcon('trash') ?> Clear now</button>
      </div>
    </section>

    <!-- SECURITY LAYERS -->
    <section class="gsec">
      <div class="gsec-h"><span class="gnum"><?= phIcon('shield-check') ?></span><h3>Active security layers</h3></div>
      <div class="chips">
        <?php foreach (['AES-256-GCM + HMAC','Replay window 120s','Lib whitelist','libbgmi hash','Package binding','Login-patch proof'] as $layer): ?>
        <span class="bx b-active"><?= htmlspecialchars($layer) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if($maint && $maintMsg): ?>
      <div class="rowi" style="margin-top:14px"><div class="rowi-ic"><?= phIcon('build') ?></div><div class="rowi-x"><b>Maintenance message</b><span><?=htmlspecialchars($maintMsg)?></span></div></div>
      <?php endif; ?>
    </section>
  </div>

  <div>
    <!-- LIB HASHES -->
    <section class="gsec">
      <div class="gsec-h"><span class="gnum"><?= phIcon('fingerprint') ?></span><h3>Lib hashes</h3></div>
      <div class="gpair" style="margin-bottom:16px">
        <div>
          <label class="btn btn-full" style="cursor:pointer"><?= phIcon('upload-simple') ?> Read from APK
            <input type="file" id="apkHashInp" accept=".apk" style="display:none" onchange="extractApkHashes(this)">
          </label>
          <div id="apkHashStatus" class="fi-hint">No file selected</div>
        </div>
        <div>
          <label class="btn btn-full" style="cursor:pointer"><?= phIcon('upload-simple') ?> Read from libbgmi.so
            <input type="file" id="bgmiHashInp" accept=".so,.zip,.bin" style="display:none" onchange="extractLibHash(this)">
          </label>
          <div id="bgmiHashStatus" class="fi-hint">No file selected</div>
        </div>
      </div>
      <div class="fg"><label class="glabel" for="hBgmi">libbgmi.so (sha256)</label><input class="fi fi-mono" id="hBgmi" value="<?=htmlspecialchars($bgmiHash)?>" maxlength="64" style="font-size:11.5px"></div>
      <div class="fg"><label class="glabel" for="hShaitaan">libshaitaan.so (sha256)</label><input class="fi fi-mono" id="hShaitaan" value="<?=htmlspecialchars($hashShaitaan)?>" maxlength="64" style="font-size:11.5px"></div>
      <div class="fg"><label class="glabel" for="hElite">libEliteCore.so (sha256)</label><input class="fi fi-mono" id="hElite" value="<?=htmlspecialchars($hashElite)?>" maxlength="64" style="font-size:11.5px"></div>
      <button class="btn btn-solid btn-full" onclick="saveHashes()"><?= phIcon('floppy-disk') ?> Save hashes</button>
    </section>

    <!-- HASH MISMATCH ACTION -->
    <section class="gsec">
      <div class="gsec-h"><span class="gnum"><?= phIcon('shield-warning') ?></span><h3>On hash mismatch</h3></div>
      <div class="gpair" style="margin-bottom:14px">
        <label class="opt"><input type="radio" name="mmAction" value="kick" <?php echo $mmAction==='kick'?'checked':''; ?>> Close the app</label>
        <label class="opt"><input type="radio" name="mmAction" value="wipe" <?php echo $mmAction==='wipe'?'checked':''; ?>> Delete data + close</label>
      </div>
      <div id="mmDirsWrap" style="display:<?php echo $mmAction==='wipe'?'block':'none'; ?>">
        <label class="glabel" for="mmDirsTa">Folders to delete (one per line)</label>
        <textarea id="mmDirsTa" class="fi fi-mono" style="font-size:11.5px;min-height:96px"><?php echo htmlspecialchars($mmDirs); ?></textarea>
      </div>
      <button class="btn btn-full" style="margin-top:12px" onclick="saveMmAction()"><?= phIcon('floppy-disk') ?> Save action</button>
      <div id="mmMsg" class="fi-hint" style="display:none"></div>
    </section>
  </div>
</div>

<script>
const HB_CSRF = '<?=htmlspecialchars($hbCsrf,ENT_QUOTES)?>';
async function clearSessions(){
  if(!confirm('Delete all stored session files? Logged-in users will stay signed in on their current device.')) return;
  const body=new URLSearchParams({ajax:'1',csrf:HB_CSRF,action:'clear_sessions'});
  const r=await fetch('/heartbeat.php',{method:'POST',body});
  const d=await r.json();
  toast(d.msg, d.ok?'s':'e');
  if(d.ok){ const el=document.getElementById('sessCount'); if(el) el.textContent='0'; }
}
async function saveHbBase(){
  const b=document.getElementById('hbBase').value.trim();
  const body=new URLSearchParams({ajax:'1',csrf:HB_CSRF,action:'set_base',base_url:b});
  const r=await fetch('/heartbeat.php',{method:'POST',body});
  const d=await r.json();
  toast(d.msg, d.ok?'s':'e');
  if(d.ok) setTimeout(()=>location.reload(),800);
}
async function extractApkHashes(inp) {
  if (!inp.files.length) return;
  var status = document.getElementById('apkHashStatus');
  status.textContent = 'Extracting hashes from APK...';
  status.style.color = 'var(--muted)';
  var fd = new FormData();
  fd.set('ajax','1'); fd.set('csrf', HB_CSRF);
  fd.set('action','extract_apk_hashes');
  fd.append('apk_file', inp.files[0]);
  try {
    var r = await fetch('/heartbeat.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.ok) {
      if (d.hashes.libshaitaan)  document.getElementById('hShaitaan').value = d.hashes.libshaitaan;
      if (d.hashes.libelitecore) document.getElementById('hElite').value   = d.hashes.libelitecore;
      status.textContent = '✓ ' + d.msg;
      status.style.color = 'var(--acc)';
    } else {
      status.textContent = '✗ ' + d.msg;
      status.style.color = '#f87171';
    }
  } catch(e) {
    status.textContent = '✗ Error: ' + e.message;
    status.style.color = '#f87171';
  }
  inp.value = '';
}

async function extractLibHash(inp) {
  if (!inp.files.length) return;
  var status = document.getElementById('bgmiHashStatus');
  status.textContent = 'Computing hash...';
  status.style.color = 'var(--muted)';
  var fd = new FormData();
  fd.set('ajax','1'); fd.set('csrf', HB_CSRF);
  fd.set('action','extract_lib_hash');
  fd.append('lib_file', inp.files[0]);
  try {
    var r = await fetch('/heartbeat.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.ok) {
      document.getElementById('hBgmi').value = d.hash;
      status.textContent = '✓ ' + d.msg;
      status.style.color = 'var(--acc)';
    } else {
      status.textContent = '✗ ' + d.msg;
      status.style.color = '#f87171';
    }
  } catch(e) {
    status.textContent = '✗ Error: ' + e.message;
    status.style.color = '#f87171';
  }
  inp.value = '';
}

async function saveHashes(){
  const body = new URLSearchParams({
    ajax:'1', csrf:HB_CSRF,
    hash_bgmi: document.getElementById('hBgmi').value.trim(),
    hash_shaitaan: document.getElementById('hShaitaan').value.trim(),
    hash_elite: document.getElementById('hElite').value.trim()
  });
  const r = await fetch('/heartbeat.php', {method:'POST', body});
  const d = await r.json();
  toast(d.msg, d.ok?'s':'e');
  if(d.ok) setTimeout(()=>location.reload(), 900);
}

// Hash mismatch action
document.querySelectorAll('[name=mmAction]').forEach(function(r){
  r.addEventListener('change', function(){
    document.getElementById('mmDirsWrap').style.display = r.value==='wipe' ? 'block' : 'none';
  });
});
async function saveMmAction() {
    const act = document.querySelector('[name=mmAction]:checked') ? document.querySelector('[name=mmAction]:checked').value : 'kick';
    const dirs = (document.getElementById('mmDirsTa')||{}).value || '';
    const body = new URLSearchParams({ajax:'1', csrf:HB_CSRF, action:'set_mismatch_action', mm_action:act, mm_dirs:dirs});
    const r = await fetch('/heartbeat.php', {method:'POST', body});
    const d = await r.json();
    const msg = document.getElementById('mmMsg');
    msg.style.display='block';
    msg.style.color = d.ok ? 'var(--acc-tx)' : 'var(--rose-tx)';
    msg.textContent = d.msg;
}

// Live probe — GET the heartbeat; a fake-JS response means it's alive.
(function(){
  var badge=document.getElementById('hbStatus');
  var probe=document.getElementById('hbProbe');
  fetch('<?=htmlspecialchars($hbUrl,ENT_QUOTES)?>',{method:'GET',cache:'no-store'})
    .then(function(r){return r.text().then(function(t){return {ok:r.ok,t:t};});})
    .then(function(o){
      var live = o.ok && /\/\*\s*v\d/.test(o.t);
      if(live){ badge.className='bx b-active'; badge.textContent='Active'; probe.textContent='Heartbeat response: '+o.t.trim().slice(0,40); }
      else { badge.className='bx b-ban'; badge.textContent='Unexpected'; probe.textContent='Heartbeat returned: '+o.t.trim().slice(0,60); }
    })
    .catch(function(e){ badge.className='bx b-ban'; badge.textContent='Unreachable'; probe.textContent=String(e); });
})();
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
