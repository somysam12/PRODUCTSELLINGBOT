<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// AJAX handler
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    $unm = $_SESSION['uname']??'';
    if ($ulv > 2) { echo json_encode(['ok'=>false,'msg'=>'Access denied']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }

    $target = trim($_POST['target_user']??'');
    $amount = max(0,(float)($_POST['amount']??0));
    $action = $_POST['action']??'';

    if (!$target||!$amount||!in_array($action,['add','deduct'])) {
        echo json_encode(['ok'=>false,'msg'=>'Fill all fields']); exit;
    }

    // Find target user
    $u = db()->one("SELECT * FROM users WHERE username=?",[$target]);
    if (!$u) { echo json_encode(['ok'=>false,'msg'=>'User not found']); exit; }

    // ── Branch isolation: only act on users in your OWN branch ──
    if (($u->branch ?? 'main') !== currentBranch()) {
        echo json_encode(['ok'=>false,'msg'=>'User not found']); exit;
    }

    // Admin can only manage own referral resellers
    if ($ulv===2) {
        $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]);
        $codes = array_column((array)$adminCodes,'code');
        if ($u->level !== 3 || !in_array($u->ref_code_used??'', $codes)) {
            echo json_encode(['ok'=>false,'msg'=>'You can only manage your own resellers']); exit;
        }
    }

    if ($action==='add') {
        db()->q("UPDATE users SET balance=balance+? WHERE username=?",[$amount,$target]);
        logAct($_SESSION['uid'],$unm,'ADD_BALANCE',"$target +$amount");
        $newBal = (float)(db()->one("SELECT balance FROM users WHERE username=?",[$target])->balance??0);
        echo json_encode(['ok'=>true,'msg'=>"Added $amount to $target. New balance: $newBal"]);
    } else {
        $cur = (float)(db()->one("SELECT balance FROM users WHERE username=?",[$target])->balance??0);
        if ($cur < $amount) { echo json_encode(['ok'=>false,'msg'=>"Insufficient balance ($cur)"]); exit; }
        db()->q("UPDATE users SET balance=balance-? WHERE username=?",[$amount,$target]);
        logAct($_SESSION['uid'],$unm,'DEDUCT_BALANCE',"$target -$amount");
        echo json_encode(['ok'=>true,'msg'=>"Deducted $amount from $target."]);
    }
    exit;
}

// AJAX - get users list for dropdown
if (isset($_GET['get_users'])) {
    requireLogin();
    header('Content-Type: application/json');
    $ulv = (int)($_SESSION['ulevel']??99);
    $unm = $_SESSION['uname']??'';
    $q   = trim($_GET['q']??'');

    if ($ulv===1) {
        // Owner sees all users IN THEIR BRANCH
        $users = db()->all("SELECT username,level,balance FROM users WHERE branch=? AND username LIKE ? ORDER BY level,username LIMIT 20",[currentBranch(),"%$q%"]);
    } else {
        // Admin sees own referral resellers only
        $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm]);
        $codes = array_column((array)$adminCodes,'code');
        if (empty($codes)) { echo json_encode([]); exit; }
        $ph = implode(',',array_fill(0,count($codes),'?'));
        $params = $codes;
        if ($q) { $params[] = "%$q%"; }
        $users = db()->all("SELECT username,level,balance FROM users WHERE level=3 AND ref_code_used IN ($ph)".($q?" AND username LIKE ?":"")." ORDER BY username LIMIT 20",$params);
    }
    echo json_encode(array_values((array)$users));
    exit;
}

requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) > 2) { header('Location: /dashboard.php'); exit; }
$pageTitle='Balance'; $activeNav='balance';
require_once __DIR__.'/app/Views/Layout/header.php';

$levelMap=[1=>'Owner',2=>'Admin',3=>'Reseller'];
$ulv2=(int)($_SESSION['ulevel']??99);
$unm2=$_SESSION['uname']??'';

