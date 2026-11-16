<?php
// =====================================================================
//  BOT LIBRARY — Telegram API sender + keyboard/format helpers
// =====================================================================
if (!defined('BOT_LIB')) { define('BOT_LIB', 1);

/** Low-level Telegram API call (fast, 5s timeout, fire-and-read). */
function tgApi(string $method, array $params): ?array {
    $token = getSetting('tg_bot_token', '');
    if ($token === '') return null;
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TCP_FASTOPEN   => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',      // allow gzip → smaller/faster responses
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

/** Send a text message (HTML). $kb = inline keyboard array or null. */
function tgSend($chatId, string $text, ?array $kb = null): ?array {
    $p = ['chat_id'=>$chatId, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
    if ($kb !== null) $p['reply_markup'] = json_encode(['inline_keyboard'=>$kb]);
    return tgApi('sendMessage', $p);
}

/** Edit an existing message (used by inline-button navigation → no chat spam). */
function tgEdit($chatId, int $msgId, string $text, ?array $kb = null): ?array {
    $p = ['chat_id'=>$chatId, 'message_id'=>$msgId, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
    if ($kb !== null) $p['reply_markup'] = json_encode(['inline_keyboard'=>$kb]);
    return tgApi('editMessageText', $p);
}

/** Acknowledge a button tap (removes the loading spinner instantly). */
function tgAnswer(string $callbackId, string $text = '', bool $alert = false): void {
    tgApi('answerCallbackQuery', ['callback_query_id'=>$callbackId, 'text'=>$text, 'show_alert'=>$alert]);
}

/** Build an inline button. */
function btn(string $label, string $data): array { return ['text'=>$label, 'callback_data'=>$data]; }

/** Build a URL button. */
function btnUrl(string $label, string $url): array { return ['text'=>$label, 'url'=>$url]; }

/** Look up the panel user linked to this Telegram chat. Null if not logged in. */
function botUser($chatId): ?object {
    return db()->one("SELECT * FROM users WHERE tg_chat_id=? LIMIT 1", [(string)$chatId]);
}

/** Role helpers. */
function isOwner(?object $u): bool    { return $u && (int)$u->level === 1; }
function isAdmin(?object $u): bool    { return $u && (int)$u->level <= 2; } // owner or admin
function isReseller(?object $u): bool { return $u && (int)$u->level === 3; }
function roleName(int $lv): string   { return [1=>'Owner',2=>'Admin',3=>'Reseller'][$lv] ?? 'User'; }

/** Set/clear the bot conversation state for a chat (for multi-step inputs). */
function botSetState($chatId, ?string $state, string $data = ''): void {
    db()->q("UPDATE users SET tg_login_state=?, tg_state_data=? WHERE tg_chat_id=?",
        [$state, $data, (string)$chatId]);
}
/** State for a chat that hasn't logged in yet (keyed by a temp row-less store). */
function botGuestState($chatId): array {
    // Guests have no user row; use panel_settings as a tiny scratch KV.
    $s = getSetting('botguest_'.$chatId.'_state', '');
    $d = getSetting('botguest_'.$chatId.'_data', '');
    return ['state'=>$s, 'data'=>$d];
}
function botSetGuestState($chatId, string $state, string $data = ''): void {
    setSetting('botguest_'.$chatId.'_state', $state);
    setSetting('botguest_'.$chatId.'_data', $data);
}
function botClearGuestState($chatId): void {
    setSetting('botguest_'.$chatId.'_state', '');
    setSetting('botguest_'.$chatId.'_data', '');
}

/** HTML-escape for safe message bodies. */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

} // BOT_LIB
