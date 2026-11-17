  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /app -->

<!-- ═══ BOTTOM DOCK + iOS NAV TRAY ═══ -->
<?php
$navOrder  = ['dashboard','allkeys','generate','genrandom','extend','users','referral','balance',
              'panelset','onedevice','keyformat','pricing','iplimit','rebranding','heartbeat','kicks',
              'pkg_manager','panelalert','botusers','panelupdate','settings','logs'];
$navGroups = ['Main'=>['dashboard','allkeys','generate','genrandom'],
              'Management'=>['extend','users','referral','balance'],
              'System'=>['panelset','onedevice','keyformat','pricing','iplimit','rebranding','heartbeat','kicks','pkg_manager','panelalert','botusers','panelupdate','settings','logs']];
$navFlat = [];
foreach ($navOrder as $k) { $it = $navItems[$k] ?? null; if (!$it) continue; if (($ulevel ?? 3) > $it['min']) continue; $navFlat[$k] = $it; }
$navKeys = array_keys($navFlat);
$ci = array_search($activeNav, $navKeys, true); if ($ci === false) $ci = 0;
$win = 2; $n = count($navKeys);
$start = max(0, min($ci - $win, $n - (2*$win + 1))); $start = max(0, $start);
$slice = array_slice($navKeys, $start, 2*$win + 1);
?>
<nav class="dock" aria-label="Pages">
  <div class="dock-rail">
    <?php foreach ($slice as $k): $it = $navFlat[$k]; $on = ($k === $activeNav); $near = (abs(array_search($k,$navKeys,true) - $ci) === 1); ?>
    <a href="<?= $it['href'] ?>" class="dock-item <?= $on?'on':'' ?> <?= $near?'near':'' ?>" title="<?= $it['label'] ?>">
      <?= $it['ico'] ?><span class="dlbl"><?= $it['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <button type="button" class="dock-more" onclick="openNavSheet()" aria-label="All pages" aria-haspopup="dialog">
    <?= phIcon('dots-three') ?><span class="dlbl">More</span>
  </button>
</nav>

<div class="sheet-bg" id="sheetBg" onclick="closeNavSheet()"></div>
<div class="nav-sheet" id="navSheet" role="dialog" aria-modal="true" aria-label="All pages">
  <div class="sheet-grip"></div>
  <?php foreach ($navGroups as $gname => $gkeys):
    $vis = array_values(array_filter($gkeys, fn($k) => isset($navFlat[$k])));
    if (!$vis) continue; ?>
  <div class="sheet-sec"><?= $gname ?></div>
  <div class="sheet-grid">
    <?php foreach ($vis as $k): $it = $navFlat[$k]; ?>
    <a href="<?= $it['href'] ?>" class="sheet-item <?= $k===$activeNav?'on':'' ?>">
      <span class="si-ic"><?= $it['ico'] ?></span>
      <span><?= $it['label'] ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<div id="toasts" class="toasts"></div>
<script src="<?= asset('/assets/js/panel.js') ?>"></script>
<?php if(isset($extraJs)) echo $extraJs; ?>
<script>
// Auto check for updates (only if update server configured)
(function(){
  const ulevel = <?php echo (int)($_SESSION['ulevel']??99); ?>;
  if(ulevel !== 1) return; // Only owner sees update notification
  
  <?php
  $masterUrl = getSetting('update_server_url','');
  $curVer    = getSetting('panel_version','3.0.0');
  if(!empty($masterUrl)):
  ?>
  const masterUrl = '<?=htmlspecialchars($masterUrl)?>';
  const curVer    = '<?=htmlspecialchars($curVer)?>';
  
  // Check once per session
  if(sessionStorage.getItem('upChecked')) {
    if(sessionStorage.getItem('upAvailable')==='1'){
      showUpdateBanner(sessionStorage.getItem('upVer')||'');
    }
    return;
  }
  
  fetch(masterUrl+'/updates/version.json', {cache:'no-cache'})
    .then(r=>r.json())
    .then(d=>{
      sessionStorage.setItem('upChecked','1');
      if(d.version && compareVer(d.version, curVer) > 0){
        sessionStorage.setItem('upAvailable','1');
        sessionStorage.setItem('upVer', d.version);
        showUpdateBanner(d.version);
      }
    })
    .catch(()=>{});
  
  function showUpdateBanner(newVer){
    const banner = document.getElementById('updateBanner');
    const dot    = document.getElementById('updateDot');
    const txt    = document.getElementById('updateBannerTxt');
    if(banner){ banner.style.display='flex'; }
    if(dot)   { dot.style.display='inline-flex'; }
    if(txt && newVer){ txt.textContent='New update available: v'+newVer+' → Click to update'; }
    
    // Show toast notification
    if(!sessionStorage.getItem('upToastShown')){
      sessionStorage.setItem('upToastShown','1');
      setTimeout(()=>{
        toast('Update Available: v'+newVer+' — Go to Panel Update','w', 6000);
      }, 1500);
    }
  }
  
  function compareVer(a,b){
    const pa=a.split('.').map(Number), pb=b.split('.').map(Number);
    for(let i=0;i<3;i++){ if((pa[i]||0)>(pb[i]||0)) return 1; if((pa[i]||0)<(pb[i]||0)) return -1; }
    return 0;
  }
  <?php endif; ?>
})();
</script>
<script>
// iOS-style nav tray
(function(){
  var sheet=document.getElementById('navSheet'), bg=document.getElementById('sheetBg');
  window.openNavSheet=function(){ if(!sheet) return; bg.classList.add('show'); sheet.classList.add('show'); document.body.style.overflow='hidden'; };
  window.closeNavSheet=function(){ if(!sheet) return; bg.classList.remove('show'); sheet.classList.remove('show'); document.body.style.overflow=''; };
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeNavSheet(); });
  // swipe-down to dismiss
  if(sheet){ var y0=null;
    sheet.addEventListener('touchstart',function(e){ if(sheet.scrollTop<=0) y0=e.touches[0].clientY; },{passive:true});
    sheet.addEventListener('touchmove',function(e){ if(y0===null) return; var dy=e.touches[0].clientY-y0; if(dy>0) sheet.style.transform='translateY('+dy+'px)'; },{passive:true});
    sheet.addEventListener('touchend',function(e){ if(y0===null) return; var dy=(e.changedTouches[0].clientY-y0); sheet.style.transform=''; if(dy>90) closeNavSheet(); y0=null; });
  }
})();
</script>
</body>
</html>
