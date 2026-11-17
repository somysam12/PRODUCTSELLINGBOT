<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';

// ── AJAX HANDLER (MUST be before any HTML output) ──────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['ajax'])) {
    // Auth FIRST — owner only, valid CSRF (prevents unauthorized price edits)
    header('Content-Type: application/json');
    requireLogin();
    if ((int)($_SESSION['ulevel']??99) !== 1) { echo json_encode(['ok'=>false,'msg'=>'Owner only']); exit; }
    if (!verifyCsrf($_POST['csrf']??'')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    // Add new duration
    if (!empty($_POST['add_duration'])) {
        $name  = trim($_POST['dur_name']??'');
        $hours = (int)($_POST['dur_hours']??0);
        $price = max(0,(float)($_POST['dur_price']??0));
        if (empty($name)||$hours<=0) { echo json_encode(['ok'=>false,'msg'=>'Fill all fields']); exit; }
        $ex = db()->one("SELECT id FROM prices WHERE duration=?",[$name]);
        if ($ex) { echo json_encode(['ok'=>false,'msg'=>'Duration already exists']); exit; }
        db()->q("INSERT INTO prices(duration,hours,price) VALUES(?,?,?)",[$name,$hours,$price]);
        echo json_encode(['ok'=>true,'msg'=>"Duration '$name' added!"]);
        exit;
    }
    // Delete duration
    if (!empty($_POST['del_duration'])) {
        $name = trim($_POST['duration']??'');
        db()->q("DELETE FROM prices WHERE duration=?",[$name]);
        echo json_encode(['ok'=>true,'msg'=>"Duration '$name' deleted!"]);
        exit;
    }
    $dur   = trim($_POST['duration']??'');
    $price = max(0,(float)($_POST['price']??0));
    if (empty($dur)) { 
        echo json_encode(['ok'=>false,'msg'=>'Select a duration']); exit; 
    }
    $exists = db()->one("SELECT id FROM prices WHERE duration=?",[$dur]);
    if ($exists) {
        db()->q("UPDATE prices SET price=? WHERE duration=?",[$price,$dur]);
        logAct($_SESSION['uid'],$_SESSION['uname']??'','UPDATE_PRICE',"$dur = $price");
        echo json_encode(['ok'=>true,'msg'=>"Price saved: $dur"]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Duration not found']);
    }
    exit;
}

// ── NORMAL PAGE LOAD ────────────────────────────────────────
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }
$pageTitle='Pricing'; $activeNav='pricing';
require_once __DIR__.'/app/Views/Layout/header.php';

$prices  = db()->all("SELECT * FROM prices ORDER BY hours ASC");
$priceMap= [];
foreach ($prices as $p) $priceMap[$p->duration] = (float)$p->price;
$sel      = $_GET['dur'] ?? ($prices[0]->duration??'');
$selPrice = $priceMap[$sel]??0;
?>

