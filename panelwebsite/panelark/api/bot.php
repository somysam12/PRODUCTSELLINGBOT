<?php
// =====================================================================
//  TELEGRAM BOT WEBHOOK   →   POST /api/bot.php
// ---------------------------------------------------------------------
//  Superfast, no-polling control panel over Telegram.
//  • Users register on the WEBSITE (with referral). Same username+password
//    logs them into the bot. On bot login their chat_id is stored, and the
//    website device binding + TG chat act as one single-device identity.
//  • Inline buttons drive everything — generate keys, referrals, balance,
//    block/unblock, edit/delete keys, etc. Role-gated (owner/admin/reseller).
//
//  SETUP (one time): set the webhook so Telegram pushes updates here:
//    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://YOURDOMAIN/api/bot.php&secret_token=<SECRET>
//  The <SECRET> must equal getSetting('tg_webhook_secret').
// =====================================================================

require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

// Telegram expects a fast 200. Do all work, then always echo 'ok'.
ignore_user_abort(true);
header('Content-Type: text/plain');

$BOT_TOKEN = getSetting('tg_bot_token', '');
if ($BOT_TOKEN === '') { echo 'ok'; exit; }
// Bot is considered ON if the owner enabled it. If the flag was never written
// but a token exists (e.g. webhook set before the flag), default to ON so the
// bot doesn't silently ignore every message.
$botEnabled = getSetting('tg_bot_enabled', '');
if ($botEnabled === '0') { echo 'ok'; exit; }   // explicitly disabled → ignore

// ── Verify Telegram secret header (prevents anyone POSTing fake updates) ──
$secretSet  = getSetting('tg_webhook_secret', '');
$secretRecv = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secretSet !== '' && !hash_equals($secretSet, $secretRecv)) { echo 'ok'; exit; }

// ── Read incoming update ──
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) { echo 'ok'; exit; }

require_once __DIR__.'/bot_lib.php';   // tg() sender + helpers
require_once __DIR__.'/bot_handlers.php'; // command/callback logic

try {
    if (isset($update['message']))            handleMessage($update['message']);
    elseif (isset($update['callback_query'])) handleCallback($update['callback_query']);
} catch (Throwable $e) {
    // Never leak errors to Telegram; log for the owner
    @logAct(null, 'BOT', 'BOT_ERROR', substr($e->getMessage(), 0, 200));
}

echo 'ok';
