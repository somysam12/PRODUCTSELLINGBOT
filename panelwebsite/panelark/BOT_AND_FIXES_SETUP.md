# KuroFileArk — Fixes + Telegram Bot Setup

## 1. Deploy
1. Upload all files, overwriting the old panel.
2. **Run `master_setup.sql` again** in phpMyAdmin (it's safe — uses
   `IF NOT EXISTS`). This adds the Telegram columns to `users`.
3. Make sure `server/libs/` and `server/apks/` are writable (0755/0775).

## 2. What changed
- **Session** now expires after **30 minutes** idle → re-login.
- **"Confirm Form Resubmission"** fixed on generate / random keys /
  register / reset-device / panel-alert (PRG pattern). Reopening Chrome
  from recents continues the page instead of that error.
- **One Device page**: instructions removed, clean single-column UI,
  device cap now **1–99999** (was 999).
- **Block admin** now cascades: bans all their resellers **and** disables
  every key under the admin + those resellers. Unban restores them.
  Banning a reseller disables that reseller's keys.
- **Change password**: account-page AJAX is now session-expiry safe
  (returns a clean "session expired" instead of silently failing).
  > If it still says "current password wrong" on a fresh install, the
  > seeded owner hash is a placeholder — set a real password via the
  > setup script / phpMyAdmin first.
- **Server logs delete** fixed (was calling a non-existent DB method).
- **Overlapping** boxes/text fixed panel-wide via CSS safety net.

## 3. Multi-Lib (3 slots)
Manage Rebranding → **Lib** tab now has **3 upload slots**. Each slot keeps
its **own fixed secure link** even after you re-upload. Copy the link shown
under each slot and use it in your APK. Downloads stay **key-gated** through
`/secure/unlock` + `/secure/lib` — no valid key, no download.

## 4. Telegram Bot (webhook — instant, no polling)
1. Create a bot with **@BotFather**, copy the token.
2. Panel → **Panel Alert**: paste the **Bot Token** (and your Chat ID for
   alerts), turn on **Enable Bot Control**, then click **Save & Connect Bot**.
   - The webhook is set **automatically** on save — no extra step.
   - Your panel must be on **HTTPS**. The page shows a live status:
     **● Webhook Connected** means the bot is live.
   - If it ever shows "Not connected", press **Re-Connect Webhook (manual)**.
3. In Telegram, open your bot and send **/start**.

### How a user logs in to the bot
1. They **register on the website** first (with a referral code) — this
   creates their username + password.
2. In Telegram they open the bot and send **/start**.
3. The bot asks for their **username** → they send it.
4. The bot asks for their **password** → they send it (the bot deletes the
   password message from the chat right after, for safety).
5. Done — their Telegram is linked (one Telegram per account) and the full
   button menu appears.

> Keys are shared: a key made in the bot shows up in the panel's All Keys,
> and a key made in the panel shows up in the bot — it's the same database
> and the same `keys_code` table. Every key works everywhere (app included).

### Buttons / features
- **Reseller:** Generate key (duration → devices, or custom key),
  My Keys (view / block / unblock / delete / edit expiry / reset device,
  search), Referrals (create + list), Balance.
- **Admin:** everything above **plus** Users (view, ban-with-cascade,
  unban) and Add Balance.
- **Owner:** everything **plus** Owner Tools (panel stats, all users,
  all keys).

Commands: `/start` or `/menu` (open menu), `/logout`, `/cancel`
(abort a multi-step input).

### Turning it off
Panel Alert → turn **Enable Bot Control** off. The webhook stays registered
but the bot ignores all updates.