<div class="g2">
  <div class="card">
    <div class="card-h"><span class="ct"><?= phIcon('tag') ?> Update Price</span></div>
    <div class="card-b">
      <div class="fg">
        <label class="fl">Select Duration</label>
        <select class="fi" id="durSel" onchange="onDurChange(this.value)">
          <?php foreach($prices as $p): ?>
          <option value="<?=htmlspecialchars($p->duration)?>" <?=$p->duration===$sel?'selected':''?>>
            <?=htmlspecialchars($p->duration)?> (<?=$p->hours>=720?round($p->hours/720).'M':($p->hours>=168?round($p->hours/24).'D':$p->hours.'H')?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label class="fl">Price per Device</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:16px">$</span>
          <input class="fi fi-mono" type="number" id="priceInp"
            value="<?=number_format($selPrice,2)?>"
            step="0.01" min="0"
            style="padding-left:28px;font-size:20px;font-weight:800;color:var(--acc)">
        </div>
      </div>

      <button onclick="savePrice()" id="saveBtn"
        style="width:100%;padding:14px;background:linear-gradient(165deg,#34d399,#059669);border:none;border-radius:var(--r2);color:#fff;font-weight:800;font-size:15px;cursor:pointer;letter-spacing:.5px;transition:.2s;box-shadow:var(--acc-sh);display:flex;align-items:center;justify-content:center;gap:8px">
        <span id="saveBtnTxt">SAVE PRICE</span>
        <span id="saveBtnLoad" style="display:none">Saving...</span>
      </button>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><span class="ct"><?= phIcon('coins') ?> All Prices</span></div>
    <div class="tw"><table>
      <thead><tr><th>Duration</th><th>Hours</th><th>Price/Device</th><th></th></tr></thead>
      <tbody>
        <?php foreach($prices as $p): ?>
        <tr>
          <td style="font-weight:700"><?=htmlspecialchars($p->duration)?></td>
          <td style="color:var(--muted)"><?=$p->hours?>h</td>
          <td style="color:var(--acc);font-weight:700" id="pval-<?=md5($p->duration)?>">₹<?=number_format((float)$p->price,2)?></td>
          <td><button class="btn btn-ghost btn-xs" onclick="selectDur('<?=htmlspecialchars(addslashes($p->duration))?>')">Edit</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>

<script>
const priceMap = <?=json_encode($priceMap)?>;

const csrf = document.querySelector('meta[name="csrf"]').content;

function onDurChange(dur){
  document.getElementById('priceInp').value = (priceMap[dur]||0).toFixed(2);
}

function selectDur(dur){
  document.getElementById('durSel').value = dur;
  onDurChange(dur);
  document.getElementById('saveBtn').scrollIntoView({behavior:'smooth',block:'center'});
  document.getElementById('priceInp').focus();
}

async function savePrice(){
  const dur   = document.getElementById('durSel').value;
  const price = parseFloat(document.getElementById('priceInp').value)||0;
  const btn   = document.getElementById('saveBtn');
  const txt   = document.getElementById('saveBtnTxt');
  const load  = document.getElementById('saveBtnLoad');

  txt.style.display='none'; load.style.display='block';
  btn.style.opacity='.7'; btn.disabled=true;

  try {
    const body = new URLSearchParams();
    body.append('ajax','1');
    body.append('duration',dur);
    body.append('price',String(price));
    body.append('csrf',csrf);

    const r = await fetch('/pricing.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: body.toString()
    });

    const text = await r.text();
    let d;
    try { d = JSON.parse(text); }
    catch(e){ throw new Error('Server returned non-JSON: '+text.substring(0,100)); }

    if(d.ok){
      priceMap[dur]=price;

      btn.style.background='linear-gradient(135deg,#00a870,#00c88a)';
      txt.textContent='SAVED!';
      txt.style.display='block'; load.style.display='none';
      toast('Price saved: '+dur+' → ₹'+price.toFixed(2),'s');
      setTimeout(()=>{
        btn.style.background='linear-gradient(135deg,#00c88a,#2dd4bf)';
        txt.textContent='SAVE PRICE';
        btn.style.opacity='1'; btn.disabled=false;
      },2000);
    } else {
      toast(d.msg||'Save failed','e');
      txt.style.display='block'; load.style.display='none';
      btn.style.opacity='1'; btn.disabled=false;
    }
  } catch(err){
    console.error(err);
    toast('Error: '+err.message,'e');
    txt.style.display='block'; load.style.display='none';
    btn.style.opacity='1'; btn.disabled=false;
  }
}
</script>

