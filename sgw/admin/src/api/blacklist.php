<?php
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET — 列出黑名单
// ?no_idc=1     跳过IDC概要（日志页仅需IP集合时使用）
// ?cloud_cidrs=1 仅返回云服务商CIDR列表（供前端云IP检测使用）
if ($method === 'GET') {
    if (!empty($_GET['cloud_cidrs'])) {
        json_out(['ok' => true, 'cidrs' => read_cloud_cidrs()]);
    }
    $idc = empty($_GET['no_idc']) ? read_idc_summary() : [];
    json_out([
        'ok' => true,
        'entries' => read_blacklist(),
        'idc_summary' => $idc,
        'cloud_provider_status' => read_json_file(CLOUD_PROVIDER_STATUS_JSON),
    ]);
}

// POST — 添加并立即生效（单个或批量导入）
if ($method === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];

    if (($body['action'] ?? '') === 'save_cloud_providers') {
        save_cloud_provider_settings($body);
    }

    // 批量导入（来自文件导入）
    if (!empty($body['import_ips']) && is_array($body['import_ips'])) {
        $entries    = read_blacklist();
        $existingSet = [];
        foreach ($entries as $e) $existingSet[$e['ip']] = true;
        $added = 0; $skipped = 0; $invalid = 0;
        foreach ($body['import_ips'] as $rawIp) {
            $ip = trim($rawIp);
            if (!$ip) continue;
            // 支持 IP 和 CIDR
            if (!is_valid_ip_or_cidr($ip, false)) { $invalid++; continue; }
            if (isset($existingSet[$ip])) { $skipped++; continue; }
            $entries[] = ['ip' => $ip, 'comment' => '从文件导入', 'added_at' => date('Y-m-d H:i')];
            $existingSet[$ip] = true;
            $added++;
        }
        if ($added > 0) {
            if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
            $reload = nginx_reload();
        } else {
            $reload = false;
        }
        json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'invalid' => $invalid, 'nginx_reloaded' => $reload]);
    }

    // 单个添加
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip || !is_valid_ip_or_cidr($ip, false)) {
        json_err('IP 格式不合法（仅支持 IPv4）');
    }

    $entries = read_blacklist();
    foreach ($entries as $e) {
        if ($e['ip'] === $ip) json_err('该IP已在黑名单中');
    }

    $entries[] = [
        'ip'       => $ip,
        'comment'  => $comment,
        'added_at' => date('Y-m-d H:i'),
    ];

    if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
    $reload = nginx_reload();

    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

// PATCH — 更新备注（仅更新 JSON，不 reload nginx）
if ($method === 'PATCH') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $ip      = trim($body['ip'] ?? '');
    $comment = safe_comment($body['comment'] ?? '');

    if (!$ip) json_err('缺少 ip 参数');

    $entries = read_blacklist();
    $found   = false;
    foreach ($entries as &$e) {
        if ($e['ip'] === $ip) { $e['comment'] = $comment; $found = true; break; }
    }
    unset($e);

    if (!$found) json_err('未找到该IP');
    file_put_contents(BLACKLIST_JSON, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    json_out(['ok' => true]);
}

// DELETE — 移除并立即生效（支持单个 ip 或批量 ips 数组）
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // 批量
    if (!empty($body['ips']) && is_array($body['ips'])) {
        $toRemove = array_map('trim', $body['ips']);
        $entries  = array_values(array_filter(read_blacklist(), fn($e) => !in_array($e['ip'], $toRemove)));
        if (!write_blacklist($entries)) json_err('写入黑名单文件失败，请检查文件权限');
        $reload = nginx_reload();
        json_out(['ok' => true, 'nginx_reloaded' => $reload]);
    }

    // 单个
    $ip = trim($body['ip'] ?? '');
    if (!$ip) json_err('缺少 ip 参数');

    $entries = array_filter(read_blacklist(), fn($e) => $e['ip'] !== $ip);
    if (!write_blacklist(array_values($entries))) json_err('写入黑名单文件失败，请检查文件权限');
    $reload = nginx_reload();

    json_out(['ok' => true, 'nginx_reloaded' => $reload]);
}

json_err('不支持的请求方式', 405);

// ── 读写黑名单 ────────────────────────────────────────────────

function read_blacklist(): array {
    if (!file_exists(BLACKLIST_JSON)) return [];
    $data = json_decode(file_get_contents(BLACKLIST_JSON), true);
    return is_array($data) ? $data : [];
}

