<?php
/**
 * Tiny session heartbeat used by the browser when a backgrounded tab comes back.
 *
 * Deliberately does NOT call requireLogin(): it must never redirect, never run
 * the licence check, and never make an outbound call. It answers in a few
 * milliseconds so restoring a tab is instant.
 */
require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$alive = !empty($_SESSION['uid']) && !empty($_SESSION['uname']);
$idle  = isset($_SESSION['last_act']) ? (time() - (int)$_SESSION['last_act']) : 0;

if ($alive && $idle > SESSION_IDLE) {
    session_unset();
    session_destroy();
    $alive = false;
}

// Touch last_act so an open tab that is being looked at stays signed in.
if ($alive) {
    $_SESSION['last_act'] = time();
}
session_write_close();

echo json_encode([
    'ok'        => $alive,
    'idle'      => $idle,
    'expires_in'=> $alive ? max(0, SESSION_IDLE - $idle) : 0,
]);
