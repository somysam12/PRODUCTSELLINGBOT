<?php
// =====================================================================
// SHARED SECRETS  —  MUST be identical in the APK (Java) and native lib
// =====================================================================
// NOTE: The AES key MUST be exactly 64 hex chars (32 bytes) for AES-256.
// The value in the old 3Steps.md guide was 63 chars (invalid) which made
// hex2bin() fail and broke encryption. Use THIS key everywhere instead.
// =====================================================================

if (!defined('HB_AES_KEY')) {
    // 32-byte key (64 hex chars) — AES-256-GCM
    define('HB_AES_KEY',  '5c3d9f2a8b1e4c6079d2f3a4b5c6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8');

    // HMAC-SHA256 request authentication key (any length)
    define('HB_HMAC_KEY', 'X9kL2mQ7vR4nB1eY3pT8wS5uJ6dA0cF');

    // XOR masks for the heartbeat response fields
    define('HB_XKEY1',    0xB7E15163);
    define('HB_XKEY2',    0x9E3779B9);

    // Separate secret used ONLY to sign the lib-download grant token.
    // (Distinct from the heartbeat keys so a leak of one does not affect the other.)
    define('UNLOCK_SECRET', 'e1f2a3b4c5d6079889a0b1c2d3e4f5061728394a5b6c7d8e9f0a1b2c3d4e5f60');

    // Only these libs are allowed to be loaded / delivered
    define('LIB_ALLOWED', 'libshaitaan.so,libEliteCore.so,libbgmi.so,libc++_shared.so');
}

// ── Shared AES-256-GCM helpers (base64url: nonce[12] + tag[16] + ct) ──
if (!function_exists('hb_aes_encrypt')) {
    function hb_aes_encrypt(string $plain): string {
        $key   = hex2bin(HB_AES_KEY);
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) return '';
        return rtrim(strtr(base64_encode($nonce.$tag.$ct), '+/', '-_'), '=');
    }
    function hb_aes_decrypt(string $b64url): ?string {
        $b64 = strtr($b64url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $key   = hex2bin(HB_AES_KEY);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key,
                    OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
    // Deterministic grant token — binds a valid key to a client nonce.
    // Contains NO date/expiry — pure keyed hash, unguessable without UNLOCK_SECRET.
    function hb_grant(string $key, string $nonce): string {
        return hash_hmac('sha256', $key.'|'.$nonce, UNLOCK_SECRET);
    }
}

// ── HMAC-AUTHENTICATED protocol (v2) — no AES key needed ─────────────────
// The request/response are NOT encrypted with a pre-shared AES key (that key
// was the single point of failure — one wrong byte in the compiled lib and
// nothing decrypts). Instead:
//   • Confidentiality  → provided by HTTPS/TLS (the whole POST is already TLS).
//   • Authenticity      → provided by HMAC-SHA256 with HB_HMAC_KEY.
// A fake/patched server cannot forge a valid signed response without HB_HMAC_KEY,
// so the app's "valid" verdict is still SERVER-decided and un-spoofable.
if (!function_exists('hb_b64url_encode')) {
    function hb_b64url_encode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    function hb_b64url_decode(string $s): ?string {
        $b64 = strtr($s, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($b64, true);
        return $raw === false ? null : $raw;
    }
    // Sign a payload → "base64url(payload).hmachex". The lib verifies the HMAC
    // before trusting it, so a server without HB_HMAC_KEY cannot fake a q=7.
    // (v3 HMAC-only: body is plaintext base64url, protected by TLS.)
    function hb_sign(string $plain): string {
        $body = hb_b64url_encode($plain);
        return $body.'.'.hash_hmac('sha256', $body, HB_HMAC_KEY);
    }
    // ENCRYPTED signed response (v4) → "base64url(nonce|tag|ct).hmachex".
    // Body is AES-256-GCM ciphertext (payload NOT readable on the wire or in
    // logs). A fake server can forge neither the GCM tag nor the outer HMAC.
    function hb_sign_aes(string $plain): string {
        $body = hb_aes_encrypt($plain);            // base64url(nonce|tag|ct)
        return $body.'.'.hash_hmac('sha256', $body, HB_HMAC_KEY);
    }
}
