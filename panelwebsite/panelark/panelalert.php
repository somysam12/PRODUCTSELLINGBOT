<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'User';

$msg=''; $msgType='ok';
if($_SERVER['REQUEST_METHOD']==='POST'&&verifyCsrf($_POST['csrf']??'')){
    $token=trim($_POST['tg_bot_token']??'');
    $chatId=trim($_POST['tg_chat_id']??'');
    $enabled=isset($_POST['tg_enabled'])?'1':'0';
    $prevToken = getSetting('tg_bot_token','');
    // Never wipe a saved bot token when the field is submitted blank (e.g. saving
    // other settings, or a browser that didn't repopulate it) — keep the old one.
    if ($token === '') $token = $prevToken;
    setSetting('tg_bot_token',$token);
    setSetting('tg_chat_id',$chatId);
    setSetting('tg_alerts_enabled',$enabled);
    // Bot control settings
    $botOn = isset($_POST['tg_bot_enabled'])?'1':'0';
    setSetting('tg_bot_enabled', $botOn);
    setSetting('tg_single_device', isset($_POST['tg_single_device'])?'1':'0');
    logAct($_SESSION['uid'],$uname,'UPDATE_TG_ALERT','');

    // ── Webhook helper: registers this panel as the bot's webhook ──
    $setWebhook = function(string $token) : array {
        if ($token === '') return ['ok'=>false,'description'=>'No bot token set'];
        $secret = getSetting('tg_webhook_secret','');
        if($secret===''){ $secret = bin2hex(random_bytes(16)); setSetting('tg_webhook_secret',$secret); }
        $hookUrl = panelBase().'/api/bot.php';
        $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query([
                'url'=>$hookUrl,
                'secret_token'=>$secret,
                'allowed_updates'=>json_encode(['message','callback_query']),
                'drop_pending_updates'=>'true',
            ]),
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        $res = json_decode(curl_exec($ch),true); curl_close($ch);
        $res['_url'] = $hookUrl;
        return is_array($res) ? $res : ['ok'=>false,'description'=>'No response from Telegram'];
    };

    // AUTO webhook: fire whenever a token is present, bot is ON, and either the
    // user pressed the button OR the token just changed. No manual step needed.
    $tokenChanged = ($token !== '' && $token !== $prevToken);
    $wantWebhook  = !empty($_POST['setup_webhook']) || ($token !== '' && $botOn === '1' && $tokenChanged);

    if ($wantWebhook) {
        $res = $setWebhook($token);
        if(!empty($res['ok'])){
            // Connecting the webhook implicitly enables the bot — otherwise
            // bot.php would silently ignore every update (the "no response to
            // /start" bug). Force it ON whenever the webhook is set.
            setSetting('tg_bot_enabled','1');
            $botOn = '1';
            $msg="Bot webhook connected & bot ENABLED! → {$res['_url']}"; $msgType='ok';
        }
        else { $msg='Saved, but webhook setup failed: '.($res['description']??'unknown').' (need HTTPS domain)'; $msgType='err'; }
    }
    // Test alert if requested
    elseif(!empty($_POST['test_alert'])&&$token&&$chatId){
        $testMsg="<b>Panel Alert Test</b>\n".str_repeat('─',28)."\n";
        $testMsg.="<b>Status:</b> Connected\n";
        $testMsg.="<b>Panel:</b> ".getSetting('panel_name','HB Panel')."\n";
        $testMsg.="<b>Time:</b> ".date('d M Y, h:i:s A');
        sendTgAlert($testMsg);
        $msg='Settings saved & test alert sent!'; $msgType='ok';
    } else {
        $msg = $botOn==='1'
            ? 'Settings saved. Bot is ON.'
            : 'Settings saved.';
        $msgType='ok';
    }
    // PRG: flash + redirect so refresh/resume never re-POSTs
    $_SESSION['pa_flash']=['msg'=>$msg,'type'=>$msgType];
    header('Location: /panelalert.php'); exit;
}
// GET: pull flash
if(!empty($_SESSION['pa_flash'])){ $msg=$_SESSION['pa_flash']['msg']; $msgType=$_SESSION['pa_flash']['type']; unset($_SESSION['pa_flash']); }

