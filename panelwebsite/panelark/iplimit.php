<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$uname = $_SESSION['uname'] ?? 'User';
if($_SERVER['REQUEST_METHOD']==='POST'&&verifyCsrf($_POST['csrf']??'')){
    header('Content-Type: application/json');
    $act=$_POST['action']??'';
    if($act==='unblock'){$id=(int)($_POST['bid']??0);db()->q("DELETE FROM blocked_ips WHERE id=?",[$id]);logAct($_SESSION['uid'],$uname,'UNBLOCK_IP',"ID:$id");echo json_encode(['ok'=>true,'msg'=>'IP unblocked.']);}
    elseif($act==='unblock_all'){db()->q("TRUNCATE TABLE blocked_ips");db()->q("TRUNCATE TABLE rate_limits");logAct($_SESSION['uid'],$uname,'UNBLOCK_ALL_IPS','');echo json_encode(['ok'=>true,'msg'=>'All IPs unblocked.']);}
    elseif($act==='block'){$ip=trim($_POST['ip']??'');if(filter_var($ip,FILTER_VALIDATE_IP)){db()->q("INSERT IGNORE INTO blocked_ips(ip,reason) VALUES(?,?)",[$ip,'Manual block']);logAct($_SESSION['uid'],$uname,'MANUAL_BLOCK_IP',$ip);echo json_encode(['ok'=>true,'msg'=>"IP {$ip} blocked."]);}else echo json_encode(['ok'=>false,'msg'=>'Invalid IP.']);}
    else echo json_encode(['ok'=>false,'msg'=>'Unknown']);exit;
}
$pageTitle='IP Management'; $activeNav='iplimit';
require_once __DIR__.'/app/Views/Layout/header.php';
$blocked=db()->all("SELECT * FROM blocked_ips ORDER BY created_at DESC LIMIT 100");
?>
<div class="card"><div class="card-h card-h-wrap"><span class="ct"><?= phIcon('shield-check') ?> Blocked IPs <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none">(<?=count($blocked)?>)</span></span>
<div class="ip-acts">
<form id="mBF" onsubmit="manualBlock(event)" class="ip-blockform"><input class="fi fi-mono" name="mip" placeholder="IP to block..."><button class="btn btn-red btn-sm">Block</button></form>
<button class="btn btn-acc btn-sm" onclick="confirmDo('Unblock ALL IPs?',unblockAll)">Unblock All</button>
</div></div>
<div class="tw"><table class="resp-table"><thead><tr><th>#</th><th>IP Address</th><th>Reason</th><th>Blocked At</th><th>Expires</th><th>Action</th></tr></thead><tbody>
<?php if(empty($blocked)): ?><tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">No blocked IPs</td></tr>
<?php else: $i=1; foreach($blocked as $b): ?>
<tr id="bi-<?=$b->id?>"><td data-label="#" style="color:var(--muted)"><?=$i++?></td>
<td data-label="IP Address" class="fi-mono"><?=htmlspecialchars($b->ip)?></td>
<td data-label="Reason" style="font-size:12px;color:var(--muted2)"><?=htmlspecialchars($b->reason??'—')?></td>
<td data-label="Blocked At" style="font-size:12px;color:var(--muted)"><?=date('d M Y H:i',strtotime($b->created_at))?></td>
<td data-label="Expires" style="font-size:12px;<?=$b->expires_at&&strtotime($b->expires_at)<time()?'color:var(--acc)':'' ?>"><?=$b->expires_at?date('d M H:i',strtotime($b->expires_at)):'<span style="color:var(--danger)">Permanent</span>'?></td>
<td data-label="Action"><button class="btn btn-acc btn-xs" onclick="unblockOne(<?=$b->id?>)">Unblock</button></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></div>
<style>
.card-h-wrap{flex-wrap:wrap;gap:10px}
.ip-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.ip-blockform{display:flex;gap:6px;align-items:center}
.ip-blockform input{width:160px;max-width:44vw;padding:8px 11px;font-size:12px}
@media(max-width:620px){
  .resp-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
  .resp-table,.resp-table tbody,.resp-table tr,.resp-table td{display:block;width:100%}
  .resp-table tr{border:1px solid var(--line);border-radius:14px;padding:6px 4px;margin-bottom:10px;background:var(--card-bg2,var(--paper))}
  .resp-table td{display:flex;justify-content:space-between;align-items:center;gap:14px;border:none!important;padding:8px 12px!important;white-space:normal!important;text-align:right}
  .resp-table td::before{content:attr(data-label);font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;text-align:left}
  .resp-table td[colspan]{justify-content:center}
  .resp-table td[colspan]::before{display:none}
}
</style>
<script>
const csrf=document.querySelector('meta[name="csrf"]').content;
async function unblockOne(id){const r=await formPost('/iplimit.php',{action:'unblock',bid:id,csrf});if(r.ok){document.getElementById('bi-'+id)?.remove();toast(r.msg,'s');}else toast(r.msg,'e');}
async function unblockAll(){const r=await formPost('/iplimit.php',{action:'unblock_all',csrf});if(r.ok){location.reload();}else toast(r.msg,'e');}
function manualBlock(e){e.preventDefault();const ip=e.target.querySelector('[name=mip]').value;formPost('/iplimit.php',{action:'block',ip,csrf}).then(r=>{if(r.ok){toast(r.msg,'s');location.reload();}else toast(r.msg,'e');});}
</script>
<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
