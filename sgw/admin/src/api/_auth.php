<?php
// API 鉴权中间件，每个 API 文件首行 require 此文件
// 关闭 HTML 错误输出，确保所有响应均为 JSON
error_reporting(0);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config.php';
start_admin_session();

if (empty($_SESSION['auth'])
    || !isset($_SESSION['auth_version'])
    || !hash_equals(ADMIN_AUTH_VERSION, (string)$_SESSION['auth_version'])) {
    destroy_admin_session();
    json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!admin_csrf_is_valid($csrf)) {
        json_err('安全令牌已失效，请刷新页面后重试', 403);
    }
}
