<?php
// =====================================================================
//  BOT ACTIONS — every inline-button feature, role-gated
// =====================================================================
if (!defined('BOT_ACTIONS')) { define('BOT_ACTIONS', 1);

function backRow(string $to = 'menu'): array { return [btn('⬅️ Back', $to)]; }

// ── Main dispatcher ──────────────────────────────────────────────────
function dispatchAction($chatId, $msgId, $cbId, object $u, string $action, string $arg): void {
    switch ($action) {

        case 'menu':      tgAnswer($cbId); sendMainMenu($chatId, $u, $msgId); return;
        case 'logout':
            // Does NOT unlink the Telegram — the account stays bound to this chat.
            // Only the owner can reset the link from the panel (Bot Users).
            tgAnswer($cbId, 'This account stays linked to this Telegram');
            tgEdit($chatId, $msgId,
                "🔒 <b>This account is permanently linked to this Telegram.</b>\n\n".
                "Only the owner can move it to another Telegram.\n".
                "Send /start to reopen your menu.");
            return;

        // ── GENERATE ──────────────────────────────────────────────
        case 'gen_menu':      tgAnswer($cbId); genMenu($chatId, $msgId, $u); return;
        case 'gen_dur':       tgAnswer($cbId); genPickDevices($chatId, $msgId, $u, $arg); return;
        case 'gen_dev':       // device count chosen → pick key type
            tgAnswer($cbId);
            [$d,$n] = array_pad(explode('|',$arg,2),2,'1');
            genKeyType($chatId, $msgId, $u, $d, max(1,(int)$n));
            return;
        case 'gen_devcustom': tgAnswer($cbId); askCustomDev($chatId, $u, $arg); return;
        case 'gen_do':        genDoKey($chatId, $msgId, $cbId, $u, $arg); return;
        case 'gen_custom':    tgAnswer($cbId); askCustomKey($chatId, $u, $arg); return;

        // ── KEYS ──────────────────────────────────────────────────
        case 'keys_list':   tgAnswer($cbId); keysList($chatId, $msgId, $u, (int)$arg); return;
        case 'key_view':    tgAnswer($cbId); keyView($chatId, $msgId, $u, $arg); return;
        case 'key_del':     keyDelete($chatId, $msgId, $cbId, $u, $arg); return;
        case 'key_block':   keyBlock($chatId, $msgId, $cbId, $u, $arg, true); return;
        case 'key_unblock': keyBlock($chatId, $msgId, $cbId, $u, $arg, false); return;
        case 'key_reset':   keyResetDevice($chatId, $msgId, $cbId, $u, $arg); return;
        case 'key_edit':    tgAnswer($cbId); askEditKey($chatId, $u, $arg); return;
        case 'findkey':     tgAnswer($cbId); botSetState($chatId,'findkey'); tgSend($chatId,"🔎 Send the key (or part of it) to search:"); return;
        case 'keystats':    tgAnswer($cbId); keyStats($chatId, $msgId, $u); return;
        case 'key_extend':  tgAnswer($cbId); askExtendKey($chatId, $u, $arg); return;

        // ── REFERRALS ─────────────────────────────────────────────
        case 'ref_menu':    tgAnswer($cbId); refMenu($chatId, $msgId, $u); return;
        case 'ref_lvl':     tgAnswer($cbId); refPickBalance($chatId, $u, $arg); return;
        case 'ref_list':    tgAnswer($cbId); refList($chatId, $msgId, $u); return;

        // ── BALANCE ───────────────────────────────────────────────
        case 'bal_info':    tgAnswer($cbId); balInfo($chatId, $msgId, $u); return;
        case 'addbal_menu': tgAnswer($cbId); if(!isAdmin($u)){tgAnswer($cbId,'No access',true);return;} botSetState($chatId,'addbal_user'); tgSend($chatId,"➕ Send the <b>username</b> to add balance to:"); return;

        // ── USERS (admin/owner) ──────────────────────────────────
        case 'users_menu':  tgAnswer($cbId); if(!isAdmin($u)){tgAnswer($cbId,'No access',true);return;} usersList($chatId,$msgId,$u,(int)$arg); return;
        case 'user_view':   tgAnswer($cbId); if(!isAdmin($u)){return;} userView($chatId,$msgId,$u,(int)$arg); return;
        case 'user_ban':    if(!isAdmin($u)){tgAnswer($cbId,'No access',true);return;} userBan($chatId,$msgId,$cbId,$u,(int)$arg,true); return;
        case 'user_unban':  if(!isAdmin($u)){tgAnswer($cbId,'No access',true);return;} userBan($chatId,$msgId,$cbId,$u,(int)$arg,false); return;
        case 'banopt':      tgAnswer($cbId); if(!isAdmin($u)){return;} banOptions($chatId,$msgId,$u,(int)$arg); return;
        case 'bando':       // bando:uid:alsoResellers:alsoKeys
            if(!isAdmin($u)){tgAnswer($cbId,'No access',true);return;}
            $p=explode(':',$arg); 
            userBan($chatId,$msgId,$cbId,$u,(int)($p[0]??0),true,!empty($p[1]),!empty($p[2]));
            return;
        case 'setpass':     tgAnswer($cbId); if(!isOwner($u)){tgAnswer($cbId,'Owner only',true);return;} askSetPass($chatId,$u,(int)$arg); return;

        // ── OWNER TOOLS ───────────────────────────────────────────
        case 'owner_menu':  tgAnswer($cbId); if(!isOwner($u)){tgAnswer($cbId,'Owner only',true);return;} ownerMenu($chatId,$msgId,$u); return;
        case 'owner_stats': tgAnswer($cbId); if(!isOwner($u)){return;} ownerStats($chatId,$msgId,$u); return;

        default:
            tgAnswer($cbId, 'Unknown action');
    }
}

// ─── GENERATE FLOW ───────────────────────────────────────────────────
function genMenu($chatId, $msgId, object $u): void {
    $prices = db()->all("SELECT * FROM prices ORDER BY hours ASC");
    $kb = []; $row = [];
    foreach ($prices as $p) {
        $row[] = btn($p->duration, 'gen_dur:'.$p->duration);
        if (count($row) === 2) { $kb[] = $row; $row = []; }
    }
    if ($row) $kb[] = $row;
    $kb[] = backRow();
    tgEdit($chatId, $msgId, "🔑 <b>Generate Key</b>\nPick a duration:", $kb);
}

function genPickDevices($chatId, $msgId, object $u, string $duration): void {
    $oneDev = getSetting('one_device_mode','0') === '1';
    $cap    = max(1,(int)getSetting('max_devices_cap','10'));
    if ($oneDev) { // forced 1 device → go straight to key-type choice
        genKeyType($chatId, $msgId, $u, $duration, 1);
        return;
    }
    $kb = []; $row = [];
    foreach ([1,2,3,5,10,20,50,100] as $d) {
        if ($d > $cap) break;
        $row[] = btn((string)$d, 'gen_dev:'.$duration.'|'.$d);
        if (count($row) === 4) { $kb[] = $row; $row=[]; }
    }
    if ($row) $kb[] = $row;
    $kb[] = [btn('✏️ Custom device count', 'gen_devcustom:'.$duration)];
    $kb[] = [btn('⬅️ Back','gen_menu')];
    tgEdit($chatId, $msgId, "📱 <b>{$duration}</b>\nHow many devices per key?  (max {$cap})", $kb);
}

// User picked a device count → choose key type (random or custom string)
function genKeyType($chatId, $msgId, object $u, string $duration, int $dev): void {
    $txt = "🔑 <b>{$duration}</b> · <b>{$dev}</b> device".($dev>1?'s':'')."\nChoose key type:";
    $kb = [
        [btn('🎲 Random Key', 'gen_do:'.$duration.'|'.$dev)],
        [btn('✏️ Custom Key', 'gen_custom:'.$duration.'|'.$dev)],
        [btn('⬅️ Back', 'gen_dur:'.$duration)],
    ];
    if ($msgId) tgEdit($chatId, $msgId, $txt, $kb); else tgSend($chatId, $txt, $kb);
}

// Ask user to type a custom device count
function askCustomDev($chatId, object $u, string $duration): void {
    $cap = max(1,(int)getSetting('max_devices_cap','10'));
    botSetState($chatId, 'gen_custom_dev', $duration);
    tgSend($chatId, "🔢 Send the <b>number of devices</b> for this key (1 to {$cap}). /cancel to abort:");
}
function flowGenCustomDev($chatId, object $u, string $text, string $duration): void {
    $cap = max(1,(int)getSetting('max_devices_cap','10'));
    $text = trim($text);
    if (!ctype_digit($text) || (int)$text < 1) { tgSend($chatId,"❌ Enter a valid number (1 to {$cap})."); return; }
    $dev = min($cap, (int)$text);
    botSetState($chatId, null);
    // proceed to key-type choice with this device count
    genKeyType($chatId, null, $u, $duration, $dev);
}

function genDoKey($chatId, $msgId, $cbId, object $u, string $arg): void {
    // arg = "duration|devices"
    [$duration, $dev] = array_pad(explode('|', $arg, 2), 2, '1');
    genDoKeyReal($chatId, $msgId, $cbId, $u, $duration, max(1,(int)$dev), '');
}

function genDoKeyReal($chatId, $msgId, $cbId, object $u, string $duration, int $dev, string $customKey): void {
    $oneDev  = getSetting('one_device_mode','0') === '1';
    $cap     = max(1,(int)getSetting('max_devices_cap','10'));
    $dev     = $oneDev ? 1 : min($cap, max(1,$dev));
    $prefix  = getSetting('key_prefix','HB');

    $pr = db()->one("SELECT hours,price FROM prices WHERE duration=?", [$duration]);
    if (!$pr) { if($cbId) tgAnswer($cbId,'Invalid duration',true); return; }
    $hours = (int)$pr->hours;
    $cost  = (float)$pr->price * $dev;

    // Balance check for non-owner
    if ((int)$u->level > 1) {
        $bal = (float)(db()->one("SELECT balance FROM users WHERE id=?", [$u->id])->balance ?? 0);
        if ($bal < $cost) {
            if($cbId) tgAnswer($cbId, "Insufficient balance (need {$cost})", true);
            tgEdit($chatId, $msgId, "❌ Insufficient balance.\nNeed <b>{$cost}</b>, you have <b>".number_format($bal,2)."</b>.", [backRow('gen_menu')]);
            return;
        }
    }

    $expiry = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
    if ($customKey !== '') {
        $key = $customKey; // EXACT text the user typed — same for owner/admin/reseller
        if (db()->one("SELECT id FROM keys_code WHERE user_key=?", [$key])) {
            tgSend($chatId, "❌ That custom key already exists. Try another."); return;
        }
    } else {
        $key = generateKey($prefix);
        while (db()->one("SELECT id FROM keys_code WHERE user_key=?", [$key])) $key = generateKey($prefix);
    }

    // expired_date NULL → clock starts on first use, not at generation.
    db()->q("INSERT INTO keys_code(user_key,game,duration,max_devices,status,registrator,expired_date,branch) VALUES(?,?,?,?,1,?,NULL,?)",
        [$key,'PUBG',$hours,$dev,$u->username,($u->branch ?? 'main')]);
    if ((int)$u->level > 1) db()->q("UPDATE users SET balance=balance-? WHERE id=?", [$cost,$u->id]);
    logAct($u->id, $u->username, 'BOT_GENERATE_KEY', "1 key Dur:$duration Dev:$dev");

    if ($cbId) tgAnswer($cbId, 'Key generated!');
    $newBal = (int)$u->level>1 ? number_format((float)(db()->one("SELECT balance FROM users WHERE id=?",[$u->id])->balance??0),2) : '∞';
    $txt = "✅ <b>Key Generated</b>\n".str_repeat('─',22)."\n".
           "<code>".e($key)."</code>\n\n".
           "⏳ Duration: <b>{$duration}</b>\n".
           "📱 Devices: <b>{$dev}</b>\n".
           "💰 Cost: <b>".number_format($cost,2)."</b>  ·  Balance: <b>{$newBal}</b>";
    $kb = [[btn('🔑 Generate Another','gen_menu')], backRow()];
    if ($msgId) tgEdit($chatId, $msgId, $txt, $kb); else tgSend($chatId, $txt, $kb);
}

function askCustomKey($chatId, object $u, string $arg): void {
    // arg = "duration|dev"
    botSetState($chatId, 'gen_custom_key', $arg);
    [$duration,$dev] = array_pad(explode('|',$arg,2),2,'1');
    tgSend($chatId, "✏️ Send your <b>custom key</b> text.\n(<b>{$duration}</b> · <b>".max(1,(int)$dev)."</b> device".((int)$dev>1?'s':'')."). /cancel to abort:");
}
function flowGenCustomKey($chatId, object $u, string $text, string $arg): void {
    botSetState($chatId, null);
    [$duration,$dev] = array_pad(explode('|',$arg,2),2,'1');
    $ck = preg_replace('/[^A-Za-z0-9_\-]/','', $text);
    if ($ck === '') { tgSend($chatId,"❌ Invalid key text. Use letters, numbers, - or _ only."); return; }
    genDoKeyReal($chatId, null, null, $u, $duration, max(1,(int)$dev), $ck);
}

// ─── KEYS LIST / VIEW / ACTIONS ──────────────────────────────────────
function keysList($chatId, $msgId, object $u, int $page): void {
    $per = 8; $off = $page*$per;
    // Owner sees all keys; others see their own
    if (isOwner($u)) {
        $br = $u->branch ?? 'main';
        $keys = db()->all("SELECT * FROM keys_code WHERE branch=? ORDER BY id DESC LIMIT $per OFFSET $off", [$br]);
        $tot  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?", [$br])->c ?? 0);
    } else {
        $keys = db()->all("SELECT * FROM keys_code WHERE registrator=? ORDER BY id DESC LIMIT $per OFFSET $off", [$u->username]);
        $tot  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=?", [$u->username])->c ?? 0);
    }
    if (!$keys) { tgEdit($chatId,$msgId,"📋 No keys found.", [[btn('🔎 Find Key','findkey')],backRow()]); return; }

    $kb = [];
    foreach ($keys as $k) {
        $st = (int)$k->status===1 ? '🟢' : ((int)$k->status===2?'🔴':'⚪');
        $kb[] = [btn($st.' '.substr($k->user_key,0,22), 'key_view:'.$k->user_key)];
    }
    $nav = [];
    if ($page>0)                 $nav[] = btn('⬅️ Prev','keys_list:'.($page-1));
    if ($off+$per < $tot)        $nav[] = btn('Next ➡️','keys_list:'.($page+1));
    if ($nav) $kb[] = $nav;
    $kb[] = [btn('🔎 Find Key','findkey'), btn('🏠 Menu','menu')];
    tgEdit($chatId, $msgId, "📋 <b>Keys</b> ({$tot})  ·  page ".($page+1), $kb);
}

function keyView($chatId, $msgId, object $u, string $key): void {
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k) { tgEdit($chatId,$msgId,"❌ Key not found.", [backRow('keys_list:0')]); return; }
    if (!isOwner($u) && $k->registrator !== $u->username) { tgEdit($chatId,$msgId,"🚫 Not your key.", [backRow('keys_list:0')]); return; }

    $devs = (int)$k->device_count;
    $stTxt = (int)$k->status===1?'🟢 Active':((int)$k->status===2?'🔴 Blocked':'⚪ Inactive');
    $exp   = $k->expired_date ? date('d M Y H:i',strtotime($k->expired_date)) : '—';
    $txt = "🔑 <b>Key Details</b>\n".str_repeat('─',22)."\n".
           "<code>".e($k->user_key)."</code>\n\n".
           "Status: <b>{$stTxt}</b>\n".
           "Devices: <b>{$devs}/".(int)$k->max_devices."</b>\n".
           "Expires: <b>{$exp}</b>\n".
           "By: <b>".e($k->registrator)."</b>";
    $kb = [];
    if ((int)$k->status===1) $kb[] = [btn('🔴 Block','key_block:'.$key), btn('♻️ Reset Device','key_reset:'.$key)];
    else                     $kb[] = [btn('🟢 Unblock','key_unblock:'.$key), btn('♻️ Reset Device','key_reset:'.$key)];
    $kb[] = [btn('✏️ Edit Expiry','key_edit:'.$key), btn('➕ Extend','key_extend:'.$key)];
    $kb[] = [btn('🗑 Delete','key_del:'.$key)];
    $kb[] = [btn('⬅️ Back','keys_list:0'), btn('🏠 Menu','menu')];
    tgEdit($chatId, $msgId, $txt, $kb);
}

