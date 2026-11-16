  </div><!-- /content -->
</div><!-- /main -->
</div><!-- /app -->

<!-- ═══ MOBILE BOTTOM NAV ═══ -->
<nav class="mob-bottom-nav" id="mobBottomNav">
  <div class="mob-bottom-nav-inner">
    <a href="/dashboard.php" class="mbn-item <?= ($activeNav==='dashboard')?'on':'' ?>">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
      <span class="mbn-label">Home</span>
    </a>
    <a href="/keys.php" class="mbn-item <?= ($activeNav==='allkeys')?'on':'' ?>">
      <svg viewBox="0 0 24 24"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6M15 3l3 3"/></svg>
      <span class="mbn-label">Keys</span>
    </a>
    <a href="/generate.php" class="mbn-item <?= ($activeNav==='generate')?'on':'' ?>">
      <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      <span class="mbn-label">Generate</span>
    </a>
    <?php if(($ulevel??3)<=2): ?>
    <a href="/users.php" class="mbn-item <?= ($activeNav==='users')?'on':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="mbn-label">Users</span>
    </a>
    <?php endif; ?>
    <button class="mbn-item mbn-more-btn" onclick="toggleSidebar()" aria-label="More">
      <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      <span class="mbn-label">More</span>
    </button>
  </div>
</nav>

<div id="toasts" class="toasts"></div>
<script src="/assets/js/panel.js?v=9"></script>
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
</body>
</html>
