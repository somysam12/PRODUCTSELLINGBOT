<?php
require_once dirname(__DIR__).'/app/Config/db.php';
require_once dirname(__DIR__).'/app/Helpers/security.php';
header('Content-Type: application/json');
if(!isLoggedIn()){echo json_encode(['ok'=>false]);exit;}
$br = currentBranch();
$stats=[
  'total_keys'  => db()->one("SELECT COUNT(*) c FROM keys_code WHERE branch=?",[$br])->c??0,
  'total_users' => db()->one("SELECT COUNT(*) c FROM users WHERE branch=?",[$br])->c??0,
  'total_refs'  => db()->one("SELECT COUNT(*) c FROM referral_codes WHERE branch=?",[$br])->c??0,
  'total_bal'   => '$'.number_format((float)(db()->one("SELECT COALESCE(SUM(balance),0) t FROM users WHERE branch=?",[$br])->t??0),2)
];
echo json_encode(['ok'=>true,'stats'=>$stats]);