function ownsOrOwner(object $u, object $k): bool {
    if (isOwner($u)) return ($k->branch ?? 'main') === ($u->branch ?? 'main');
    return $k->registrator === $u->username;
}

// Key statistics summary
function keyStats($chatId, $msgId, object $u): void {
    $scope = isOwner($u) ? ("branch=".db()->quote($u->branch ?? 'main')) : ("registrator=".db()->quote($u->username));
    $tot   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE $scope")->c??0);
    $act   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE $scope AND status=1")->c??0);
    $blk   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE $scope AND status=2")->c??0);
    $exp   = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE $scope AND expired_date IS NOT NULL AND expired_date<NOW()")->c??0);
    $used  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE $scope AND device_count>0")->c??0);
    $txt = "📊 <b>Key Stats</b>\n".str_repeat('─',22)."\n".
           "Total: <b>{$tot}</b>\n".
           "🟢 Active: <b>{$act}</b>\n".
           "🔴 Blocked: <b>{$blk}</b>\n".
           "⌛ Expired: <b>{$exp}</b>\n".
           "📱 In-use: <b>{$used}</b>";
    tgEdit($chatId, $msgId, $txt, [[btn('📋 View Keys','keys_list:0')], backRow('menu')]);
}

function askExtendKey($chatId, object $u, string $key): void {
    botSetState($chatId,'extendkey',$key);
    tgSend($chatId, "➕ Send how many <b>days</b> to add to this key (e.g. <code>30</code>). /cancel to abort:");
}
function flowExtendKey($chatId, object $u, string $text, string $key): void {
    botSetState($chatId,null);
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k || !ownsOrOwner($u,$k)) { tgSend($chatId,"🚫 Not allowed."); return; }
    if (!ctype_digit(trim($text))) { tgSend($chatId,"❌ Enter a number of days."); return; }
    $days = (int)$text;
    if (empty($k->expired_date)) {
        // Key not activated yet → extend the stored plan length; the clock still
        // starts on first use (don't activate it early).
        db()->q("UPDATE keys_code SET duration = duration + ? WHERE id=?", [$days*24, $k->id]);
        logAct($u->id,$u->username,'BOT_EXTEND_KEY',"$key +{$days}d (pre-activation)");
        tgSend($chatId, "✅ Added <b>{$days}</b> days to the plan. The clock still starts on first login.");
        return;
    }
    $base = strtotime($k->expired_date) > time() ? strtotime($k->expired_date) : time();
    $newExp = date('Y-m-d H:i:s', $base + $days*86400);
    db()->q("UPDATE keys_code SET duration = duration + ?, expired_date=? WHERE id=?", [$days*24, $newExp, $k->id]);
    logAct($u->id,$u->username,'BOT_EXTEND_KEY',"$key +{$days}d");
    tgSend($chatId, "✅ Extended by <b>{$days}</b> days.\nNew expiry: <b>".date('d M Y H:i',strtotime($newExp))."</b>");
}