<!-- Add / Delete Duration -->
<div class="card" style="margin-top:16px">
  <div class="card-h">
    <span class="ct">
      <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="16"><path d="M438.09-438.09V-320q0 17.81 12.05 29.86T480-278.09q17.81 0 29.86-12.05T521.91-320v-118.09H640q17.81 0 29.86-12.05T681.91-480q0-17.81-12.05-29.86T640-521.91H521.91V-640q0-17.81-12.05-29.86T480-681.91q-17.81 0-29.86 12.05T438.09-640v118.09H320q-17.81 0-29.86 12.05T278.09-480q0 17.81 12.05 29.86T320-438.09h118.09ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
      Add / Delete Duration
    </span>
    <span class="owner-only">Owner Only</span>
  </div>
  <div class="card-b">
    <div style="background:var(--acc-bg);border:1px solid var(--acc-20);border-radius:var(--r);padding:14px;margin-bottom:16px">
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Add New Duration</div>
      <div class="g2" style="gap:10px;margin-bottom:10px">
        <div class="fg" style="margin:0">
          <label class="fl">Duration Name</label>
          <input class="fi" id="newDurName" placeholder="e.g. 45 Days">
        </div>
        <div class="fg" style="margin:0">
          <label class="fl">Hours</label>
          <input class="fi fi-mono" type="number" id="newDurHours" placeholder="1080" min="1">
        </div>
      </div>
      <div class="fg" style="margin-bottom:10px">
        <label class="fl">Starting Price</label>
        <input class="fi fi-mono" type="number" id="newDurPrice" placeholder="5.00" step="0.01" min="0">
      </div>
      <button onclick="addDuration()" class="btn btn-acc btn-full">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="15"><path d="M438.09-438.09V-320q0 17.81 12.05 29.86T480-278.09q17.81 0 29.86-12.05T521.91-320v-118.09H640q17.81 0 29.86-12.05T681.91-480q0-17.81-12.05-29.86T640-521.91H521.91V-640q0-17.81-12.05-29.86T480-681.91q-17.81 0-29.86 12.05T438.09-640v118.09H320q-17.81 0-29.86 12.05T278.09-480q0 17.81 12.05 29.86T320-438.09h118.09ZM480-71.87q-84.91 0-159.34-32.12-74.44-32.12-129.5-87.17-55.05-55.06-87.17-129.5Q71.87-395.09 71.87-480t32.12-159.34q32.12-74.44 87.17-129.5 55.06-55.05 129.5-87.17 74.43-32.12 159.34-32.12t159.34 32.12q74.44 32.12 129.5 87.17 55.05 55.06 87.17 129.5 32.12 74.43 32.12 159.34t-32.12 159.34q-32.12 74.44-87.17 129.5-55.06 55.05-129.5 87.17Q564.91-71.87 480-71.87Z"/></svg>
        Add Duration
      </button>
    </div>

    <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Delete Duration</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach($prices as $p): ?>
      <button class="btn btn-red btn-sm" onclick="delDuration('<?=htmlspecialchars(addslashes($p->duration))?>')">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="13" height="13"><path d="M277.37-111.87q-37.78 0-64.39-26.61t-26.61-64.39v-514.5q-19.15 0-32.33-13.17-13.17-13.18-13.17-32.33t13.17-32.33q13.18-13.17 32.33-13.17H354.5q0-19.15 13.17-32.33 13.18-13.17 32.33-13.17h159.52q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33h168.61q19.15 0 32.33 13.17 13.17 13.18 13.17 32.33t-13.17 32.33q-13.18 13.17-32.33 13.17v514.5q0 37.78-26.61 64.39t-64.39 26.61H277.37Zm121.2-168.37q17.95 0 30.29-12.34 12.34-12.33 12.34-30.29v-274.74q0-17.96-12.34-30.29-12.34-12.34-30.29-12.34-17.96 0-30.42 12.34-12.45 12.33-12.45 30.29v274.74q0 17.96 12.45 30.29 12.46 12.34 30.42 12.34Zm163.1 0q17.96 0 30.3-12.34 12.33-12.33 12.33-30.29v-274.74q0-17.96-12.33-30.29-12.34-12.34-30.3-12.34-17.95 0-30.41 12.34-12.46 12.33-12.46 30.29v274.74q0 17.96 12.46 30.29 12.46 12.34 30.41 12.34Z"/></svg>
        <?=htmlspecialchars($p->duration)?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
async function addDuration(){
  const name  = document.getElementById('newDurName').value.trim();
  const hours = document.getElementById('newDurHours').value;
  const price = document.getElementById('newDurPrice').value;
  if(!name||!hours){toast('Fill name and hours','e');return;}
  const csrf = document.querySelector('meta[name="csrf"]').content;
  const r = await formPost('/pricing.php',{ajax:1,add_duration:1,dur_name:name,dur_hours:hours,dur_price:price||0,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),1000);}
  else toast(r.msg,'e');
}
async function delDuration(name){
  const csrf = document.querySelector('meta[name="csrf"]').content;
  const r = await formPost('/pricing.php',{ajax:1,del_duration:1,duration:name,csrf});
  if(r.ok){toast(r.msg,'s');setTimeout(()=>location.reload(),800);}
  else toast(r.msg,'e');
}
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
