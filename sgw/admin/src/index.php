<?php
require_once __DIR__ . '/config.php';
start_admin_session();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = '/' . ltrim($uri, '/');

// ── Secret path 校验 ─────────────────────────────────────────
// 若配置了 ADMIN_SECRET_PATH，所有请求必须以 /SECRET 开头
// 不匹配的请求直接 444 断连，不给任何提示
if (ADMIN_SECRET_PATH !== '') {
    $prefix = '/' . ADMIN_SECRET_PATH;
    if ($uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
        http_response_code(444);
        exit;
    }
    // 剥离前缀，后续逻辑正常处理 /、/logout、/api/xxx.php
    $uri = substr($uri, strlen($prefix)) ?: '/';
}

// ── API 路由：直接 include 对应的 PHP 文件 ───────────────────
// 修复：secret path 模式下 apiFetch 会带上前缀，nginx 转发到 index.php
// 这里在剥离前缀后，将 /api/xxx.php 请求路由到实际文件
if (str_starts_with($uri, '/api/')) {
    $apiFile = realpath(__DIR__ . $uri);
    $apiDir  = realpath(__DIR__ . '/api');
    if ($apiFile && $apiDir && str_starts_with($apiFile, $apiDir . DIRECTORY_SEPARATOR)) {
        require $apiFile;
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not found']);
    }
    exit;
}

// 退出
if ($uri === '/logout') {
    destroy_admin_session();
    $base = ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH . '/' : '/';
    header('Location: ' . $base);
    exit;
}

// 处理登录 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($uri === '/' || $uri === '/index.php')) {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    $totp = trim($_POST['totp'] ?? '');
    $sourceIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $base = ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH . '/' : '/';
    $guard = admin_login_guard_status($sourceIp);
    $csrfValid = admin_csrf_is_valid((string)($_POST['_csrf'] ?? ''));
    if ($guard['locked']) {
        $minutes = max(1, (int)ceil($guard['retry_after'] / 60));
        $_SESSION['login_error'] = "登录尝试过多，请 {$minutes} 分钟后再试";
        header('Location: ' . $base);
        exit;
    }

    $passwordCheck = admin_password_check($pass);
    $userValid = ADMIN_USER !== '' && hash_equals((string)ADMIN_USER, $user);
    $totpValid = !ADMIN_TOTP_ENABLED || admin_totp_verify(ADMIN_TOTP_SECRET, $totp);

    if ($csrfValid && $userValid && !empty($passwordCheck['valid']) && $totpValid) {
        admin_login_guard_record_success($sourceIp);
        if (!empty($passwordCheck['needs_rehash'])) {
            admin_store_password_hash($pass);
        }
        session_regenerate_id(true);
        admin_csrf_rotate();
        $_SESSION['auth'] = true;
        $_SESSION['ts']   = time();
        $_SESSION['auth_version'] = ADMIN_AUTH_VERSION;
        header('Location: ' . $base);
    } else {
        $failure = admin_login_guard_record_failure($sourceIp);
        if (!empty($failure['locked'])) {
            $minutes = max(1, (int)ceil($failure['retry_after'] / 60));
            $_SESSION['login_error'] = "登录尝试过多，请 {$minutes} 分钟后再试";
        } else {
            $_SESSION['login_error'] = ADMIN_TOTP_ENABLED ? '用户名、密码或验证码错误' : '用户名或密码错误';
        }
        header('Location: ' . $base);
    }
    exit;
}

// Session 超时检查
if (isset($_SESSION['auth']) && (
    (time() - ($_SESSION['ts'] ?? 0)) > SESSION_LIFETIME
    || !isset($_SESSION['auth_version'])
    || !hash_equals(ADMIN_AUTH_VERSION, (string)$_SESSION['auth_version'])
)) {
    destroy_admin_session();
}

// 刷新 session 时间戳
if (isset($_SESSION['auth'])) {
    $_SESSION['ts'] = time();
    require __DIR__ . '/views/dashboard.php';
} else {
    require __DIR__ . '/views/login.php';
}