function keyDelete($chatId, $msgId, $cbId, object $u, string $key): void {
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k || !ownsOrOwner($u,$k)) { tgAnswer($cbId,'Not allowed',true); return; }
    db()->q("DELETE FROM keys_code WHERE id=?", [$k->id]);
    logAct($u->id,$u->username,'BOT_DELETE_KEY',$key);
    tgAnswer($cbId,'Key deleted');
    keysList($chatId,$msgId,$u,0);
}

function keyBlock($chatId, $msgId, $cbId, object $u, string $key, bool $block): void {
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k || !ownsOrOwner($u,$k)) { tgAnswer($cbId,'Not allowed',true); return; }
    db()->q("UPDATE keys_code SET status=? WHERE id=?", [$block?2:1, $k->id]);
    logAct($u->id,$u->username,$block?'BOT_BLOCK_KEY':'BOT_UNBLOCK_KEY',$key);
    tgAnswer($cbId, $block?'Key blocked':'Key unblocked');
    keyView($chatId,$msgId,$u,$key);
}

function keyResetDevice($chatId, $msgId, $cbId, object $u, string $key): void {
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k || !ownsOrOwner($u,$k)) { tgAnswer($cbId,'Not allowed',true); return; }
    db()->q("UPDATE keys_code SET devices=NULL, device_count=0 WHERE id=?", [$k->id]);
    logAct($u->id,$u->username,'BOT_RESET_KEY_DEVICE',$key);
    tgAnswer($cbId,'Devices reset');
    keyView($chatId,$msgId,$u,$key);
}