$pageTitle='Panel Alert'; $activeNav='panelalert';
require_once __DIR__.'/app/Views/Layout/header.php';
require_once __DIR__.'/app/Helpers/icons.php';

$tgToken  = getSetting('tg_bot_token','');
$tgChatId = getSetting('tg_chat_id','');
$tgEnabled= getSetting('tg_alerts_enabled','0')==='1';
$tgBotEnabled = getSetting('tg_bot_enabled','0')==='1';
$tgSingleDev  = getSetting('tg_single_device','1')==='1';
$tgHookSecret = getSetting('tg_webhook_secret','');
$tgHookUrl    = panelBase().'/api/bot.php';

// ── Live webhook status from Telegram (cached 60s so the page loads fast) ──
$hookConnected = false; $hookInfo = '';
if ($tgToken !== '') {
    $cacheRaw = getSetting('tg_hookinfo_cache','');
    $cache = $cacheRaw ? json_decode($cacheRaw,true) : null;
    if ($cache && !empty($cache['t']) && (time()-$cache['t'] < 60)) {
        $hookConnected = !empty($cache['connected']);
        $hookInfo = $cache['info'] ?? '';
    } else {
        $ch = curl_init("https://api.telegram.org/bot{$tgToken}/getWebhookInfo");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>false]);
        $wi = json_decode(curl_exec($ch),true); curl_close($ch);
        if (!empty($wi['ok']) && !empty($wi['result']['url'])) {
            $hookConnected = ($wi['result']['url'] === $tgHookUrl);
            $hookInfo = $wi['result']['url'];
            if (!empty($wi['result']['last_error_message'])) $hookInfo .= ' — last error: '.$wi['result']['last_error_message'];
        }
        setSetting('tg_hookinfo_cache', json_encode(['t'=>time(),'connected'=>$hookConnected,'info'=>$hookInfo]));
    }
}
?>
<?php if($msg): ?><div class="alert a-<?=$msgType?>"><?=svgIcon('check',14)?> <?=htmlspecialchars($msg)?></div><?php endif; ?>

