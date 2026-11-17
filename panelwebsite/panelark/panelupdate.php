<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX handlers (before HTML) ──────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';
    $ulevel = (int)($_SESSION['ulevel'] ?? 99);

    // Check for update
    if ($action === 'check') {
        $masterUrl = getSetting('update_server_url', '');
        if (empty($masterUrl)) {
            echo json_encode(['ok'=>false,'msg'=>'Update server URL not configured']);
            exit;
        }
        $ch = curl_init($masterUrl . '/updates/version.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'HBPanel-Updater/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$res || $code !== 200) {
            echo json_encode(['ok'=>false,'msg'=>'Cannot reach update server']);
            exit;
        }
        $data = json_decode($res, true);
        if (!$data || empty($data['version'])) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid version data from server']);
            exit;
        }

        $current = getSetting('panel_version','3.0.0');
        $latest  = $data['version'];
        $hasUpdate = version_compare($latest, $current, '>');

        echo json_encode([
            'ok'         => true,
            'current'    => $current,
            'latest'     => $latest,
            'has_update' => $hasUpdate,
            'changelog'  => $data['changelog'] ?? [],
            'date'       => $data['date'] ?? '',
        ]);
        exit;
    }

    // Apply update - owner only
    if ($action === 'apply') {
        if ($ulevel !== 1) {
            echo json_encode(['ok'=>false,'msg'=>'Owner only']);
            exit;
        }
        if (!verifyCsrf($_POST['csrf']??'')) {
            echo json_encode(['ok'=>false,'msg'=>'Invalid token']);
            exit;
        }

        $masterUrl = getSetting('update_server_url','');
        $secToken  = getSetting('update_secret_token','');

        if (empty($masterUrl) || empty($secToken)) {
            echo json_encode(['ok'=>false,'msg'=>'Update server not configured in settings']);
            exit;
        }

        // Download ZIP
        $zipUrl = $masterUrl . '/updates/latest.zip?token=' . urlencode($secToken);
        $tmpZip = sys_get_temp_dir() . '/hbpanel_update_' . time() . '.zip';

        $ch = curl_init($zipUrl);
        $fp = fopen($tmpZip, 'w');
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'HBPanel-Updater/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$res || $code !== 200) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>"Download failed (HTTP $code). Check token."]); exit;
        }

        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>'ZipArchive not available on server']); exit;
        }

        // Extract ZIP
        $zip  = new ZipArchive();
        $root = __DIR__; // public_html (panelupdate.php is IN public_html)

        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            echo json_encode(['ok'=>false,'msg'=>'Cannot open ZIP file']); exit;
        }

        // Extract to panel root (skip config files to preserve settings)
        // Files to NEVER overwrite during update
        $skipFiles = [
            'app/Config/config.php',
            'app/Config/db.php',
            'index.php',
            'server/index.php',
            'register.php',
            'reset-device.php',
            'forgot.php',
            'setup.php',
        ];
        
        // Skip ALL .htaccess files - never overwrite
        $skipHtaccess = true;

        $extracted = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            // Strip top folder if present (e.g. finalpanel/)
            $parts = explode('/', $name, 2);
            $relative = count($parts) > 1 ? $parts[1] : $name;
            if (empty($relative)) continue;
            if (in_array($relative, $skipFiles)) continue;
            // Never overwrite ANY .htaccess file
            if ($skipHtaccess && basename($relative) === '.htaccess') continue;

            $dest = $root . '/' . $relative;
            if (substr($name, -1) === '/') {
                @mkdir($dest, 0755, true);
            } else {
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, $zip->getFromIndex($i));
                $extracted++;
            }
        }
        $zip->close();
        @unlink($tmpZip);

        // Get new version from server
        $ch2  = curl_init($masterUrl . '/updates/version.json');
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false]);
        $vRes = curl_exec($ch2); curl_close($ch2);
        $vData= json_decode($vRes, true);
        $newVer = $vData['version'] ?? '3.1.0';

        // Update version in DB
        db()->q("UPDATE panel_settings SET svalue=? WHERE skey='panel_version'", [$newVer]);
        logAct($_SESSION['uid'], $_SESSION['uname']??'', 'PANEL_UPDATE', "Updated to v$newVer ($extracted files)");

        echo json_encode([
            'ok'      => true,
            'msg'     => "Panel updated to v$newVer successfully! ($extracted files)",
            'version' => $newVer,
        ]);
        exit;
    }

    // Save config
    if ($action === 'save_config') {
        if ($ulevel !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
        $url    = rtrim(trim($_POST['url']??''),'/');
        $token  = trim($_POST['token']??'');
        $licKey = trim($_POST['lic_key']??'');
        if (empty($url)||empty($token)) { echo json_encode(['ok'=>false,'msg'=>'Fill both fields']); exit; }

        $settings = [
            'update_server_url'    => $url,
            'update_secret_token'  => $token,
            'lic_cache'            => '',
            'lic_cache_time'       => '0',
        ];
        if (!empty($licKey)) $settings['panel_license_key'] = $licKey;

        foreach ($settings as $k => $v) {
            $ex = db()->one("SELECT id FROM panel_settings WHERE skey=?",[$k]);
            if ($ex) db()->q("UPDATE panel_settings SET svalue=? WHERE skey=?",[$v,$k]);
            else     db()->q("INSERT INTO panel_settings(skey,svalue) VALUES(?,?)",[$k,$v]);
        }
        echo json_encode(['ok'=>true,'msg'=>'Config saved!']); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── PAGE ────────────────────────────────────────
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'Panel Update'; $activeNav = 'panelupdate';
require_once __DIR__.'/app/Views/Layout/header.php';

$currentVer   = getSetting('panel_version','3.0.0');
$masterUrl    = getSetting('update_server_url','');
$secToken     = getSetting('update_secret_token','');
$configured   = !empty($masterUrl) && !empty($secToken);
$s = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="g2">
  <!-- Update Manager -->
  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg>
        Panel Update Manager
      </span>
      <span id="upBadge" class="bx b-active">Checking...</span>
    </div>
    <div class="card-b">
      <!-- Status Box -->
      <div id="upStatus" style="background:var(--acc-bg);border:1px solid var(--acc-20);border-radius:var(--r);padding:16px;margin-bottom:16px;text-align:center">
        <div style="font-size:12px;color:var(--muted);margin-bottom:6px">Checking for updates...</div>
        <div style="width:40px;height:40px;margin:0 auto;border:3px solid var(--acc-20);border-top-color:var(--acc);border-radius:50%;animation:spin 1s linear infinite"></div>
      </div>

      <!-- Current Version -->
      <div style="display:flex;gap:12px;margin-bottom:14px">
        <div style="flex:1;background:var(--s3);border:1px solid var(--border);border-radius:var(--r);padding:14px;text-align:center">
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Current Version</div>
          <div style="font-size:28px;font-weight:900;color:var(--acc)"><?=$currentVer?></div>
        </div>
        <div style="flex:1;background:var(--s3);border:1px solid var(--border);border-radius:var(--r);padding:14px;text-align:center">
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">New Version</div>
          <div id="newVerDisplay" style="font-size:28px;font-weight:900;color:var(--muted2)">—</div>
          <div id="newVerDate" style="font-size:10px;color:var(--muted);margin-top:4px"></div>
        </div>
      </div>

      <!-- Controls -->
      <div style="display:flex;gap:10px;margin-bottom:14px">
        <button class="btn btn-ghost" style="flex:1" onclick="checkUpdate()">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="15" height="15"><path d="M378.09-314.5q-111.16 0-188.33-77.17-77.17-77.18-77.17-188.33t77.17-188.33q77.17-77.17 188.33-77.17 111.15 0 188.32 77.17 77.18 77.18 77.18 188.33 0 44.48-13.52 83.12-13.53 38.64-36.57 68.16l222.09 222.33q12.67 12.91 12.67 31.94 0 19.04-12.91 31.71-12.68 12.67-31.83 12.67t-31.82-12.67L529.85-364.59q-29.76 23.05-68.64 36.57-38.88 13.52-83.12 13.52Zm0-91q72.84 0 123.67-50.83 50.83-50.82 50.83-123.67t-50.83-123.67q-50.83-50.83-123.67-50.83-72.85 0-123.68 50.83-50.82 50.82-50.82 123.67t50.82 123.67q50.83 50.83 123.68 50.83Z"/></svg>
          Check for Update
        </button>
        <button id="applyBtn" class="btn btn-acc" style="flex:1;display:none" onclick="applyUpdate()">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="15" height="15"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg>
          Apply Update
        </button>
      </div>

      <!-- Changelog -->
      <div id="changelogBox" style="display:none">
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;display:flex;align-items:center;gap:6px">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="15" height="15"><path d="M361.91-239.28h236.42q17.71 0 29.69-12.1T640-281.2q0-17.71-11.98-29.69t-29.69-11.98H361.67q-17.71 0-29.69 11.98T320-281.2q0 17.72 12.1 29.82 12.1 12.1 29.81 12.1Zm0-160h236.42q17.71 0 29.69-12.1T640-441.2q0-17.71-11.98-29.69t-29.69-11.98H361.67q-17.71 0-29.69 11.98T320-441.2q0 17.72 12.1 29.82 12.1 12.1 29.81 12.1ZM242.87-71.87q-37.78 0-64.39-26.61t-26.61-64.39v-634.26q0-37.78 26.61-64.39t64.39-26.61H525.8q18.16 0 34.69 6.84 16.53 6.83 29.21 19.51L781.78-669.7q12.68 12.68 19.51 29.21 6.84 16.53 6.84 34.69v442.93q0 37.78-26.61 64.39t-64.39 26.61H242.87Zm274.26-570.76q0 19.15 13.17 32.33 13.18 13.17 32.33 13.17h154.5l-200-200v154.5Z"/></svg>
          What's New
        </div>
        <div id="changelogList" style="background:var(--s3);border:1px solid var(--border);border-radius:var(--r);padding:12px"></div>
      </div>

      <?php if(!$configured): ?>
      <div class="alert a-warn" style="margin-top:14px">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M111.63-115.93q-12.67 0-22.89-6.29T72.85-138.8q-5.72-10.2-6.22-22.16t6.22-23.34L440.22-820q6.71-11.43 17.55-17.03 10.84-5.6 22.25-5.6t22.23 5.6q10.82 5.6 17.53 17.03l367.37 635.7q6.72 11.38 6.22 23.34-.5 11.96-6.22 22.16-5.72 10.19-15.91 16.53-10.2 6.34-22.87 6.34H111.63ZM480-240.72q17.24 0 29.22-11.98 11.98-11.97 11.98-29.21 0-17.24-11.98-29.1-11.98-11.86-29.22-11.86t-29.22 11.86q-11.98 11.86-11.98 29.1 0 17.24 11.98 29.21 11.98 11.98 29.22 11.98ZM480-360q17 0 28.5-11.5T520-400v-117.37q0-17-11.5-28.5t-28.5-11.5q-17 0-28.5 11.5t-11.5 28.5V-400q0 17 11.5 28.5T480-360Z"/></svg>
        Update server not configured. Set it below.
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php if(!$configured): ?>
  <!-- Update Server Config - ONLY shown if not configured yet -->
  <div class="card" id="cfgCard">
    <div class="card-h">
      <span class="ct">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M428.46-71.87q-28.2 0-48.54-18.6-20.33-18.6-24.33-45.79l-9-66.24q-11.09-4.28-21.04-10.33-9.94-6.04-19.51-12.84l-61.76 26q-25.95 11.24-52.15 2.12-26.2-9.12-40.67-33.32l-51.55-90.37q-14.48-23.96-8.36-51.03 6.12-27.08 28.08-44.56l52.76-40q-.76-6.04-.76-11.58v-23.18q0-5.54.76-11.58l-52.76-39.76q-22.2-17.48-28.2-44.56-6-27.08 8.48-51.27l51.55-90.13q14.47-23.96 40.55-33.2 26.08-9.24 52.03 2l62.48 26q9.57-6.8 19.65-12.73 10.09-5.92 20.42-10.2l9-66.48q4-27.43 24.33-46.03 20.34-18.6 48.54-18.6h103.08q28.2 0 48.54 18.6 20.33 18.6 24.33 46.03l9 66.48q11.09 4.28 21.04 10.2 9.94 5.93 19.51 12.73l61.76-26q25.95-11.24 52.15-2t40.67 33.2l51.55 90.13q14.48 24.19 8.48 51.27-6 27.08-28.2 44.56l-53 39.76q.76 6.04.76 11.58V-480q0 6.04-.12 11.59-.12 5.54-1.64 11.58l53 39.76q22.2 17.48 28.2 44.56 6 27.08-8.48 51.27l-52.55 90.37q-14.47 23.96-40.55 33.2-26.08 9.24-52.03-2l-60.48-26q-9.57 6.8-19.65 12.84-10.09 6.05-20.42 10.33l-9 66.24q-4 27.19-24.33 45.79-20.34 18.6-48.54 18.6H428.46ZM481.28-340q58 0 99-41t41-99q0-58-41-99t-99-41q-58.76 0-99.38 41t-40.62 99q0 58 40.62 99t99.38 41Z"/></svg>
        Update Server Config
      </span>
    </div>
    <div class="card-b">
      <form onsubmit="saveConfig(event)">
        <div class="fg">
          <label class="fl">Master Domain URL</label>
          <input class="fi" type="url" id="cfgUrl" placeholder="https://battleground-hub.shop">
          <div class="fi-hint">Without trailing slash</div>
        </div>
        <div class="fg">
          <label class="fl">Secret Token</label>
          <div style="display:flex;align-items:center;background:var(--sunk);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <input class="fi fi-mono" type="password" id="cfgToken" placeholder="Enter secret token" style="border:none;border-radius:0">
            <button type="button" onclick="toggleTok()" style="padding:0 12px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:12px;white-space:nowrap;">Show</button>
          </div>
          <div class="fi-hint">Token jo battleground-hub.shop ke .htaccess mein hai</div>
        </div>
        <button type="submit" class="btn btn-acc btn-full">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="15"><path d="m382-362.13 334.7-334.7q13.67-13.67 32.06-13.67t32.07 13.67q13.67 13.68 13.67 32.45 0 18.77-13.67 32.45L414.07-264.41q-13.68 13.67-32.07 13.67t-32.07-13.67L178.41-435.93q-13.67-13.68-13.29-32.45.38-18.77 14.05-32.45 13.68-13.67 32.45-13.67 18.77 0 32.45 13.67L382-362.13Z"/></svg>
          Save & Connect
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>

<style>
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;

async function checkUpdate(){
  document.getElementById('upStatus').innerHTML = '<div style="font-size:12px;color:var(--muted);margin-bottom:8px">Checking...</div><div style="width:30px;height:30px;margin:0 auto;border:3px solid var(--acc-20);border-top-color:var(--acc);border-radius:50%;animation:spin 1s linear infinite"></div>';
  document.getElementById('upBadge').className='bx b-exp';
  document.getElementById('upBadge').textContent='Checking...';
  document.getElementById('applyBtn').style.display='none';
  document.getElementById('changelogBox').style.display='none';

  const r = await formPost('/panelupdate.php',{ajax:1,action:'check',csrf});
  if(!r.ok){
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--danger);font-size:13px;font-weight:700">'+r.msg+'</div>';
    document.getElementById('upBadge').className='bx b-ban';
    document.getElementById('upBadge').textContent='Error';
    return;
  }

  document.getElementById('newVerDisplay').textContent = r.latest;
  document.getElementById('newVerDate').textContent = r.date || '';

  if(r.has_update){
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--warn);font-size:13px;font-weight:700;margin-bottom:4px">New version available!</div><div style="font-size:11px;color:var(--muted)">v'+r.current+' → v'+r.latest+'</div>';
    document.getElementById('upBadge').className='bx b-exp';
    document.getElementById('upBadge').textContent='Update Available';
    document.getElementById('applyBtn').style.display='flex';
    document.getElementById('newVerDisplay').style.color='var(--warn)';

    // Show changelog
    if(r.changelog && r.changelog.length){
      const list = r.changelog.map(c=>`<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line2);font-size:12px;color:var(--text)"><span style="color:var(--acc);font-weight:900">•</span>${c}</div>`).join('');
      document.getElementById('changelogList').innerHTML = list;
      document.getElementById('changelogBox').style.display='block';
    }
  } else {
    document.getElementById('upStatus').innerHTML = '<div style="color:var(--acc);font-size:13px;font-weight:700">Already up to date — v'+r.current+'</div>';
    document.getElementById('upBadge').className='bx b-active';
    document.getElementById('upBadge').textContent='Up to Date';
    document.getElementById('newVerDisplay').style.color='var(--acc)';
  }
}