function askEditKey($chatId, object $u, string $key): void {
    botSetState($chatId,'editkey_new',$key);
    tgSend($chatId, "✏️ Send new expiry in <b>days from now</b> (e.g. <code>30</code>), or a date <code>YYYY-MM-DD</code>. /cancel to abort:");
}
function flowEditKey($chatId, object $u, string $text, string $key): void {
    botSetState($chatId,null);
    $k = db()->one("SELECT * FROM keys_code WHERE user_key=?", [$key]);
    if (!$k || !ownsOrOwner($u,$k)) { tgSend($chatId,"🚫 Not allowed."); return; }
    $text = trim($text);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$text)) {
        $exp = $text.' 23:59:59';
    } elseif (ctype_digit($text)) {
        $exp = date('Y-m-d H:i:s', strtotime("+{$text} days"));
    } else { tgSend($chatId,"❌ Invalid format."); return; }
    db()->q("UPDATE keys_code SET expired_date=? WHERE id=?", [$exp,$k->id]);
    logAct($u->id,$u->username,'BOT_EDIT_KEY',"$key → $exp");
    tgSend($chatId, "✅ Expiry updated to <b>".date('d M Y H:i',strtotime($exp))."</b>");
}

function flowFindKey($chatId, object $u, string $text): void {
    botSetState($chatId,null);
    $q = '%'.trim($text).'%';
    if (isOwner($u)) $rows = db()->all("SELECT user_key,status FROM keys_code WHERE user_key LIKE ? AND branch=? ORDER BY id DESC LIMIT 10",[$q,($u->branch ?? 'main')]);
    else             $rows = db()->all("SELECT user_key,status FROM keys_code WHERE user_key LIKE ? AND registrator=? ORDER BY id DESC LIMIT 10",[$q,$u->username]);
    if (!$rows) { tgSend($chatId,"🔎 No match.", [backRow('keys_list:0')]); return; }
    $kb=[];
    foreach($rows as $r){ $st=(int)$r->status===1?'🟢':((int)$r->status===2?'🔴':'⚪'); $kb[]=[btn($st.' '.substr($r->user_key,0,24),'key_view:'.$r->user_key)]; }
    $kb[]=backRow();
    tgSend($chatId, "🔎 Matches:", $kb);
}

