<?php
// =====================================================================
//  BOT HANDLERS — messages, login flow, inline menus, all actions
// =====================================================================
if (!defined('BOT_HANDLERS')) { define('BOT_HANDLERS', 1);

// ─────────────────────────────────────────────────────────────────────
//  MESSAGE ROUTER
// ─────────────────────────────────────────────────────────────────────
function handleMessage(array $msg): void {
    $chatId = $msg['chat']['id'] ?? null;
    $text   = trim($msg['text'] ?? '');
    if ($chatId === null) return;

    $u = botUser($chatId);

    // /start or /menu → show main menu (or login prompt)
    if ($text === '/start' || $text === '/menu') {
        botClearGuestState($chatId);
        if ($u) { botSetState($chatId, null); sendMainMenu($chatId, $u); }
        else    { promptLogin($chatId); }
        return;
    }
    if ($text === '/logout') {
        // NOTE: /logout does NOT unlink the Telegram. Once an account is bound to
        // a Telegram, only the OWNER can reset that link (Bot Users → Reset Link).
        // This prevents a user from logging out and re-binding a second Telegram.
        tgSend($chatId,
            "🔒 This account stays linked to <b>this</b> Telegram.\n".
            "Only the owner can move it to another Telegram (via the panel).\n\n".
            "Send /start to open your menu.");
        return;
    }

    // ── If already logged in, check for a pending multi-step state ──
    if ($u && !empty($u->tg_login_state)) {
        // For password-setting states, delete the message so the new password
        // doesn't linger in chat history.
        if (in_array($u->tg_login_state, ['setuserpass'], true) && !empty($msg['message_id'])) {
            tgApi('deleteMessage', ['chat_id'=>$chatId, 'message_id'=>$msg['message_id']]);
        }
        handleUserState($chatId, $u, $text);
        return;
    }

    // ── Guest (not logged in): drive the login state machine ──
    if (!$u) {
        $gs = botGuestState($chatId);
        if ($gs['state'] === 'await_user') {
            botSetGuestState($chatId, 'await_pass', $text); // stash username
            tgSend($chatId, "🔑 Now send your <b>password</b>:");
            return;
        }
        if ($gs['state'] === 'await_pass') {
            $username = $gs['data'];
            $password = $text;
            botClearGuestState($chatId);
            // Security: remove the password message from the chat history
            if (!empty($msg['message_id'])) {
                tgApi('deleteMessage', ['chat_id'=>$chatId, 'message_id'=>$msg['message_id']]);
            }
            doBotLogin($chatId, $username, $password);
            return;
        }
        // No state → prompt login
        promptLogin($chatId);
        return;
    }

    // Logged in, no state, plain text → show menu
    sendMainMenu($chatId, $u);
}

// ─────────────────────────────────────────────────────────────────────
//  LOGIN
// ─────────────────────────────────────────────────────────────────────
function promptLogin($chatId): void {
    botSetGuestState($chatId, 'await_user');
    $regUrl = panelBase().'/register.php';
    tgSend($chatId,
        "🔐 <b>Panel Bot Login</b>\n".str_repeat('─',22)."\n".
        "Use the <b>same username &amp; password</b> you created on the website.\n\n".
        "No account yet? Register here first:\n".e($regUrl)."\n\n".
        "👉 Send your <b>username</b>:",
        [[btnUrl('🌐 Register on Website', $regUrl)]]);
}

function doBotLogin($chatId, string $username, string $password): void {
    $username = strtolower(trim($username));
    $u = db()->one("SELECT * FROM users WHERE username=?", [$username]);
    if (!$u || !password_verify($password, $u->password)) {
        tgSend($chatId, "❌ Invalid username or password. Send /start to try again.");
        return;
    }
    if ((int)$u->status === 0) { tgSend($chatId, "🚫 This account is banned."); return; }

    // ── STRICT single-device: one Telegram per account. If this account is
    //    already linked to a DIFFERENT chat, BLOCK the new login entirely.
    //    The user must be reset from the panel (Bot Users → Reset Link) or
    //    /logout from the old Telegram first. Applies to EVERY role. ──
    if (getSetting('tg_single_device','1') === '1'
        && !empty($u->tg_chat_id)
        && (string)$u->tg_chat_id !== (string)$chatId) {
        tgSend($chatId,
            "🚫 <b>This account is already linked to another Telegram.</b>\n\n".
            "Each account can be used from only one Telegram — permanently.\n".
            "Only the <b>owner</b> can move it to a new Telegram (from the panel).\n".
            "Contact your owner to reset the link.");
        return;
    }
    // Also ensure THIS chat isn't already tied to a different account.
    $other = db()->one("SELECT username FROM users WHERE tg_chat_id=? AND id<>?", [(string)$chatId, $u->id]);
    if ($other) {
        tgSend($chatId,
            "🚫 <b>This Telegram is already linked to another account</b> (".e($other->username).").\n\n".
            "Only the owner can reset that link. One Telegram = one account.");
        return;
    }

    db()->q("UPDATE users SET tg_chat_id=?, tg_login_state=NULL, last_login=NOW() WHERE id=?",
        [(string)$chatId, $u->id]);
    logAct($u->id, $u->username, 'BOT_LOGIN', "chat_id:$chatId");

    $u = db()->one("SELECT * FROM users WHERE id=?", [$u->id]);
    tgSend($chatId, "✅ <b>Welcome, ".e($u->username)."!</b>\nRole: <b>".roleName((int)$u->level)."</b>");
    sendMainMenu($chatId, $u);
}

// ─────────────────────────────────────────────────────────────────────
//  MAIN MENU (role-aware inline keyboard)
// ─────────────────────────────────────────────────────────────────────
function sendMainMenu($chatId, object $u, ?int $editMsgId = null): void {
    $bal = number_format((float)$u->balance, 2);
    $head = "🏠 <b>Main Menu</b>\n".str_repeat('─',22)."\n".
            "👤 ".e($u->username)."  ·  <b>".roleName((int)$u->level)."</b>\n".
            "💰 Balance: <b>{$bal}</b>";

    $kb = [];
    // Everyone (owner/admin/reseller) can generate + see keys + balance
    $kb[] = [btn('🔑 Generate Key','gen_menu'), btn('📋 My Keys','keys_list:0')];
    $kb[] = [btn('📊 Key Stats','keystats'), btn('🎫 Referrals','ref_menu')];
    $kb[] = [btn('💰 Balance','bal_info'), btn('🔎 Find Key','findkey')];

    if (isAdmin($u)) { // owner + admin
        $kb[] = [btn('👥 Users','users_menu:0'), btn('➕ Add Balance','addbal_menu')];
    }
    if (isOwner($u)) {
        $kb[] = [btn('⚙️ Owner Tools','owner_menu')];
    }
    $kb[] = [btn('🔄 Refresh','menu')];

    if ($editMsgId) tgEdit($chatId, $editMsgId, $head, $kb);
    else            tgSend($chatId, $head, $kb);
}

// ─────────────────────────────────────────────────────────────────────
//  CALLBACK ROUTER (inline button taps)
// ─────────────────────────────────────────────────────────────────────
function handleCallback(array $cb): void {
    $chatId = $cb['message']['chat']['id'] ?? null;
    $msgId  = $cb['message']['message_id'] ?? null;
    $data   = $cb['data'] ?? '';
    $cbId   = $cb['id'] ?? '';
    if ($chatId === null) return;

    $u = botUser($chatId);
    if (!$u) { tgAnswer($cbId, 'Please /start to log in', true); return; }
    if ((int)$u->status === 0) { tgAnswer($cbId, 'Account banned', true); return; }

    // split "action:arg"
    $parts = explode(':', $data, 2);
    $action = $parts[0];
    $arg    = $parts[1] ?? '';

    // Route to the action dispatcher (in bot_actions.php)
    require_once __DIR__.'/bot_actions.php';
    dispatchAction($chatId, $msgId, $cbId, $u, $action, $arg);
}

// ─────────────────────────────────────────────────────────────────────
//  MULTI-STEP TEXT INPUT (when a logged-in user is mid-flow)
//  State format: "flow:step" with data in tg_state_data
// ─────────────────────────────────────────────────────────────────────
function handleUserState($chatId, object $u, string $text): void {
    require_once __DIR__.'/bot_actions.php';
    $state = $u->tg_login_state;
    $data  = $u->tg_state_data ?? '';

    if ($text === '/cancel') { botSetState($chatId, null); tgSend($chatId, "Cancelled."); sendMainMenu($chatId, $u); return; }

    switch ($state) {
        case 'gen_custom_key':   flowGenCustomKey($chatId, $u, $text, $data); break;
        case 'gen_custom_dev':   flowGenCustomDev($chatId, $u, $text, $data); break;
        case 'ref_balance':      flowRefBalance($chatId, $u, $text, $data); break;
        case 'addbal_user':      flowAddBalUser($chatId, $u, $text); break;
        case 'addbal_amount':    flowAddBalAmount($chatId, $u, $text, $data); break;
        case 'editkey_new':      flowEditKey($chatId, $u, $text, $data); break;
        case 'extendkey':        flowExtendKey($chatId, $u, $text, $data); break;
        case 'setuserpass':      flowSetUserPass($chatId, $u, $text, $data); break;
        case 'findkey':          flowFindKey($chatId, $u, $text); break;
        case 'blockuser_name':   flowBlockUser($chatId, $u, $text); break;
        default:
            botSetState($chatId, null);
            sendMainMenu($chatId, $u);
    }
}

} // BOT_HANDLERS