async function applyUpdate(){
  const btn = document.getElementById('applyBtn');
  btn.disabled=true; btn.textContent='Updating...';
  const r = await formPost('/panelupdate.php',{ajax:1,action:'apply',csrf});
  if(r.ok){
    toast(r.msg,'s');
    setTimeout(()=>location.reload(),2000);
  } else {
    toast(r.msg,'e');
    btn.disabled=false;
    btn.innerHTML='<svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="15"><path d="M385.83-125.54q-103.48-30.92-168.72-116.85-65.24-85.94-65.24-197.13 0-55.57 18.04-106.23 18.05-50.66 50.89-93.42 11.96-15.11 31.31-15.85 19.35-.74 34.74 14.65 11.72 11.72 12.45 29.03.74 17.32-10.5 32.75-22.08 29.81-34.01 65.13-11.92 35.33-11.92 73.94 0 78.85 45.59 140.79 45.58 61.95 117.95 85.43 14.68 4.95 24.13 17.39 9.46 12.43 9.46 26.87 0 23.34-16.27 36.64-16.27 13.29-37.9 6.86Zm188.34 0q-21.63 6.43-37.9-7.24Q520-146.46 520-169.8q0-13.44 9.46-25.99 9.45-12.56 24.13-17.51 72.13-24.48 117.83-86.05 45.71-61.56 45.71-140.17 0-97.37-67.49-166.18-67.49-68.8-164.62-70.71h-3.95l16.23 16.24q11.72 11.71 11.72 29.31 0 17.6-11.72 29.32-11.71 11.71-29.31 11.71-17.6 0-29.32-11.71l-90.21-90.22q-6.72-6.72-9.7-14.79-2.98-8.08-2.98-17.04 0-8.95 2.98-17.03 2.98-8.08 9.7-14.79l90.21-90.46q11.72-11.72 29.32-11.72t29.31 11.72q11.72 11.72 11.72 29.32 0 17.59-11.72 29.31l-19.58 19.59h4.19q136.63 0 231.43 95.63 94.79 95.63 94.79 232.5 0 110.43-65.24 196.63-65.24 86.19-168.72 117.35Z"/></svg> Apply Update';
  }
}

function toggleTok(){
  const inp = document.getElementById('cfgToken');
  const btn = inp.nextElementSibling;
  if(inp.type==='password'){ inp.type='text'; btn.textContent='Hide'; }
  else { inp.type='password'; btn.textContent='Show'; }
}
async function saveConfig(e){
  e.preventDefault();
  const url   = document.getElementById('cfgUrl').value.trim().replace(/\/$/,'');
  const token = document.getElementById('cfgToken').value.trim();
  if(!url||!token){toast('Fill both fields','e');return;}
  const r = await formPost('/panelupdate.php',{ajax:1,action:'save_config',url,token,csrf});
  if(r.ok){
    toast('Connected! Config saved permanently.','s');
    // Hide config card immediately and permanently
    const card = document.getElementById('cfgCard');
    if(card){ card.style.transition='opacity .4s'; card.style.opacity='0'; setTimeout(()=>card.remove(),400); }
    setTimeout(()=>checkUpdate(), 800);
  } else toast(r.msg,'e');
}

// Auto check on load
window.addEventListener('load', checkUpdate);
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