// ─── REFERRALS ───────────────────────────────────────────────────────
function refMenu($chatId, $msgId, object $u): void {
    $kb = [];
    // Level a reseller can create depends on role. Reseller→creates users(3); admin/owner can create resellers/admins.
    if (isOwner($u))      $kb[] = [btn('Reseller','ref_lvl:3'), btn('Admin','ref_lvl:2')];
    elseif (isAdmin($u))  $kb[] = [btn('Reseller','ref_lvl:3')];
    else                  $kb[] = [btn('New User','ref_lvl:3')];
    $kb[] = [btn('📄 My Referral Codes','ref_list')];
    $kb[] = backRow();
    tgEdit($chatId, $msgId, "🎫 <b>Referrals</b>\nCreate a referral code:", $kb);
}
function refPickBalance($chatId, object $u, string $lvl): void {
    botSetState($chatId,'ref_balance',$lvl);
    tgSend($chatId, "💰 Send the starting <b>balance</b> for this referral (e.g. <code>10000</code>). /cancel to abort:");
}
function flowRefBalance($chatId, object $u, string $text, string $lvl): void {
    botSetState($chatId,null);
    if (!is_numeric($text)) { tgSend($chatId,"❌ Enter a number."); return; }
    $bal = (float)$text; $lvl = (int)$lvl; if(!in_array($lvl,[2,3],true)) $lvl=3;

    // Enforce referral limit for non-owner
    $unlimSaldo = getSetting('unlimited_saldo_referral','0')==='1';
    if (!isOwner($u)) {
        $used  = (int)$u->referrals_used; $lim = (int)$u->referral_limit;
        if ($lim>0 && $used>=$lim) { tgSend($chatId,"🚫 Referral limit reached ({$used}/{$lim})."); return; }
        // charge balance for the code's saldo — UNLESS owner enabled unlimited saldo
        if (!$unlimSaldo) {
            $myBal = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$u->id])->balance??0);
            if ($myBal < $bal) { tgSend($chatId,"❌ Not enough balance to fund this referral."); return; }
        }
    }

    $code = generateRefCode(6);
    $accExp = '30 Days';
    db()->q("INSERT INTO referral_codes(code,created_by,saldo,level,acc_expire,referral_limit,is_used,branch) VALUES(?,?,?,?,?,0,0,?)",
        [$code,$u->username,$bal,$lvl,$accExp,($u->branch ?? 'main')]);
    if (!isOwner($u)) {
        // Deduct balance only when unlimited-saldo is OFF
        if (!$unlimSaldo) db()->q("UPDATE users SET balance=balance-? WHERE id=?", [$bal,$u->id]);
        db()->q("UPDATE users SET referrals_used=referrals_used+1 WHERE id=?", [$u->id]);
    }
    logAct($u->id,$u->username,'BOT_GEN_REFERRAL',"$code lvl:$lvl bal:$bal");
    $regUrl = panelBase().'/register.php';
    tgSend($chatId,
        "✅ <b>Referral Created</b>\n".str_repeat('─',22)."\n".
        "🎫 Code: <code>".e($code)."</code>\n".
        "🔰 Role: <b>".roleName($lvl)."</b>\n".
        "💰 Balance: <b>".number_format($bal,2)."</b>\n\n".
        "Share with the new user to register at:\n".e($regUrl),
        [[btnUrl('🌐 Open Register Page',$regUrl)],backRow()]);
}
function refList($chatId, $msgId, object $u): void {
    $rows = db()->all("SELECT code,level,saldo,is_used FROM referral_codes WHERE created_by=? ORDER BY id DESC LIMIT 15",[$u->username]);
    if (!$rows) { tgEdit($chatId,$msgId,"📄 No referral codes yet.", [backRow('ref_menu')]); return; }
    $txt = "📄 <b>Your Referral Codes</b>\n".str_repeat('─',22)."\n";
    foreach($rows as $r){ $used=$r->is_used?'✅ used':'🟢 open'; $txt.="<code>".e($r->code)."</code> · ".roleName((int)$r->level)." · ".number_format((float)$r->saldo,2)." · {$used}\n"; }
    tgEdit($chatId,$msgId,$txt,[backRow('ref_menu')]);
}

