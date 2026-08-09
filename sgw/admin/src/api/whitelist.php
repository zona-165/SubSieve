<?php
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET — 列出白名单
if ($method === 'GET') {
    json_out(['ok' => true, 'entries' => read_whitelist()]);
}

// POST — 添加条目（单个或批量导入）
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // 批量导入（来自文件导入）
    if (!empty($body['import_ips']) && is_array($body['import_ips'])) {
        $entries = read_whitelist();
        $existingSet = [];
        foreach ($entries as $e) $existingSet[$e['ip']] = true;
        $added = 0; $skipped = 0; $invalid = 0;
        foreach ($body['import_ips'] as $rawIp) {
            $ip = trim($rawIp);
            if (!$ip) continue;
            if (!is_valid_ip_or_cidr($ip, true)) { $invalid++; continue; }
            if (isset($existingSet[$ip])) { $skipped++; continue; }
            $entries[] = ['ip' => $ip, 'comment' => '从文件导入'];
            $existingSet[$ip] = true;
            $added++;
        }
        if ($added > 0) {
            if (!write_whitelist($entries)) json_err('白名单保存或重载提交失败，旧规则已保留');
        }
        json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'invalid' => $invalid]);
    }

    // 单个添加
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip || !is_valid_ip_or_cidr($ip, true)) {
        json_err('IP 格式不合法');
    }

    $entries = read_whitelist();
    foreach ($entries as $e) {
        if ($e['ip'] === $ip) json_err('该IP已在白名单中');
    }

    $entries[] = ['ip' => $ip, 'comment' => $comment];
    if (!write_whitelist($entries)) json_err('白名单保存或重载提交失败，旧规则已保留');

    json_out(['ok' => true]);
}

// PATCH — 更新备注
if ($method === 'PATCH') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip) json_err('缺少 ip 参数');
    if (!file_exists(WHITELIST_IPS)) json_err('白名单文件不存在');

    $lines = file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES);
    $found = false;
    $new   = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $new[] = $line;
            continue;
        }
        if (preg_match('/^(\S+)/', $trimmed, $m) && $m[1] === $ip) {
            $new[] = $ip . ($comment ? "  # $comment" : '');
            $found = true;
        } else {
            $new[] = $line;
        }
    }

    if (!$found) json_err('未找到该IP');
    if (!subsieve_atomic_write_nginx_files([WHITELIST_IPS => implode("\n", $new) . "\n"], true)) {
        json_err('白名单备注保存或重载提交失败，旧规则已保留');
    }
    json_out(['ok' => true]);
}

// DELETE — 删除条目（支持单个 ip 或批量 ips 数组）
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $toRemove = [];
    if (!empty($body['ips']) && is_array($body['ips'])) {
        $toRemove = array_map('trim', $body['ips']);
    } else {
        $ip = trim($body['ip'] ?? '');
        if (!$ip) json_err('缺少 ip 参数');
        $toRemove = [$ip];
    }

    $lines = file_exists(WHITELIST_IPS)
        ? file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES)
        : [];

    $new = array_filter($lines, function($l) use ($toRemove) {
        $entry = strtok(trim($l), " \t");
        return !in_array($entry, $toRemove);
    });

    if (!subsieve_atomic_write_nginx_files([WHITELIST_IPS => implode("\n", $new) . "\n"], true)) {
        json_err('白名单删除或重载提交失败，旧规则已保留');
    }
    json_out(['ok' => true]);
}

json_err('不支持的请求方式', 405);

// ── 读取并解析白名单文件 ──────────────────────────────────────
function read_whitelist(): array {
    if (!file_exists(WHITELIST_IPS)) return [];

    $entries = [];
    foreach (file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        // 分离 IP 和注释
        $comment = '';
        if (preg_match('/^(\S+)\s+#\s*(.*)$/', $line, $m)) {
            $ip      = $m[1];
            $comment = $m[2];
        } else {
            $ip = strtok($line, " \t");
        }

        $entries[] = ['ip' => $ip, 'comment' => $comment];
    }
    return $entries;
}

function write_whitelist(array $entries): bool {
    $lines = ['# IP 白名单 - 由 admin 自动生成 | ' . date('Y-m-d H:i:s')];
    foreach ($entries as $entry) {
        $ip = trim((string)($entry['ip'] ?? ''));
        if (!is_valid_ip_or_cidr($ip, true)) continue;
        $comment = safe_comment((string)($entry['comment'] ?? ''));
        $lines[] = $ip . ($comment !== '' ? '  # ' . $comment : '');
    }
    return subsieve_atomic_write_nginx_files([
        WHITELIST_IPS => implode("\n", $lines) . "\n",
    ], true);
}
