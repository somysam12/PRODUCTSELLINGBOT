<?php
// ── Icon system ─────────────────────────────────────────────
// One registry for the whole panel: Google Material Symbols (Rounded, filled)
// — github.com/google/material-design-icons, Apache-2.0. Data lives in icon_set.php.
// (phIcon() keeps its name; the `ph` class is just the panel's icon class.)

function phIcon(string $name, int $size = 0, string $extra = ''): string {
    static $set = null;
    if ($set === null) $set = require __DIR__.'/icon_set.php';
    if (!isset($set[$name])) return '';
    $dim = $size > 0 ? ' width="'.$size.'" height="'.$size.'"' : '';
    return '<svg class="ph" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"'.$dim.($extra !== '' ? ' '.$extra : '').'>'.$set[$name].'</svg>';
}

// Legacy helper used across pages — old names map onto the new set.
function svgIcon(string $name, int $size = 20): string {
    static $map = [
        'user' => 'user', 'lock' => 'lock-key', 'key' => 'key', 'eye' => 'eye', 'eye-off' => 'eye-slash',
        'shield' => 'shield-check', 'check' => 'check-circle', 'arrow-right' => 'arrow-right',
        'mail' => 'envelope-simple', 'phone' => 'device-mobile', 'refresh' => 'arrows-clockwise',
        'link' => 'link', 'alert' => 'warning', 'telegram' => 'telegram-logo', 'login' => 'sign-in',
        'register' => 'user-plus', 'trash' => 'trash', 'ban' => 'prohibit', 'info' => 'info', 'zap' => 'lightning',
    ];
    return phIcon($map[$name] ?? $name, $size);
}

// Cache-busting asset URL: /assets/css/panel.css?v=<mtime>. Every page uses this,
// so a deploy can never leave a browser on a stale stylesheet or script.
function asset(string $path): string {
    $file = dirname(__DIR__, 2).$path;
    return $path.'?v='.(@filemtime($file) ?: 1);
}
