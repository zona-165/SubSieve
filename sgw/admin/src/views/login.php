<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Subscribe Gateway — 登录</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{color-scheme:dark}
body{background:#101214;color:#f1f5f4;font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px}
.shell{width:min(100%,380px)}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}
.brand-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:8px;background:#14b8a6;color:#fff;font-weight:850;box-shadow:0 10px 24px rgba(15,118,110,.24)}
.brand-copy{display:flex;flex-direction:column;font-weight:800;line-height:1.2}
.brand-copy small{margin-top:4px;color:#7f8b87;font-size:10px;font-weight:650}
.card{background:#1d2125;border:1px solid #2a2f34;border-radius:8px;padding:28px;box-shadow:0 18px 50px rgba(0,0,0,.24)}
h1{font-size:20px;font-weight:820;margin-bottom:6px}
.sub{color:#b2bcb9;font-size:12px;margin-bottom:24px}
label{display:block;font-size:11px;font-weight:750;color:#b2bcb9;margin-bottom:6px;margin-top:14px}
input{width:100%;background:#121517;border:1px solid #3a4249;color:#f1f5f4;padding:11px 12px;border-radius:7px;font-size:14px;outline:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:#14b8a6;box-shadow:0 0 0 3px rgba(20,184,166,.12)}
.btn{width:100%;margin-top:22px;padding:11px;background:#14b8a6;color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:800;cursor:pointer;transition:background .15s,transform .15s}
.btn:hover{background:#0f766e}.btn:active{transform:scale(.99)}
.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.30);color:#f87171;padding:10px 12px;border-radius:7px;font-size:12px;margin-bottom:10px}
@media (max-width:420px){body{padding:16px}.card{padding:22px}}
</style>
</head>
<body>
<div class="shell">
  <div class="brand"><span class="brand-mark">S</span><span class="brand-copy">SubSieve<small>订阅网关控制台</small></span></div>
  <div class="card">
    <h1>登录管理后台</h1>
    <p class="sub">使用部署时生成的管理员凭证继续。</p>
    <?php if (!empty($_SESSION['login_error'])): ?>
      <div class="err"><?= htmlspecialchars($_SESSION['login_error']) ?></div>
      <?php unset($_SESSION['login_error']); ?>
    <?php endif; ?>
    <form method="POST" action="<?= ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH . '/' : '/' ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <label for="login-user">用户名</label>
      <input id="login-user" type="text" name="username" autocomplete="username" required autofocus>
      <label for="login-pass">密码</label>
      <input id="login-pass" type="password" name="password" autocomplete="current-password" required>
      <button class="btn" type="submit">登录</button>
    </form>
  </div>
</div>
</body>
</html>
