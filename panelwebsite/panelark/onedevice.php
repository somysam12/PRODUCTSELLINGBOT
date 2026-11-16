<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle = 'One Device Mode';
$activeNav = 'onedevice';
require_once __DIR__.'/app/Views/Layout/header.php';

if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrf($_POST['csrf']??'')) {
    $sec = $_POST['section'] ?? 'apk';
    if ($sec === 'panel') {
        // Panel single-device login settings
        setSetting('single_device_login', ($_POST['single_device']??'0')==='1'?'1':'0');
        setSetting('panel_device_reset_limit', (string)max(1,(int)($_POST['reset_limit']??1)));
        setSetting('max_devices_cap', (string)max(1,min(99999,(int)($_POST['max_devices_cap']??10))));
        logAct($_SESSION['uid'],$uname,'PANEL_SINGLE_DEVICE',($_POST['single_device']??'0')==='1'?'ON':'OFF');
        header('Location: /onedevice.php?saved=1'); exit;
    }
    // APK one-device mode
    $val = ($_POST['one_device']??'0')==='1' ? '1' : '0';
    setSetting('one_device_mode', $val);
    if ($val === '1') {
        db()->q("UPDATE keys_code SET max_devices=1 WHERE branch=?",[currentBranch()]);
    }
    logAct($_SESSION['uid'],$uname,'ONE_DEVICE_MODE_'.($val?'ON':'OFF'),'');
    header('Location: /onedevice.php?saved=1'); exit;
}

$isOn      = getSetting('one_device_mode','0') === '1';
$panelSD   = getSetting('single_device_login','0') === '1';
$resetLim  = (int)getSetting('panel_device_reset_limit','1');
$maxCap    = (int)getSetting('max_devices_cap','10');
$saved     = isset($_GET['saved']);
?>
<?php if($saved): ?><div class="alert a-ok"><?=svgIcon('check',14)?> Setting saved!</div><?php endif; ?>

<div style="max-width:560px;margin:0 auto">
  <div class="card">
    <div class="card-h"><span class="ct"><?=svgIcon('phone',14)?> One Device Mode</span><span class="owner-only">Owner Only</span></div>
    <div class="card-b">
      <div style="padding:24px;background:<?= $isOn?'rgba(255,184,0,.06)':'rgba(45,212,191,.04)' ?>;border:1px solid <?= $isOn?'rgba(255,184,0,.3)':'rgba(45,212,191,.15)' ?>;border-radius:var(--r);margin-bottom:20px;text-align:center">
        <div style="margin-bottom:12px;color:<?= $isOn?'var(--warn)':'var(--acc)' ?>"><?= svgIcon($isOn?'lock':'key',34) ?></div>
        <div style="font-size:16px;font-weight:800;color:<?= $isOn?'var(--warn)':'var(--acc)' ?>;margin-bottom:6px">
          ONE DEVICE MODE: <?= $isOn?'ENABLED':'DISABLED' ?>
        </div>
        <div style="font-size:12px;color:var(--muted2)">
          <?php if($isOn): ?>
            All keys are limited to 1 device. New keys will also be 1 device max.
          <?php else: ?>
            Keys can use multiple devices based on their settings.
          <?php endif; ?>
        </div>
      </div>

      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="section" value="apk">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--s3);border:1px solid var(--border);border-radius:var(--r);margin-bottom:16px">
          <div>
            <div style="font-weight:700;font-size:14px">One Device Per Key</div>
            <div style="font-size:11px;color:var(--muted);margin-top:3px">Each license key works on only 1 device</div>
          </div>
          <label class="tog"><input type="checkbox" name="one_device" value="1" id="odTog" <?= $isOn?'checked':'' ?>><span class="togsl"></span></label>
        </div>
        <button type="submit" class="btn btn-acc btn-full">SAVE SETTING</button>
      </form>

      <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

      <!-- PANEL SINGLE-DEVICE LOGIN -->
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px">Panel Login — Single Device</div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="section" value="panel">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;background:var(--s3);border:1px solid var(--border);border-radius:var(--r);margin-bottom:14px">
          <div>
            <div style="font-weight:700;font-size:14px">Single-Device Panel Login</div>
            <div style="font-size:11px;color:var(--muted);margin-top:3px">Admins &amp; resellers log in from one device only</div>
          </div>
          <label class="tog"><input type="checkbox" name="single_device" value="1" <?= $panelSD?'checked':'' ?>><span class="togsl"></span></label>
        </div>
        <div class="fg">
          <label class="fl">Self-Reset Limit (per 24 hours)</label>
          <input class="fi fi-mono" type="number" name="reset_limit" min="1" max="20" value="<?= $resetLim ?>">
        </div>
        <div class="fg">
          <label class="fl">Max Devices Per Key (cap)</label>
          <input class="fi fi-mono" type="number" name="max_devices_cap" min="1" max="99999" value="<?= $maxCap ?>">
        </div>
        <button type="submit" class="btn btn-acc btn-full">SAVE PANEL LOGIN</button>
      </form>
    </div>
  </div>
</div>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
