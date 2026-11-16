<?php
// Approve Accounts page removed — redirect anyone who lands here.
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
header('Location: /users.php');
exit;