// Get users for table
if ($ulv2===1) {
    $allUsers = db()->all("SELECT username,level,balance FROM users WHERE branch=? ORDER BY level,username",[currentBranch()]);
} else {
    $adminCodes = db()->all("SELECT code FROM referral_codes WHERE created_by=?",[$unm2]);
    $codes = array_column((array)$adminCodes,'code');
    if (!empty($codes)) {
        $ph=implode(',',array_fill(0,count($codes),'?'));
        $allUsers = db()->all("SELECT username,level,balance FROM users WHERE level=3 AND ref_code_used IN ($ph)",$codes);
    } else { $allUsers=[]; }
}
$s='fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="g2">
  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M202.87-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-554.26q0-37.78 26.61-64.39t64.39-26.61h554.26q37.78 0 64.39 26.61t26.61 64.39H520.48q-69.57 0-113.59 44.02-44.02 44.02-44.02 113.59V-360q0 69.57 44.02 113.35t113.59 43.78h327.65q0 37.78-26.61 64.39t-64.39 26.61H202.87ZM520.48-280q-32.76 0-56.62-23.62T440-360v-239.52q0-32.76 23.86-56.62T520.48-680h287.17q32.76 0 56.62 23.86t23.86 56.62V-360q0 32.76-23.86 56.38Q840.41-280 807.65-280H520.48ZM640-420q26 0 43-17t17-43q0-26-17-43t-43-17q-26 0-43 17t-17 43q0 26 17 43t43 17Z"/></svg>
        Add / Deduct Balance
      </span>
    </div>
    <div class="card-b">
      <div class="fg">
        <label class="fl">Username</label>
        <div style="position:relative">
          <input class="fi" id="targetUser" placeholder="Type to search users..." oninput="searchUsers(this.value)" onfocus="searchUsers(this.value)" autocomplete="off">
          <div id="userDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--card-bg);border:1px solid var(--line2);border-radius:var(--r2);z-index:100;max-height:200px;overflow-y:auto;box-shadow:var(--pop);margin-top:6px"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Amount</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);">$</span>
          <input class="fi fi-mono" type="number" id="amountInp" min="0.01" step="0.01" placeholder="0.00" style="padding-left:28px;font-size:16px;font-weight:700;color:var(--acc)">
        </div>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-acc" style="flex:1;padding:12px" onclick="doBal('add')">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M438.09-438.09V-320q0 17.81 12.05 29.86T480-278.09q17.81 0 29.86-12.05T521.91-320v-118.09H640q17.81 0 29.86-12.05T681.91-480q0-17.81-12.05-29.86T640-521.91H521.91V-640q0-17.81-12.05-29.86T480-681.91q-17.81 0-29.86 12.05T438.09-640v118.09H320q-17.81 0-29.86 12.05T278.09-480q0 17.81 12.05 29.86T320-438.09h118.09ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
          Add Balance
        </button>
        <button class="btn btn-red" style="flex:1;padding:12px" onclick="doBal('deduct')">
          <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M325.5-434.5h309q19.15 0 32.33-13.17Q680-460.85 680-480t-13.17-32.33q-13.18-13.17-32.33-13.17h-309q-19.15 0-32.33 13.17Q280-499.15 280-480t13.17 32.33q13.18 13.17 32.33 13.17ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
          Deduct
        </button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <span class="ct">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M480-484.07q-69.59 0-118.86-49.27-49.27-49.27-49.27-118.86 0-69.58 49.27-118.74 49.27-49.15 118.86-49.15t118.86 49.15q49.27 49.16 49.27 118.74 0 69.59-49.27 118.86-49.27 49.27-118.86 49.27ZM151.87-238.8v-29.61q0-36.16 18.7-66.57 18.69-30.41 49.84-46.37 62.72-31.24 127.68-46.98 64.95-15.74 131.91-15.74 67.43 0 132.39 15.62 64.96 15.62 127.2 46.86 31.15 15.96 49.84 46.25 18.7 30.3 18.7 66.93v29.61q0 37.78-26.61 64.39t-64.39 26.61H242.87q-37.78 0-64.39-26.61t-26.61-64.39Z"/></svg>
        User Balances
      </span>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Username</th><th>Role</th><th>Balance</th></tr></thead>
      <tbody>
        <?php if(empty($allUsers)): ?>
        <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--muted)">No users found</td></tr>
        <?php else: foreach($allUsers as $u):
          $lvl=(int)$u->level;
          $cls=['b-owner','b-admin','b-reseller'][$lvl-1]??'b-reseller';
        ?>
        <tr>
          <td style="font-weight:700;cursor:pointer" onclick="selectUser('<?=htmlspecialchars($u->username)?>')">
            <?=htmlspecialchars($u->username)?>
          </td>
          <td><span class="bx <?=$cls?>" style="font-size:9px"><?=$levelMap[$lvl]??'?'?></span></td>
          <td style="color:var(--acc);font-weight:700" id="bal-<?=htmlspecialchars($u->username)?>"><?=number_format((float)$u->balance,0)?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]').content;
let searchTimer;

async function searchUsers(q){
  clearTimeout(searchTimer);
  const dd = document.getElementById('userDropdown');
  searchTimer = setTimeout(async()=>{
    const res = await fetch('/balance.php?get_users=1&q='+encodeURIComponent(q.trim()));
    const users = await res.json();
    if(!users.length){ dd.innerHTML='<div style="padding:10px 14px;color:var(--muted);font-size:12px">No matching users</div>'; dd.style.display='block'; return; }
    dd.innerHTML = users.map(u=>
      `<div onclick="selectUser('${u.username}')" style="padding:10px 14px;cursor:pointer;font-size:13px;display:flex;justify-content:space-between;border-bottom:1px solid var(--line2);transition:.12s" onmouseover="this.style.background='rgba(45,212,191,.08)'" onmouseout="this.style.background=''">
        <span style="font-weight:700">${u.username}</span>
        <span style="color:var(--acc);font-family:monospace">${parseFloat(u.balance).toLocaleString()}</span>
      </div>`
    ).join('');
    dd.style.display='block';
  }, 300);
}

function selectUser(name){
  document.getElementById('targetUser').value = name;
  document.getElementById('userDropdown').style.display='none';
  document.getElementById('amountInp').focus();
}

document.addEventListener('click', e=>{
  if(!e.target.closest('#targetUser')&&!e.target.closest('#userDropdown'))
    document.getElementById('userDropdown').style.display='none';
});

async function doBal(action){
  const user   = document.getElementById('targetUser').value.trim();
  const amount = parseFloat(document.getElementById('amountInp').value)||0;
  if(!user||!amount){ toast('Enter username and amount','e'); return; }
  const r = await formPost('/balance.php',{ajax:1,target_user:user,amount,action,csrf});
  if(r.ok){
    toast(r.msg,'s');
    // Update table balance if visible
    const cell = document.getElementById('bal-'+user);
    if(cell){ setTimeout(()=>location.reload(),1200); }
  } else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
