<?php
require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
header('Content-Type: application/json');
if(!isLoggedIn()){echo json_encode(['ok'=>false]);exit;}
// Same scoping as dashboard.php: owner = whole branch, admin/reseller = own keys/balance.
$br    = currentBranch();
$own   = (int)($_SESSION['ulevel'] ?? 3) > 1;
$uname = (string)($_SESSION['uname'] ?? '');
$stats=[
  'total_keys'  => (int)($own
        ? db()->one("SELECT COUNT(*) c FROM keys_code WHERE registrator=?",[$uname])->c
        : db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[$br])->c),
  'total_users' => (int)(db()->one("SELECT COUNT(*) c FROM users WHERE branch=?",[$br])->c??0),
  'total_refs'  => (int)(db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=?",[$br])->c??0),
  'total_bal'   => (int)round((float)($own
        ? (db()->one("SELECT balance b FROM users WHERE id=?",[(int)$_SESSION['uid']])->b ?? 0)
        : (db()->one("SELECT COALESCE(SUM(balance),0) t FROM users WHERE branch=? AND level>1",[$br])->t ?? 0))),
];
echo json_encode(['ok'=>true,'stats'=>$stats]);