// ─── BALANCE ─────────────────────────────────────────────────────────
function balInfo($chatId, $msgId, object $u): void {
    $b = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$u->id])->balance??0);
    $keys = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=?",[$u->username])->c??0);
    $txt = "💰 <b>Balance</b>\n".str_repeat('─',22)."\n".
           "Balance: <b>".number_format($b,2)."</b>\n".
           "Keys generated: <b>{$keys}</b>\n".
           "Role: <b>".roleName((int)$u->level)."</b>";
    tgEdit($chatId,$msgId,$txt,[backRow()]);
}
function flowAddBalUser($chatId, object $u, string $text): void {
    if (!isAdmin($u)) { botSetState($chatId,null); return; }
    $target = strtolower(trim($text));
    $t = db()->one("SELECT id,username,level,branch FROM users WHERE username=?", [$target]);
    if (!$t) { botSetState($chatId,null); tgSend($chatId,"❌ User not found."); return; }
    if (($t->branch ?? 'main') !== ($u->branch ?? 'main')) { botSetState($chatId,null); tgSend($chatId,"❌ User not found."); return; }
    botSetState($chatId,'addbal_amount',(string)$t->id);
    tgSend($chatId, "💰 Adding balance to <b>".e($t->username)."</b>.\nSend the <b>amount</b> (use negative to deduct):");
}
function flowAddBalAmount($chatId, object $u, string $text, string $tid): void {
    botSetState($chatId,null);
    if (!isAdmin($u)) return;
    if (!is_numeric($text)) { tgSend($chatId,"❌ Enter a number."); return; }
    $amt = (float)$text; $t = db()->one("SELECT * FROM users WHERE id=?", [(int)$tid]);
    if (!$t) { tgSend($chatId,"❌ User gone."); return; }
    // Non-owner admin can only add from their OWN balance
    if (!isOwner($u) && $amt > 0) {
        $myBal = (float)(db()->one("SELECT balance FROM users WHERE id=?",[$u->id])->balance??0);
        if ($myBal < $amt) { tgSend($chatId,"❌ Not enough balance to transfer."); return; }
        db()->q("UPDATE users SET balance=balance-? WHERE id=?", [$amt,$u->id]);
    }
    db()->q("UPDATE users SET balance=balance+? WHERE id=?", [$amt,$t->id]);
    logAct($u->id,$u->username,'BOT_ADD_BALANCE',"{$t->username} {$amt}");
    $nb = number_format((float)(db()->one("SELECT balance FROM users WHERE id=?",[$t->id])->balance??0),2);
    tgSend($chatId, "✅ Balance updated.\n<b>".e($t->username)."</b> now has <b>{$nb}</b>.", [[btn('➕ Add More','addbal_menu')],[btn('👥 Users','users_menu:0'),btn('🏠 Menu','menu')]]);
}

