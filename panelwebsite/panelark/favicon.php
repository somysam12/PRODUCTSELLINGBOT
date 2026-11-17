<?php
// Browsers (and Chrome's address bar / history) often request /favicon.ico
// directly, ignoring the <link rel="icon">. Serve the owner's uploaded brand
// logo here so the tab + suggestion icons use it; fall back to the default SVG.
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

$logo = trim((string)getSetting('panel_logo', ''));
$target = $logo !== '' ? $logo : '/assets/favicon.svg';

// Long cache so browsers keep it, but it changes when the logo path changes.
header('Cache-Control: public, max-age=86400');
header('Location: ' . $target, true, 302);
exit;
