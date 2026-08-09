<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/src/config.php';

$failures = [];
function csrf_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}
function csrf_source(string $relative): string {
    $source = file_get_contents(dirname(__DIR__) . '/' . $relative);
    if ($source === false) throw new RuntimeException('无法读取 ' . $relative);
    return $source;
}

$token = str_repeat('b', 64);
$_SESSION['csrf_token'] = $token;
csrf_expect(admin_csrf_request_is_valid('GET', null), 'GET 请求不应要求 CSRF Token');
csrf_expect(admin_csrf_request_is_valid('HEAD', ''), 'HEAD 请求不应要求 CSRF Token');
csrf_expect(admin_csrf_request_is_valid('POST', $token), 'POST 请求携带正确 Token 应通过');
csrf_expect(admin_csrf_request_is_valid('PATCH', $token), 'PATCH 请求携带正确 Token 应通过');
csrf_expect(admin_csrf_request_is_valid('DELETE', $token), 'DELETE 请求携带正确 Token 应通过');
csrf_expect(!admin_csrf_request_is_valid('POST', str_repeat('c', 64)), '错误 Token 不应通过');
csrf_expect(!admin_csrf_request_is_valid('POST', ''), '空 Token 不应通过');

$auth = csrf_source('admin/src/api/_auth.php');
$dashboard = csrf_source('admin/src/views/dashboard.php');
$login = csrf_source('admin/src/views/login.php');
$index = csrf_source('admin/src/index.php');
csrf_expect(str_contains($auth, 'admin_csrf_request_is_valid($method, $csrfToken)'), 'API 鉴权层未启用 CSRF 校验');
csrf_expect(str_contains($dashboard, "headers['X-CSRF-Token'] = CSRF_TOKEN"), '统一 API 请求未添加 CSRF Header');
csrf_expect(substr_count($dashboard, "'X-CSRF-Token': CSRF_TOKEN") === 3, 'multipart 写请求没有全部添加 CSRF Header');
csrf_expect(str_contains($login, 'name="csrf_token"'), '登录表单缺少 CSRF Token');
csrf_expect(str_contains($index, "admin_csrf_request_is_valid('POST', \$_POST['csrf_token'] ?? '')"), '登录处理未校验 CSRF Token');

if ($failures !== []) {
    fwrite(STDERR, "CSRF 测试失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "CSRF 测试通过：登录、统一 API 与 multipart 写请求均受会话令牌保护。\n";