// ─── USERS (admin/owner) ─────────────────────────────────────────────
function usersList($chatId, $msgId, object $u, int $page): void {
    $per=8; $off=$page*$per;
    // Owner sees all; admin sees users they created + their resellers
    if (isOwner($u)) {
        $br = $u->branch ?? 'main';
        $rows = db()->all("SELECT id,username,level,status FROM users WHERE level>1 AND branch=? ORDER BY id DESC LIMIT $per OFFSET $off", [$br]);
        $tot  = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE level>1 AND branch=?", [$br])->c??0);
    } else {
        $rows = db()->all("SELECT id,username,level,status FROM users WHERE ref_giver=? ORDER BY id DESC LIMIT $per OFFSET $off",[$u->username]);
        $tot  = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE ref_giver=?",[$u->username])->c??0);
    }
    if (!$rows) { tgEdit($chatId,$msgId,"👥 No users.", [backRow()]); return; }
    $kb=[];
    foreach($rows as $r){ $st=(int)$r->status===1?'🟢':'🔴'; $kb[]=[btn($st.' '.$r->username.' ('.roleName((int)$r->level).')','user_view:'.$r->id)]; }
    $nav=[];
    if($page>0) $nav[]=btn('⬅️ Prev','users_menu:'.($page-1));
    if($off+$per<$tot) $nav[]=btn('Next ➡️','users_menu:'.($page+1));
    if($nav)$kb[]=$nav;
    $kb[]=backRow();
    tgEdit($chatId,$msgId,"👥 <b>Users</b> ({$tot})",$kb);
}
function userView($chatId, $msgId, object $u, int $uid): void {
    $t = db()->one("SELECT * FROM users WHERE id=?", [$uid]);
    if (!$t) { tgEdit($chatId,$msgId,"❌ Not found.",[backRow('users_menu:0')]); return; }
    if (($t->branch ?? "main") !== ($u->branch ?? "main")) { tgEdit($chatId,$msgId,"🚫 Not found.",[backRow("users_menu:0")]); return; }
    if (!isOwner($u) && $t->ref_giver !== $u->username) { tgEdit($chatId,$msgId,"🚫 Not your user.",[backRow("users_menu:0")]); return; }
    $st = (int)$t->status===1?'🟢 Active':'🔴 Banned';
    $txt = "👤 <b>".e($t->username)."</b>\n".str_repeat('─',22)."\n".
           "Role: <b>".roleName((int)$t->level)."</b>\n".
           "Status: <b>{$st}</b>\n".
           "Balance: <b>".number_format((float)$t->balance,2)."</b>\n".
           "TG linked: <b>".($t->tg_chat_id?'yes':'no')."</b>";
    $kb=[];
    if((int)$t->status===1) $kb[]=[btn('🔴 Ban User','banopt:'.$uid)];
    else                    $kb[]=[btn('🟢 Unban','user_unban:'.$uid)];
    if(isOwner($u)) $kb[]=[btn('🔑 Change Password','setpass:'.$uid)];
    $kb[]=[btn('⬅️ Back','users_menu:0'), btn('🏠 Menu','menu')];
    tgEdit($chatId,$msgId,$txt,$kb);
}

// Ban options screen — choose what to cascade
function banOptions($chatId, $msgId, object $u, int $uid): void {
    $t = db()->one("SELECT username,level,branch,ref_giver FROM users WHERE id=?", [$uid]);
    if (!$t || ($t->branch ?? 'main')!==($u->branch ?? 'main')) { tgEdit($chatId,$msgId,"❌ Not found.",[backRow('users_menu:0')]); return; }
    if (!isOwner($u) && $t->ref_giver !== $u->username) { tgEdit($chatId,$msgId,"🚫 Not your user.",[backRow('users_menu:0')]); return; }
    $lvl=(int)$t->level;
    $txt="🔴 <b>Ban ".e($t->username)."</b>\n".str_repeat('─',22)."\nChoose how to ban:";
    $kb=[];
    if ($lvl===2) {
        // admin: user only / +keys / +resellers / +resellers +keys
        $kb[]=[btn('Admin only','bando:'.$uid.':0:0')];
        $kb[]=[btn('Admin + their own keys','bando:'.$uid.':0:1')];
        $kb[]=[btn('Admin + resellers (+reseller keys)','bando:'.$uid.':1:0')];
        $kb[]=[btn('Admin + resellers + ALL keys','bando:'.$uid.':1:1')];
    } elseif ($lvl===3) {
        $kb[]=[btn('Reseller only','bando:'.$uid.':0:0')];
        $kb[]=[btn('Reseller + their keys','bando:'.$uid.':0:1')];
    } else {
        $kb[]=[btn('Ban','bando:'.$uid.':0:0')];
    }
    $kb[]=[btn('⬅️ Cancel','user_view:'.$uid)];
    tgEdit($chatId,$msgId,$txt,$kb);
}
function userBan($chatId, $msgId, $cbId, object $u, int $uid, bool $ban, bool $alsoResellers=true, bool $alsoKeys=true): void {
    $t = db()->one("SELECT * FROM users WHERE id=?", [$uid]);
    if (!$t) { tgAnswer($cbId,'Not found',true); return; }
    if (($t->branch ?? 'main') !== ($u->branch ?? 'main')) { tgAnswer($cbId,'Not found',true); return; }
    if (!isOwner($u) && $t->ref_giver !== $u->username) { tgAnswer($cbId,'Not allowed',true); return; }
    if ((int)$t->level === 1) { tgAnswer($cbId,'Cannot ban owner',true); return; }

    db()->q("UPDATE users SET status=? WHERE id=?", [$ban?0:1, $uid]);
    if ((int)$t->level === 2) {
        $names=[$t->username];
        if ($alsoResellers) {
            $subs = db()->all("SELECT username FROM users WHERE ref_giver=?", [$t->username]);
            $resellerNames=[];
            foreach($subs as $s){ $names[]=$s->username; $resellerNames[]=$s->username; }
            db()->q("UPDATE users SET status=? WHERE ref_giver=?", [$ban?0:1, $t->username]);
            // Banning resellers ALWAYS toggles their keys with them
            if ($resellerNames) {
                $rp=implode(',',array_fill(0,count($resellerNames),'?'));
                if ($ban) db()->q("UPDATE keys_code SET status=0 WHERE registrator IN ($rp)", $resellerNames);
                else      db()->q("UPDATE keys_code SET status=1 WHERE registrator IN ($rp) AND (expired_date IS NULL OR expired_date>NOW())", $resellerNames);
            }
        }
        if ($alsoKeys) {
            // additionally toggle the admin's OWN keys
            if ($ban) db()->q("UPDATE keys_code SET status=0 WHERE registrator=?", [$t->username]);
            else      db()->q("UPDATE keys_code SET status=1 WHERE registrator=? AND (expired_date IS NULL OR expired_date>NOW())", [$t->username]);
        }
    } elseif ((int)$t->level === 3) {
        if ($alsoKeys) db()->q("UPDATE keys_code SET status=? WHERE registrator=?", [$ban?0:1, $t->username]);
    }
    logAct($u->id,$u->username,$ban?'BOT_BAN_USER':'BOT_UNBAN_USER',"ID:$uid R:".($alsoResellers?1:0)." K:".($alsoKeys?1:0));
    tgAnswer($cbId, $ban?'Banned':'Unbanned');
    userView($chatId,$msgId,$u,$uid);
}

