<?php
require_once __DIR__.'/app/Config/db.php';
require_once __DIR__.'/app/Helpers/security.php';
requireLogin();
if ((int)($_SESSION['ulevel'] ?? 99) !== 1) { header('Location: /dashboard.php'); exit; }

// ── AJAX: delete single log ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_log') {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) { db()->q("DELETE FROM activity_logs WHERE id = ?", [$id]); }
    echo json_encode(['ok' => true]);
    exit;
}

// ── AJAX: clear all logs ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'Invalid token']); exit; }
    db()->q("DELETE FROM activity_logs");
    echo json_encode(['ok' => true]);
    exit;
}

$pageTitle = 'Activity Logs'; $activeNav = 'logs';
require_once __DIR__.'/app/Views/Layout/header.php';

$pp = 30; $pg = max(1,(int)($_GET['p']??1)); $off = ($pg-1)*$pp;
$sq = trim($_GET['q']??'');
$where = "WHERE branch=?"; $params = [currentBranch()];
if ($sq) { $where .= " AND (action LIKE ? OR detail LIKE ? OR username LIKE ? OR ip LIKE ?)"; array_push($params, "%$sq%","%$sq%","%$sq%","%$sq%"); }
$total = (int)(db()->one("SELECT COUNT(*) c FROM activity_logs $where",$params)->c??0);
$logs  = db()->all("SELECT * FROM activity_logs $where ORDER BY created_at DESC LIMIT $pp OFFSET $off",$params);
$pages = (int)ceil($total/$pp);
$s     = 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
?>

<div class="card">
  <div class="card-h">
    <span class="ct">
      <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" width="16" height="16"><path d="M361.91-239.28h236.42q17.71 0 29.69-12.1T640-281.2q0-17.71-11.98-29.69t-29.69-11.98H361.67q-17.71 0-29.69 11.98T320-281.2q0 17.72 12.1 29.82 12.1 12.1 29.81 12.1Zm0-160h236.42q17.71 0 29.69-12.1T640-441.2q0-17.71-11.98-29.69t-29.69-11.98H361.67q-17.71 0-29.69 11.98T320-441.2q0 17.72 12.1 29.82 12.1 12.1 29.81 12.1ZM242.87-71.87q-37.78 0-64.39-26.61t-26.61-64.39v-634.26q0-37.78 26.61-64.39t64.39-26.61H525.8q18.16 0 34.69 6.84 16.53 6.83 29.21 19.51L781.78-669.7q12.68 12.68 19.51 29.21 6.84 16.53 6.84 34.69v442.93q0 37.78-26.61 64.39t-64.39 26.61H242.87Zm274.26-570.76q0 19.15 13.17 32.33 13.18 13.17 32.33 13.17h154.5l-200-200v154.5Z"/></svg>
      Activity Logs
      <span style="color:var(--muted);font-size:11px;font-weight:400;text-transform:none;letter-spacing:0" id="logCount">(<?= number_format($total) ?>)</span>
    </span>
    <div style="display:flex;align-items:center;gap:8px">
      <?php if ($total > 0): ?>
      <button class="btn btn-red btn-xs" onclick="clearAllLogs()">Clear All</button>
      <?php endif; ?>
      <div class="sbar" style="width:240px">
        <svg class="ph" viewBox="0 -960 960 960" fill="currentColor" style="width:13px;height:13px;flex-shrink:0;margin-right:8px;opacity:.4"><path d="M378.09-314.5q-111.16 0-188.33-77.17-77.17-77.18-77.17-188.33t77.17-188.33q77.17-77.17 188.33-77.17 111.15 0 188.32 77.17 77.18 77.18 77.18 188.33 0 44.48-13.52 83.12-13.53 38.64-36.57 68.16l222.09 222.33q12.67 12.91 12.67 31.94 0 19.04-12.91 31.71-12.68 12.67-31.83 12.67t-31.82-12.67L529.85-364.59q-29.76 23.05-68.64 36.57-38.88 13.52-83.12 13.52Zm0-91q72.84 0 123.67-50.83 50.83-50.82 50.83-123.67t-50.83-123.67q-50.83-50.83-123.67-50.83-72.85 0-123.68 50.83-50.82 50.82-50.82 123.67t50.82 123.67q50.83 50.83 123.68 50.83Z"/></svg>
        <input id="logSearch" placeholder="Search action, user, IP..." value="<?= htmlspecialchars($sq) ?>"
               onkeydown="if(event.key==='Enter'){let u=new URL('/logs.php',location);u.searchParams.set('q',this.value);u.searchParams.set('p','1');location.href=u;}">
      </div>
    </div>
  </div>

  <div class="tw">
    <table id="logsTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Action</th>
          <th>Detail</th>
          <th>User</th>
          <th>IP</th>
          <th>Time</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted)">No logs found</td></tr>
        <?php else: $i = $off+1; foreach ($logs as $l): ?>
        <tr data-id="<?= $l->id ?>">
          <td style="color:var(--muted);font-size:11px"><?= $i++ ?></td>
          <td><span class="bx b-admin" style="font-size:9px;padding:3px 8px"><?= htmlspecialchars($l->action) ?></span></td>
          <td style="font-size:11px;color:var(--muted2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l->detail??'') ?>"><?= htmlspecialchars($l->detail ?? '—') ?></td>
          <td style="font-size:12px;font-weight:600"><?= htmlspecialchars($l->username ?? 'System') ?></td>
          <td style="font-size:11px;color:var(--muted2)"><?= htmlspecialchars($l->ip ?? '—') ?></td>
          <td style="font-size:10px;color:var(--muted);white-space:nowrap"><?= date('d M Y H:i:s', strtotime($l->created_at)) ?></td>
          <td><button class="btn btn-red btn-xs" onclick="deleteLog(<?= $l->id ?>,this)">Del</button></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php for ($p2=1; $p2<=$pages; $p2++): ?>
    <a href="?p=<?= $p2 ?>&q=<?= urlencode($sq) ?>" class="pg-btn <?= $p2===$pg?'on':'' ?>"><?= $p2 ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf"]')?.content || '';

function deleteLog(id, btn) {
  if (!confirm('Delete this log entry?')) return;
  btn.disabled = true;
  fetch('/logs.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=delete_log&id=' + id + '&csrf=' + encodeURIComponent(csrf)
  }).then(r => r.json()).then(d => {
    if (d.ok) {
      const row = btn.closest('tr');
      if (row) row.remove();
    }
  }).catch(() => { btn.disabled = false; });
}

function clearAllLogs() {
  if (!confirm('Delete ALL activity logs? This cannot be undone.')) return;
  fetch('/logs.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'action=clear_logs&csrf=' + encodeURIComponent(csrf)
  }).then(r => r.json()).then(d => {
    if (d.ok) location.reload();
  });
}

(function(){
  const inp = document.getElementById('logSearch');
  if (!inp) return;
  let debounce;
  inp.addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      const q = inp.value.toLowerCase().trim();
      document.querySelectorAll('#logsTable tbody tr').forEach(r => {
        r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none';
      });
    }, 150);
  });
})();
</script>

<?php require_once __DIR__.'/app/Views/Layout/footer.php'; ?>