<div class="g2">
  <div class="card">
    <div class="card-h">
      <span class="ct"><?=svgIcon('telegram',14)?> Telegram Alert Settings</span>
      <span class="owner-only"><?=svgIcon('shield',12)?> Owner Only</span>
    </div>
    <div class="card-b">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:var(--blue-bg);border:1px solid rgba(53,103,196,.3);border-radius:var(--r);margin-bottom:18px">
        <div>
          <div style="font-weight:700;font-size:13px">Telegram Alerts</div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Send alerts to your Telegram bot</div>
        </div>
        <label class="tog"><input type="checkbox" id="tgEnabledTog" <?=$tgEnabled?'checked':''?>><span class="togsl"></span></label>
      </div>

      <form method="POST">
        <?=csrfField()?>
        <input type="hidden" name="tg_enabled" id="tgEnabledHidden" value="<?=$tgEnabled?'1':'0'?>">

        <div class="fg">
          <label class="fl"><?=svgIcon('key',12)?> Bot Token</label>
          <div style="display:flex;align-items:center;background:var(--sunk);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <span style="padding:0 12px;color:var(--muted);flex-shrink:0"><?=svgIcon('key',15)?></span>
            <input class="fi fi-mono" type="text" name="tg_bot_token" value="<?=htmlspecialchars($tgToken)?>" placeholder="1234567890:AAF..." style="border:none;border-radius:0;padding-left:0">
          </div>
          <div class="fi-hint">Get from @BotFather on Telegram</div>
        </div>

        <div class="fg">
          <label class="fl"><?=svgIcon('mail',12)?> Chat ID</label>
          <div style="display:flex;align-items:center;background:var(--sunk);border:1px solid var(--border);border-radius:var(--r2);overflow:hidden">
            <span style="padding:0 12px;color:var(--muted);flex-shrink:0"><?=svgIcon('mail',15)?></span>
            <input class="fi fi-mono" type="text" name="tg_chat_id" value="<?=htmlspecialchars($tgChatId)?>" placeholder="7182189844" style="border:none;border-radius:0;padding-left:0">
          </div>
          <div class="fi-hint">Your Telegram user/group Chat ID</div>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

        <!-- ── BOT CONTROL (webhook-driven, superfast) ── -->
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--acc);margin-bottom:12px">🤖 Bot Control Panel</div>

        <div class="srow">
          <div>
            <div style="font-weight:700;font-size:13px">Enable Bot Control</div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">Manage panel from Telegram (generate, referrals, users…)</div>
          </div>
          <label class="tog"><input type="checkbox" name="tg_bot_enabled" value="1" <?= $tgBotEnabled?'checked':'' ?>><span class="togsl"></span></label>
        </div>

        <div class="srow">
          <div>
            <div style="font-weight:700;font-size:13px">Single-Device Bot Login</div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">One Telegram per account (mirrors website)</div>
          </div>
          <label class="tog"><input type="checkbox" name="tg_single_device" value="1" <?= $tgSingleDev?'checked':'' ?>><span class="togsl"></span></label>
        </div>

        <div class="fg" style="margin-top:12px">
          <label class="fl">Webhook URL <span style="color:var(--muted)">(auto-set on save)</span></label>
          <div class="code-box2"><?= htmlspecialchars($tgHookUrl) ?></div>
          <div style="margin-top:8px">
            <?php if($hookConnected): ?>
              <span class="bx b-active" style="font-size:11px">● Webhook Connected — bot is live</span>
            <?php elseif($tgToken===''): ?>
              <span class="bx b-reseller" style="font-size:11px">○ Add bot token &amp; save to connect</span>
            <?php else: ?>
              <span class="bx b-ban" style="font-size:11px">○ Not connected — save with Bot Control ON (needs HTTPS)</span>
            <?php endif; ?>
          </div>
          <div class="fi-hint">Saving your token with <b>Bot Control ON</b> registers this webhook automatically. Telegram then pushes updates here instantly — no polling, zero delay.</div>
        </div>

        <div style="display:flex;gap:10px;margin-top:6px">
          <button type="submit" class="btn btn-acc" style="flex:1"><?=svgIcon('check',13)?> Save &amp; Connect Bot</button>
          <button type="submit" name="test_alert" value="1" class="btn btn-blue" style="flex:1"><?=svgIcon('telegram',13)?> Test Alert</button>
        </div>
        <button type="submit" name="setup_webhook" value="1" class="btn btn-full" style="margin-top:10px;background:linear-gradient(135deg,#229ED9,#0088cc);color:#fff"><?=svgIcon('telegram',14)?> Re-Connect Webhook (manual)</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span class="ct"><?=svgIcon('info',14)?> Alert Events</span></div>
    <div class="card-b">
      <div class="srow"><span style="display:flex;align-items:center;gap:6px"><?=svgIcon('link',13)?> Referral Created</span><span class="bx b-active">Active</span></div>
      <div class="srow"><span style="display:flex;align-items:center;gap:6px"><?=svgIcon('ban',13)?> DDoS Attack Blocked</span><span class="bx b-active">Active</span></div>

      <div class="sep" style="margin:16px 0"></div>

      <div style="background:var(--blue-bg);border:1px solid rgba(53,103,196,.3);border-radius:var(--r);padding:14px;font-size:11px;color:var(--muted2);line-height:1.8">
        <?=svgIcon('info',12)?> <strong style="color:var(--acc2)">How to setup:</strong><br>
        1. Create bot via <strong>@BotFather</strong><br>
        2. Copy the <strong>Bot Token</strong><br>
        3. Get your <strong>Chat ID</strong> from @userinfobot<br>
        4. Paste both above and Save<br>
        5. Click <strong>Test Alert</strong> to verify
      </div>

      <div style="margin-top:14px;padding:12px;background:var(--acc-bg);border:1px solid var(--acc-20);border-radius:var(--r)">
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Alert Status</div>
        <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:<?=$tgEnabled?'var(--acc)':'var(--danger)'?>">
          <span style="width:8px;height:8px;border-radius:50%;background:currentColor;display:inline-block"></span>
          <?=$tgEnabled?'ALERTS ACTIVE':'ALERTS DISABLED'?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById("tgEnabledTog").addEventListener("change",function(){
  document.getElementById("tgEnabledHidden").value=this.checked?"1":"0";
});
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