// Password change flow (OWNER ONLY)
function askSetPass($chatId, object $u, int $uid): void {
    if (!isOwner($u)) { tgSend($chatId,"🚫 Only the owner can change passwords."); return; }
    $t = db()->one("SELECT username,branch,level,ref_giver FROM users WHERE id=?", [$uid]);
    if (!$t || ($t->branch ?? 'main')!==($u->branch ?? 'main')) { tgSend($chatId,"❌ Not found."); return; }
    botSetState($chatId,'setuserpass',(string)$uid);
    tgSend($chatId, "🔑 Send the new password for <b>".e($t->username)."</b> (min 4 chars). /cancel to abort:");
}
function flowSetUserPass($chatId, object $u, string $text, string $uid): void {
    botSetState($chatId,null);
    if (!isOwner($u)) { tgSend($chatId,"🚫 Not allowed."); return; }
    $t = db()->one("SELECT username,branch,level,ref_giver FROM users WHERE id=?", [(int)$uid]);
    if (!$t || ($t->branch ?? 'main')!==($u->branch ?? 'main')) { tgSend($chatId,"❌ Not found."); return; }
    if (strlen(trim($text)) < 4) { tgSend($chatId,"❌ Password must be at least 4 characters."); return; }
    $hash = password_hash(trim($text), PASSWORD_BCRYPT);
    db()->q("UPDATE users SET password=? WHERE id=?", [$hash,(int)$uid]);
    logAct($u->id,$u->username,'BOT_SET_PASSWORD',"Changed password for {$t->username}");
    tgSend($chatId, "✅ Password changed for <b>".e($t->username)."</b>.");
}
function flowBlockUser($chatId, object $u, string $text): void { botSetState($chatId,null); }

// ─── OWNER TOOLS ─────────────────────────────────────────────────────
function ownerMenu($chatId, $msgId, object $u): void {
    $kb = [
        [btn('📊 Panel Stats','owner_stats')],
        [btn('👥 All Users','users_menu:0')],
        [btn('📋 All Keys','keys_list:0')],
        backRow(),
    ];
    tgEdit($chatId,$msgId,"⚙️ <b>Owner Tools</b>",$kb);
}
function ownerStats($chatId, $msgId, object $u): void {
    $br = $u->branch ?? 'main';
    $users = (int)(db()->one("SELECT COUNT(*) c FROM users WHERE branch=?",[$br])->c??0);
    $keys  = (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[$br])->c??0);
    $active= (int)(db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=? AND status=1",[$br])->c??0);
    $banned= (int)(db()->one("SELECT COUNT(*) c FROM users WHERE branch=? AND status=0",[$br])->c??0);
    $txt = "📊 <b>Panel Stats</b>\n".str_repeat('─',22)."\n".
           "👥 Users: <b>{$users}</b> (banned {$banned})\n".
           "🔑 Keys: <b>{$keys}</b> (active {$active})";
    tgEdit($chatId,$msgId,$txt,[backRow('owner_menu')]);
}

} // BOT_ACTIONS