function write_blacklist(array $entries): bool {
    $r1 = file_put_contents(BLACKLIST_JSON, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    $lines = ["# 黑名单 - 由 admin 自动生成 | " . date('Y-m-d H:i:s')];
    foreach ($entries as $e) {
        $ip = trim((string)($e['ip'] ?? ''));
        // 防御性校验 IP/CIDR，避免被篡改的 JSON 通过 IP 字段注入 nginx 指令
        if (!is_valid_ip_or_cidr($ip, false)) continue;
        $at      = safe_comment($e['added_at'] ?? '');
        $cmtText = safe_comment($e['comment'] ?? '');
        $cmt = $cmtText !== '' ? " # {$cmtText} ({$at})" : " # {$at}";
        $lines[] = "deny {$ip};{$cmt}";
    }
    $r2 = file_put_contents(BLACKLIST_CONF, implode("\n", $lines) . "\n", LOCK_EX);

    return $r1 !== false && $r2 !== false;
}

// ── 读取 cloud_geo.conf 返回所有CIDR列表（供前端IP范围匹配）──────

function read_cloud_cidrs(): array {
    if (!file_exists(CLOUD_GEO_CONF)) return [];
    $cidrs = [];
    foreach (file(CLOUD_GEO_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (preg_match('/^(\d[\d\.\/]+) (?:1|[a-z0-9_]+);$/', $line, $m)) {
            $cidrs[] = $m[1];
        }
    }
    return $cidrs;
}

// ── 读取 cloud_geo.conf 返回各IDC汇总 ──────────────────────────

function read_idc_summary(): array {
    $catalog = read_json_file(CLOUD_PROVIDER_CATALOG_JSON);
    $providers = is_array($catalog['providers'] ?? null) ? $catalog['providers'] : [];
    if ($providers) {
        $settings = read_json_file(CLOUD_PROVIDER_SETTINGS_JSON);
        $enabled = is_array($settings['enabled'] ?? null) ? $settings['enabled'] : [];
        $state = read_json_file(CLOUD_PROVIDER_STATE_JSON);
        $active = is_array($state['providers'] ?? null) ? $state['providers'] : [];
        $summary = [];
        foreach ($providers as $provider) {
            if (!is_array($provider)) continue;
            $id = trim((string)($provider['id'] ?? ''));
            if ($id === '') continue;
            $stateRow = is_array($active[$id] ?? null) ? $active[$id] : [];
            $desired = array_key_exists($id, $enabled)
                ? !empty($enabled[$id])
                : !empty($provider['default_enabled']);
            $sourceType = (string)($provider['source']['type'] ?? 'none');
            $summary[] = [
                'id' => $id,
                'name' => (string)($provider['name'] ?? $id),
                'asns' => array_values(array_filter(array_map('strval', is_array($provider['asns'] ?? null) ? $provider['asns'] : []))),
                'keywords' => array_values(array_filter(array_map('strval', is_array($provider['keywords'] ?? null) ? $provider['keywords'] : []))),
                'default_enabled' => !empty($provider['default_enabled']),
                'enabled' => $desired,
                'active' => array_key_exists('active', $stateRow)
                    ? !empty($stateRow['active'])
                    : ($desired && (int)($stateRow['active_count'] ?? 0) > 0),
                'count' => (int)($stateRow['cidr_count'] ?? 0),
                'active_count' => (int)($stateRow['active_count'] ?? 0),
                'available' => $sourceType !== 'none',
                'source_type' => $sourceType,
            ];
        }
        return $summary;
    }

    if (!file_exists(CLOUD_GEO_CONF)) return [];

    $summary = [];
    $current = null;
    $count   = 0;

    foreach (file(CLOUD_GEO_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (preg_match('/^# === (.+?)(?: \[[a-z0-9_]+\])? ===$/', $line, $m)) {
            if ($current !== null && $count > 0) {
                $summary[] = ['name' => $current, 'count' => $count];
            }
            $current = $m[1];
            $count   = 0;
        } elseif ($current !== null && preg_match('/^\d[\d\.\/]+ (?:1|[a-z0-9_]+);$/', $line)) {
            $count++;
        }
    }
    if ($current !== null && $count > 0) {
        $summary[] = ['name' => $current, 'count' => $count];
    }
    return $summary;
}

function read_json_file(string $file): array {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function save_cloud_provider_settings(array $body): void {
    $requested = is_array($body['enabled'] ?? null) ? $body['enabled'] : null;
    if ($requested === null) json_err('缺少厂商开关数据');

    $catalog = read_json_file(CLOUD_PROVIDER_CATALOG_JSON);
    $providers = is_array($catalog['providers'] ?? null) ? $catalog['providers'] : [];
    if (!$providers) json_err('云厂商目录尚未生成，请稍后重试', 503);

    $normalized = [];
    $unavailable = [];
    foreach ($providers as $provider) {
        if (!is_array($provider)) continue;
        $id = trim((string)($provider['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-z0-9_]+$/', $id)) continue;
        $value = array_key_exists($id, $requested)
            ? !empty($requested[$id])
            : !empty($provider['default_enabled']);
        if ($value && (string)($provider['source']['type'] ?? 'none') === 'none') {
            $unavailable[] = (string)($provider['name'] ?? $id);
            $value = false;
        }
        $normalized[$id] = $value;
    }
    if (!$normalized) json_err('云厂商目录为空');

    $payload = [
        'version' => 1,
        'enabled' => $normalized,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) json_err('厂商策略编码失败');

    $tmp = CLOUD_PROVIDER_SETTINGS_JSON . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) json_err('厂商策略写入失败，请检查权限');
    @chmod($tmp, 0666);
    if (file_exists(CLOUD_PROVIDER_SETTINGS_JSON)) {
        @copy(CLOUD_PROVIDER_SETTINGS_JSON, CLOUD_PROVIDER_SETTINGS_JSON . '.prev');
    }
    if (!@rename($tmp, CLOUD_PROVIDER_SETTINGS_JSON)) {
        @unlink($tmp);
        json_err('厂商策略替换失败');
    }
    if (@file_put_contents(CLOUD_PROVIDER_REFRESH_SIGNAL, (string)time(), LOCK_EX) === false) {
        if (file_exists(CLOUD_PROVIDER_SETTINGS_JSON . '.prev')) {
            @rename(CLOUD_PROVIDER_SETTINGS_JSON . '.prev', CLOUD_PROVIDER_SETTINGS_JSON);
        }
        json_err('无法通知网关应用厂商策略');
    }
    invalidate_guard_cache();
    json_out([
        'ok' => true,
        'applying' => true,
        'enabled_count' => count(array_filter($normalized)),
        'unavailable' => $unavailable,
    ]);
}
