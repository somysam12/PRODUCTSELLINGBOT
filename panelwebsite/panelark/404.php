<?php http_response_code(404); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#10b981">
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<title>Page not found</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  :root{color-scheme:light}
  body{
    font-family:'Plus Jakarta Sans',system-ui,-apple-system,sans-serif;
    min-height:100vh;display:flex;align-items:center;justify-content:center;
    padding:24px;color:#211e18;
    background:radial-gradient(ellipse 90% 55% at 15% -5%,#f2ede4 0,transparent 55%),
               radial-gradient(ellipse 70% 45% at 100% 8%,#ece5d9 0,transparent 52%),#e7e1d6;
  }
  .card{
    width:100%;max-width:420px;text-align:center;padding:40px 32px;
    background:#fdfcf9;border:1px solid rgba(43,36,26,.08);border-radius:24px;
    box-shadow:0 2px 6px rgba(74,60,40,.08),0 20px 48px rgba(74,60,40,.12),inset 0 1px 0 rgba(255,255,255,.9);
  }
  .ic{
    width:64px;height:64px;margin:0 auto 22px;border-radius:19px;
    display:flex;align-items:center;justify-content:center;
    background:linear-gradient(160deg,#39342b,#211e18);
    box-shadow:0 6px 24px rgba(18,14,8,.3),inset 0 1px 0 rgba(255,255,255,.08);
  }
  .ic svg{width:30px;height:30px;stroke:#34d399;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .code{font-size:52px;font-weight:800;letter-spacing:-2px;line-height:1;margin-bottom:8px;
    background:linear-gradient(135deg,#211e18,#059669);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
  h1{font-size:19px;font-weight:800;letter-spacing:-.4px;margin-bottom:8px}
  p{font-size:14px;color:#5f584b;line-height:1.6;margin-bottom:24px}
  .btn{
    display:inline-flex;align-items:center;gap:8px;padding:13px 22px;border-radius:13px;
    background:linear-gradient(165deg,#34d399,#059669);color:#fff;font-size:14px;font-weight:700;
    text-decoration:none;box-shadow:0 2px 8px rgba(16,185,129,.32),0 12px 28px rgba(16,185,129,.3),inset 0 1px 0 rgba(255,255,255,.35);
    transition:transform .16s}
  .btn:hover{transform:translateY(-1px)}
  .btn svg{width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
</style>
</head>
<body>
  <div class="card">
    <div class="ic">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v3"/><path d="M11 14h.01"/></svg>
    </div>
    <div class="code">404</div>
    <h1>Page not found</h1>
    <p>The page you’re looking for doesn’t exist or may have moved.</p>
    <a href="/" class="btn">
      <svg viewBox="0 0 24 24"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
      Back to panel
    </a>
  </div>
</body>
</html>
