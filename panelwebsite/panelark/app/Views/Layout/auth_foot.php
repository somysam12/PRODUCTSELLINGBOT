    </main>
  </div>
</div>
<div id="toasts" class="toasts"></div>
<script src="<?= asset('/assets/js/panel.js') ?>"></script>
<script>
// Show / hide password for any .af-eye button
function authEye(btn){
  var inp = btn.parentNode.querySelector('input'); if(!inp) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('.e1').hidden = show; btn.querySelector('.e2').hidden = !show;
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}
</script>
</body>
</html>
