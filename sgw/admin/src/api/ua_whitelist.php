<?php
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET — 列出UA白名单
if ($method === 'GET') {
    json_out(['ok' => true, 'entries' => read_ua_whitelist()]);
}

// POST — 添加并立即生效
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ua      = trim($body['ua'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ua) json_err('请输入 UA 关键词');
    if (preg_match('/[\r\n]/', $ua)) json_err('UA 关键词不能包含换行');

    $entries = read_ua_whitelist();
    foreach ($entries as $e) {
        if ($e['ua'] === $ua) json_err('该 UA 已在白名单中');
    }

    $entries[] = [
        'ua'       => $ua,
        'comment'  => $comment,
        'added_at' => date('Y-m-d H:i'),
    ];

    if (!write_ua_whitelist($entries)) json_err('UA白名单保存或重载提交失败，旧规则已保留');
    $reload = true;
    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

// PATCH — 更新备注（仅更新 JSON，不 reload nginx）
if ($method === 'PATCH') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ua      = trim($body['ua'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ua) json_err('缺少 ua 参数');

    $entries = read_ua_whitelist();
    $found   = false;
    foreach ($entries as &$e) {
        if ($e['ua'] === $ua) { $e['comment'] = $comment; $found = true; break; }
    }
    unset($e);

    if (!$found) json_err('未找到该UA');
    if (!subsieve_atomic_write_json(UA_WHITELIST_JSON, $entries)) {
        json_err('更新UA白名单备注失败，请检查文件权限');
    }
    json_out(['ok' => true]);
}

// DELETE — 移除并立即生效
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $ua   = trim($body['ua'] ?? '');

    if (!$ua) json_err('缺少 ua 参数');

    $entries = array_filter(read_ua_whitelist(), fn($e) => $e['ua'] !== $ua);
    if (!write_ua_whitelist(array_values($entries))) json_err('UA白名单保存或重载提交失败，旧规则已保留');
    $reload = true;
    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

json_err('不支持的请求方式', 405);

// ── 读写 UA 白名单 ─────────────────────────────────────────────

function read_ua_whitelist(): array {
    if (!file_exists(UA_WHITELIST_JSON)) return [];
    $data = json_decode(file_get_contents(UA_WHITELIST_JSON), true);
    return is_array($data) ? $data : [];
}

function write_ua_whitelist(array $entries): bool {
    // 生成 nginx map conf（$is_ua_whitelisted）
    $lines   = ['# UA白名单 - 由 admin 自动生成 | ' . date('Y-m-d H:i:s')];
    $lines[] = 'map $http_user_agent $is_ua_whitelisted {';
    $lines[] = '    default 0;';
    foreach ($entries as $e) {
        // 防御性剔除换行（历史/被篡改的 JSON 可能含换行，避免注入新配置行）
        $ua = str_replace(["\r", "\n"], '', (string)($e['ua'] ?? ''));
        if ($ua === '') continue;
        // 字面量匹配：preg_quote 中和正则元字符，再转义 nginx 双引号字符串层
        $pattern = nginx_ua_pattern($ua);
        $cmt     = !empty($e['comment']) ? ' # ' . safe_comment($e['comment']) : '';
        $lines[] = "    \"~*{$pattern}\" 1;{$cmt}";
    }
    $lines[] = '}';

    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return subsieve_atomic_write_nginx_files([
        UA_WHITELIST_CONF => implode("\n", $lines) . "\n",
        UA_WHITELIST_JSON => $json . "\n",
    ]);
}
