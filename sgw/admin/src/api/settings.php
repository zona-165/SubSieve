<?php
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && !empty($_GET['import_alert_history'])) {
    try {
        import_alert_history();
    } catch (Throwable $e) {
        json_err('导入失败: ' . $e->getMessage());
    }
}

if ($method === 'POST' && !empty($_GET['preview_alert_history'])) {
    try {
        preview_alert_history();
    } catch (Throwable $e) {
        json_err('预览失败: ' . $e->getMessage());
    }
}

// GET — 读取当前设置
if ($method === 'GET') {
    try {
        if (!empty($_GET['export_alert_history'])) {
            export_alert_history();
        }
        $s = read_settings();
        $certInfo = get_cert_info();
        $statsCache = get_stats_cache_info();
        $historyLimit = isset($_GET['alert_history_limit']) ? (int)$_GET['alert_history_limit'] : 10;
        $historyPage = isset($_GET['alert_history_page']) ? (int)$_GET['alert_history_page'] : 1;
        $historyFilter = (string)($_GET['alert_history_filter'] ?? 'all');
        $historyQuery = (string)($_GET['alert_history_query'] ?? '');
        $historyRange = (string)($_GET['alert_history_range'] ?? 'all');
        $alertHistory = get_alert_history($historyLimit, $historyPage, $historyFilter, $historyQuery, $historyRange);
        if (empty($s['upstream_url']) || empty($s['subscribe_path'])) {
            $parsed = parse_protect_conf();
            if ($parsed) {
                $s['upstream_url']   = $s['upstream_url']   ?? $parsed['upstream_url'];
                $s['upstream_host']  = $s['upstream_host']  ?? $parsed['upstream_host'];
                $s['subscribe_path'] = $s['subscribe_path'] ?? $parsed['subscribe_path'];
            }
        }
        // 网关端口：优先取 settings.json 中保存的值，否则取容器环境变量（即 .env 当前值）
        if (empty($s['gateway_port'])) {
            $s['gateway_port'] = GATEWAY_PORT;
        }
        json_out(['ok' => true, 'settings' => $s, 'cert' => $certInfo, 'stats_cache' => $statsCache, 'alert_history' => $alertHistory]);
    } catch (Throwable $e) {
        json_err('PHP错误: ' . $e->getMessage());
    }
}

// POST — 保存设置
if ($method === 'POST') {
    try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // 仅同步部署信息
    if (!empty($body['_sync_deploy'])) {
        $s = read_settings();
        update_deploy_info($s);
        json_out(['ok' => true]);
    }

    if (!empty($body['_run_alert_check'])) {
        $result = run_alert_check_now();
        if (!$result['ok']) {
            json_err('告警检查失败: ' . ($result['error'] ?? 'unknown'));
        }
        json_out(['ok' => true, 'result' => $result['result'] ?? []]);
    }

    $s = read_settings();

    if (!empty($body['_clear_alert_history'])) {
        clear_alert_history(!empty($body['reset_state']));
        json_out(['ok' => true, 'msg' => !empty($body['reset_state']) ? '告警历史和去重状态已重置' : '告警历史已清空']);
    }

    if (!empty($body['_delete_alert_history_entry'])) {
        $deleted = delete_alert_history_entry($body);
        json_out(['ok' => true, 'deleted' => $deleted, 'msg' => '告警记录已删除']);
    }

    if (!empty($body['_test_alert'])) {
        $testSettings = apply_alert_settings($s, $body);
        $result = send_alert_message($testSettings, "SubSieve 测试告警\n这是一条后台测试通知。");
        if (!$result['ok']) {
            json_err('测试推送失败: ' . ($result['error'] ?? 'unknown'));
        }
        json_out(['ok' => true, 'msg' => '测试推送已发送']);
    }

    // ── 界面标题 ───────────────────────────────────────────────
    if (isset($body['site_title'])) $s['site_title'] = trim($body['site_title']) ?: 'SubSieve';
    if (isset($body['page_title'])) $s['page_title'] = trim($body['page_title']) ?: 'SubSieve Admin';

    // ── 管理员凭证 ─────────────────────────────────────────────
    if (!empty($body['admin_user'])) {
        $s['admin_user'] = trim($body['admin_user']);
    }
    if (!empty($body['new_pass'])) {
        $newPass = $body['new_pass'];
        $confPass = $body['confirm_pass'] ?? '';
        if ($newPass !== $confPass) {
            json_err('两次输入的密码不一致');
        }
        if (strlen($newPass) < 6) {
            json_err('密码至少需要6位');
        }
        $s['admin_pass'] = $newPass;
    }

    // ── 网关端口 ───────────────────────────────────────────────
    $gatewayPortChanged = false;
    if (isset($body['gateway_port'])) {
        $gp = (int)$body['gateway_port'];
        if ($gp < 1 || $gp > 65535) {
            json_err('网关端口无效（1-65535）');
        }
        $s['gateway_port'] = $gp;
        $gatewayPortChanged = true;
    }

    // ── 上游（机场）配置 ────────────────────────────────────────
    $upstreamChanged = false;
    if (isset($body['upstream_url']) && $body['upstream_url'] !== '') {
        // 上游地址会直接拼入 proxy_pass，拒绝换行 / { } ; 等可篡改反代的字符
        $url = safe_conf_value($body['upstream_url']);
        // 自动加 https:// 前缀
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $urlParts = parse_url($url);
        if (!is_array($urlParts) || !in_array(strtolower((string)($urlParts['scheme'] ?? '')), ['http', 'https'], true) || empty($urlParts['host'])) {
            json_err('上游地址格式无效');
        }
        $s['upstream_url'] = $url;
        // 自动提取 host（用于 proxy_set_header Host）
        $host = parse_url($url, PHP_URL_HOST);
        $s['upstream_host'] = safe_conf_value($host ?: $url);
        $upstreamChanged = true;
    }
    if (isset($body['subscribe_path']) && $body['subscribe_path'] !== '') {
        // 订阅路径会直接拼入 location ^~ ，同样拒绝结构字符
        $path = safe_conf_value($body['subscribe_path']);
        if (!str_starts_with($path, '/')) $path = '/' . $path;
        if (str_contains($path, '?')) json_err('订阅路径不能包含查询参数');
        $s['subscribe_path'] = $path;
        $upstreamChanged = true;
    }

    $s = apply_alert_settings($s, $body);

    // 保存 settings.json
    if (!write_settings($s)) {
        json_err('保存设置失败，请检查文件权限');
    }

    $nginxReloaded = false;
    $protectUpdated = false;

    // 若上游配置变更，重新生成 protect.conf
    if ($upstreamChanged && !empty($s['upstream_url']) && !empty($s['subscribe_path'])) {
        // 写入 nginx 配置前对三个结构性值统一兜底校验，
        // 覆盖可能来自旧 settings.json（本次未改动）的未校验值
        $safePath    = safe_conf_value($s['subscribe_path']);
        $safeBackend = safe_conf_value($s['upstream_url']);
        $safeHost    = safe_conf_value($s['upstream_host'] ?? (parse_url($s['upstream_url'], PHP_URL_HOST) ?: $s['upstream_url']));
        $protectUpdated = write_protect_conf($safePath, $safeBackend, $safeHost);
        if ($protectUpdated) {
            $nginxReloaded = nginx_reload();
        }
    }

    // 更新 DEPLOY_INFO.txt
    update_deploy_info($s);

    $msg = '设置已保存' . ($nginxReloaded ? '，nginx 已重载' : '');
    if ($gatewayPortChanged) {
        $msg .= '。网关端口已记录，需在宿主机执行 bash update.sh 后生效';
    }
    json_out([
        'ok'                   => true,
        'nginx_reloaded'       => $nginxReloaded,
        'protect_updated'      => $protectUpdated,
        'gateway_port_changed' => $gatewayPortChanged,
        'msg'                  => $msg,
    ]);
    } catch (Throwable $e) {
        json_err('PHP错误: ' . $e->getMessage());
    }
}

json_err('不支持的请求方式', 405);

// ── 辅助函数 ──────────────────────────────────────────────────

function read_settings(): array {
    if (!file_exists(SETTINGS_JSON)) return [];
    $raw = @file_get_contents(SETTINGS_JSON);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_settings(array $s): bool {
    return file_put_contents(SETTINGS_JSON, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function apply_alert_settings(array $s, array $body): array {
    if (array_key_exists('alert_enabled', $body)) {
        $s['alert_enabled'] = !empty($body['alert_enabled']) ? 1 : 0;
    }
    if (array_key_exists('alert_channel', $body)) {
        $channel = trim((string)$body['alert_channel']);
        $s['alert_channel'] = in_array($channel, ['webhook', 'telegram'], true) ? $channel : 'webhook';
    }
    if (array_key_exists('alert_quiet_enabled', $body)) {
        $s['alert_quiet_enabled'] = !empty($body['alert_quiet_enabled']) ? 1 : 0;
    }
    foreach (['alert_webhook_url', 'alert_telegram_bot_token', 'alert_telegram_chat_id'] as $key) {
        if (array_key_exists($key, $body)) {
            $s[$key] = trim((string)$body[$key]);
        }
    }
    foreach (['alert_quiet_start', 'alert_quiet_end'] as $key) {
        if (array_key_exists($key, $body)) {
            $value = trim((string)$body[$key]);
            $s[$key] = preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '';
        }
    }
    $intFields = [
        'alert_scanner_score' => [1, 100, 80],
        'alert_susp_ip_score' => [1, 100, 90],
        'alert_susp_token_ips' => [2, 50, 3],
        'alert_dedupe_minutes' => [1, 1440, 60],
        'alert_history_max' => [50, 1000, 200],
    ];
    foreach ($intFields as $key => [$min, $max, $default]) {
        if (array_key_exists($key, $body)) {
            $value = is_numeric($body[$key]) ? (int)$body[$key] : $default;
            if ($value < $min) $value = $min;
            if ($value > $max) $value = $max;
            $s[$key] = $value;
        }
    }
    return $s;
}

function send_alert_message(array $settings, string $text): array {
    if (empty($settings['alert_enabled'])) {
        return ['ok' => false, 'error' => '告警未开启'];
    }
    $channel = $settings['alert_channel'] ?? 'webhook';
    if ($channel === 'telegram') {
        return send_telegram_alert($settings, $text);
    }
    return send_webhook_alert($settings, $text);
}

function send_webhook_alert(array $settings, string $text): array {
    $url = trim((string)($settings['alert_webhook_url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => 'Webhook 地址无效'];
    }
    $payload = json_encode([
        'title' => 'SubSieve 告警',
        'text' => $text,
        'source' => 'SubSieve',
        'time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    $result = http_json_post($url, $payload);
    return $result['ok'] ? ['ok' => true] : $result;
}

function send_telegram_alert(array $settings, string $text): array {
    $token = trim((string)($settings['alert_telegram_bot_token'] ?? ''));
    $chatId = trim((string)($settings['alert_telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        return ['ok' => false, 'error' => 'Telegram Bot Token 或 Chat ID 未填写'];
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ], JSON_UNESCAPED_UNICODE);
    $result = http_json_post($url, $payload);
    return $result['ok'] ? ['ok' => true] : $result;
}

function http_json_post(string $url, string $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($raw === false) {
        return ['ok' => false, 'error' => '网络请求失败'];
    }
    if ($status >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $status . ': ' . substr($raw, 0, 160)];
    }
    return ['ok' => true, 'status' => $status, 'body' => $raw];
}

function run_alert_check_now(): array {
    if (!function_exists('exec')) {
        return ['ok' => false, 'error' => 'exec 函数不可用'];
    }
    $php = PHP_BINARY ?: 'php';
    $script = dirname(__DIR__) . '/maintenance.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' check-alerts 2>&1';
    $lines = [];
    $code = 0;
    @exec($cmd, $lines, $code);
    $raw = trim(implode("\n", $lines));
    $parsed = $raw !== '' ? json_decode($raw, true) : null;
    if ($code !== 0) {
        return ['ok' => false, 'error' => $raw ?: ('exit ' . $code)];
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => '维护脚本未返回 JSON: ' . substr($raw, 0, 160)];
    }
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => implode('; ', $parsed['errors'] ?? []) ?: ($parsed['error'] ?? 'unknown'), 'result' => $parsed];
    }
    return ['ok' => true, 'result' => $parsed];
}

function clear_alert_history(bool $resetState = false): void {
    $history = [
        'status' => [
            'last_check' => date('Y-m-d H:i:s'),
            'enabled' => false,
            'channel' => '',
            'events' => 0,
            'sent' => 0,
            'skipped' => 0,
            'errors' => [],
            'note' => $resetState ? 'reset' : 'history_cleared',
        ],
        'entries' => [],
    ];
    @file_put_contents(ALERT_HISTORY_JSON, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(ALERT_HISTORY_JSON, 0666);
    if ($resetState) {
        @file_put_contents(ALERT_STATE_JSON, "{}", LOCK_EX);
        @chmod(ALERT_STATE_JSON, 0666);
    }
}

function delete_alert_history_entry(array $body): int {
    $key = trim((string)($body['key'] ?? ''));
    $time = trim((string)($body['time'] ?? ''));
    $status = trim((string)($body['status'] ?? ''));
    if ($key === '' || $time === '' || $status === '') {
        json_err('缺少告警记录标识');
    }
    if (!defined('ALERT_HISTORY_JSON') || !file_exists(ALERT_HISTORY_JSON)) {
        json_err('告警历史不存在');
    }
    $raw = @file_get_contents(ALERT_HISTORY_JSON);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        json_err('告警历史格式无效');
    }
    $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $before = count($entries);
    $data['entries'] = array_values(array_filter($entries, function ($entry) use ($key, $time, $status) {
        if (!is_array($entry)) return false;
        return !(trim((string)($entry['key'] ?? '')) === $key
            && trim((string)($entry['time'] ?? '')) === $time
            && trim((string)($entry['status'] ?? '')) === $status);
    }));
    $deleted = $before - count($data['entries']);
    if ($deleted < 1) {
        json_err('未找到对应告警记录');
    }
    @file_put_contents(ALERT_HISTORY_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(ALERT_HISTORY_JSON, 0666);
    return $deleted;
}

/**
 * 重新生成 protect.conf（覆盖上游配置）
 */
function write_protect_conf(string $subscribePath, string $backend, string $host): bool {
    $conf = <<<NGINX
location ^~ $subscribePath {

    if (\$whitelist_ip = 1) { set \$block_reason ""; }

    if (\$is_cloud_ip = 1)       { set \$block_reason "cloud"; }
    if (\$bad_subscribe_ua = 1)  { set \$block_reason "ua"; }
    if (\$is_custom_bad_ua = 1)  { set \$block_reason "ua"; }
    if (\$is_token_blacklisted = 1) { set \$block_reason "token"; }
    if (\$is_ua_whitelisted = 1) { set \$block_reason ""; }

    if (\$whitelist_ip = 1) { set \$block_reason ""; }

    if (\$block_reason = "cloud") { return 403 "Forbidden: Cloud IP"; }
    if (\$block_reason = "ua")    { return 403 "Forbidden: Invalid Client"; }
    if (\$block_reason = "token") { return 403 "Forbidden: Token Blocked"; }

    limit_req zone=subscribe_limit burst=5 nodelay;
    limit_req_status 429;

    set \$upstream_backend $backend;
    proxy_pass          \$upstream_backend;
    proxy_set_header    Host              $host;
    proxy_set_header    X-Real-IP         \$remote_addr;
    proxy_set_header    X-Forwarded-For   \$proxy_add_x_forwarded_for;
    proxy_set_header    REMOTE-HOST       \$remote_addr;
    proxy_ssl_server_name on;
    proxy_ssl_name        $host;
    proxy_set_header    Upgrade           \$http_upgrade;
    proxy_set_header    Connection        \$connection_upgrade;
    proxy_http_version  1.1;
    proxy_connect_timeout 10s;
    proxy_send_timeout    15s;
    proxy_read_timeout    60s;

    add_header Cache-Control no-store;
    add_header X-Subscribe-Filter "active";
}
NGINX;
    return file_put_contents(PROTECT_CONF, $conf, LOCK_EX) !== false;
}

/**
 * 解析 protect.conf 提取上游配置
 */
function parse_protect_conf(): ?array {
    if (!file_exists(PROTECT_CONF)) return null;
    $content = @file_get_contents(PROTECT_CONF);
    if ($content === false) return null;
    $result = [];
    if (preg_match('/^location\s+\^~\s+(\S+)/m', $content, $m)) {
        $result['subscribe_path'] = $m[1];
    }
    // 优先从 "set $upstream_backend URL;" 提取（模板生成的 protect.conf 格式）
    if (preg_match('/set\s+\$upstream_backend\s+(\S+);/m', $content, $m)) {
        $result['upstream_url'] = rtrim($m[1], ';');
    } elseif (preg_match('/proxy_pass\s+(\S+);/m', $content, $m)) {
        $val = rtrim($m[1], ';');
        // 跳过 nginx 变量引用（如 $upstream_backend），只取真实 URL
        if (!str_starts_with($val, '$')) {
            $result['upstream_url'] = $val;
        }
    }
    if (preg_match('/proxy_set_header\s+Host\s+(\S+);/m', $content, $m)) {
        $result['upstream_host'] = rtrim($m[1], ';');
    }
    return $result ?: null;
}

/**
 * 获取 SSL 证书信息
 */
function get_cert_info(): array {
    $certFile = '/etc/nginx/ssl/cert.pem';
    if (!file_exists($certFile)) {
        return ['exists' => false];
    }
    $info = ['exists' => true, 'path' => $certFile];
    $certContent = @file_get_contents($certFile);
    if ($certContent === false) {
        return $info; // 无读取权限，返回 exists:true 但无 subject
    }
    $certData = @openssl_x509_parse($certContent);
    if ($certData) {
        $info['subject']   = $certData['subject']['CN'] ?? '';
        $info['valid_to']  = date('Y-m-d', $certData['validTo_time_t']);
        $info['valid_from']= date('Y-m-d', $certData['validFrom_time_t']);
        $info['issuer']    = $certData['issuer']['O'] ?? $certData['issuer']['CN'] ?? '';
        $san = '';
        if (!empty($certData['extensions']['subjectAltName'])) {
            $san = $certData['extensions']['subjectAltName'];
        }
        $info['san'] = $san;
        $daysLeft = (int)(($certData['validTo_time_t'] - time()) / 86400);
        $info['days_left'] = $daysLeft;
    }
    return $info;
}

function get_stats_cache_info(): array {
    $file = dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json';
    $info = [
        'exists' => file_exists($file),
        'path' => $file,
        'size' => 0,
        'size_text' => '0 B',
        'mtime' => '',
        'age_seconds' => null,
        'fresh' => false,
        'scan_limit' => 30000,
        'cached' => false,
    ];
    if (!$info['exists']) return $info;
    $size = (int)@filesize($file);
    $mtime = (int)@filemtime($file);
    $info['size'] = $size;
    $info['size_text'] = human_bytes($size);
    if ($mtime > 0) {
        $info['mtime'] = date('Y-m-d H:i:s', $mtime);
        $info['age_seconds'] = max(0, time() - $mtime);
        $info['fresh'] = $info['age_seconds'] <= 180;
    }
    $raw = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;
    if (is_array($data)) {
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $info['cached'] = isset($data['ts']);
        if (isset($payload['scan_limit'])) $info['scan_limit'] = (int)$payload['scan_limit'];
        if (!empty($data['ts'])) {
            $info['mtime'] = date('Y-m-d H:i:s', (int)$data['ts']);
            $info['age_seconds'] = max(0, time() - (int)$data['ts']);
            $info['fresh'] = $info['age_seconds'] <= 180;
        }
    }
    return $info;
}

function get_alert_history(int $limit = 10, int $page = 1, string $filter = 'all', string $query = '', string $range = 'all'): array {
    if (!in_array($limit, [10, 25, 50], true)) $limit = 10;
    if ($page < 1) $page = 1;
    if (!in_array($filter, ['all', 'sent', 'muted', 'error'], true)) $filter = 'all';
    if (!in_array($range, ['all', 'today', '24h', '7d'], true)) $range = 'all';
    $query = strtolower(trim($query));
    if (!defined('ALERT_HISTORY_JSON') || !file_exists(ALERT_HISTORY_JSON)) {
        return ['exists' => false, 'status' => [], 'entries' => []];
    }
    $raw = @file_get_contents(ALERT_HISTORY_JSON);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return ['exists' => true, 'status' => [], 'entries' => []];
    }
    $allEntries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
    $historySummary = summarize_alert_history([
        'entries' => $allEntries,
        'history_max' => alert_history_max_setting(),
    ]);
    $rangeStart = alert_history_range_start($range);
    $filteredEntries = array_values(array_filter($allEntries, function ($entry) use ($filter, $query, $rangeStart) {
        if (!is_array($entry)) return false;
        $status = (string)($entry['status'] ?? 'sent');
        if ($filter !== 'all' && $status !== $filter) return false;
        if ($rangeStart !== null) {
            $ts = strtotime((string)($entry['time'] ?? ''));
            if (!$ts || $ts < $rangeStart) return false;
        }
        if ($query === '') return true;
        $haystack = strtolower(implode(' ', [
            (string)($entry['title'] ?? ''),
            (string)($entry['summary'] ?? ''),
            (string)($entry['time'] ?? ''),
            (string)($entry['channel'] ?? ''),
            (string)($entry['key'] ?? ''),
        ]));
        return strpos($haystack, $query) !== false;
    }));
    $filteredTotal = count($filteredEntries);
    $totalPages = max(1, (int)ceil($filteredTotal / $limit));
    if ($page > $totalPages) $page = $totalPages;
    $entries = array_slice($filteredEntries, ($page - 1) * $limit, $limit);
    $quietEntries = array_values(array_filter($allEntries, fn($e) => is_array($e) && ($e['status'] ?? '') === 'muted'));
    $latestQuiet = $quietEntries[0] ?? null;
    return [
        'exists' => true,
        'status' => is_array($data['status'] ?? null) ? $data['status'] : [],
        'entries' => $entries,
        'limit' => $limit,
        'page' => $page,
        'total_pages' => $totalPages,
        'total' => count($allEntries),
        'filtered_total' => $filteredTotal,
        'filter' => $filter,
        'query' => $query,
        'range' => $range,
        'summary' => $historySummary,
        'quiet_summary' => [
            'count' => count($quietEntries),
            'latest_time' => is_array($latestQuiet) ? ($latestQuiet['time'] ?? '') : '',
            'latest_title' => is_array($latestQuiet) ? ($latestQuiet['title'] ?? '') : '',
            'latest_summary' => is_array($latestQuiet) ? ($latestQuiet['summary'] ?? '') : '',
        ],
    ];
}

function alert_history_range_start(string $range): ?int {
    if ($range === 'today') return strtotime(date('Y-m-d 00:00:00')) ?: null;
    if ($range === '24h') return time() - 86400;
    if ($range === '7d') return time() - 7 * 86400;
    return null;
}

function export_alert_history(): void {
    $payload = [
        'exported_at' => date('Y-m-d H:i:s'),
        'history' => [],
    ];
    if (defined('ALERT_HISTORY_JSON') && file_exists(ALERT_HISTORY_JSON)) {
        $raw = @file_get_contents(ALERT_HISTORY_JSON);
        $data = $raw ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $payload['history'] = $data;
        }
    }
    $filename = 'subsieve-alert-history-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_uploaded_alert_history(): array {
    if (empty($_FILES['history']) || !is_uploaded_file($_FILES['history']['tmp_name'])) {
        json_err('请选择告警历史 JSON 文件');
    }
    if ((int)($_FILES['history']['size'] ?? 0) > 1024 * 1024) {
        json_err('文件过大，最多 1MB');
    }
    $raw = @file_get_contents($_FILES['history']['tmp_name']);
    if ($raw === false || trim($raw) === '') {
        json_err('文件为空或无法读取');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_err('JSON 格式无效');
    }
    $history = is_array($data['history'] ?? null) ? $data['history'] : $data;
    $context = is_array($data['context'] ?? null) ? $data['context'] : (is_array($history['context'] ?? null) ? $history['context'] : null);
    if (!is_array($history['entries'] ?? null)) {
        json_err('不是有效的告警历史文件');
    }
    $status = is_array($history['status'] ?? null) ? $history['status'] : [];
    $historyMax = alert_history_max_setting();
    $entries = array_slice(array_values(array_filter($history['entries'], 'is_array')), 0, $historyMax);
    $result = [
        'status' => $status,
        'entries' => $entries,
        'original_entries' => count(array_filter($history['entries'], 'is_array')),
        'history_max' => $historyMax,
    ];
    if (!empty($data['exported_at'])) $result['exported_at'] = (string)$data['exported_at'];
    if ($context !== null) $result['context'] = $context;
    return $result;
}

function alert_history_max_setting(): int {
    $settings = read_settings();
    $value = is_numeric($settings['alert_history_max'] ?? null) ? (int)$settings['alert_history_max'] : 200;
    if ($value < 50) $value = 50;
    if ($value > 1000) $value = 1000;
    return $value;
}

function summarize_alert_history(array $history): array {
    $entries = is_array($history['entries'] ?? null) ? $history['entries'] : [];
    $summary = [
        'total' => count($entries),
        'sent' => 0,
        'muted' => 0,
        'error' => 0,
        'first_time' => '',
        'last_time' => '',
        'truncated' => !empty($history['original_entries']) && (int)$history['original_entries'] > count($entries),
        'original_total' => (int)($history['original_entries'] ?? count($entries)),
        'history_max' => (int)($history['history_max'] ?? count($entries)),
        'exported_at' => (string)($history['exported_at'] ?? ''),
    ];
    if (is_array($history['context'] ?? null)) {
        $summary['context'] = $history['context'];
    }
    $times = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $status = (string)($entry['status'] ?? 'sent');
        if (isset($summary[$status])) $summary[$status]++;
        if (!empty($entry['time'])) $times[] = (string)$entry['time'];
    }
    sort($times);
    if ($times) {
        $summary['first_time'] = $times[0];
        $summary['last_time'] = $times[count($times) - 1];
    }
    return $summary;
}

function preview_alert_history(): void {
    $history = read_uploaded_alert_history();
    json_out(['ok' => true, 'preview' => summarize_alert_history($history)]);
}

function import_alert_history(): void {
    $history = read_uploaded_alert_history();
    $safeHistory = [
        'status' => $history['status'],
        'entries' => $history['entries'],
    ];
    if (is_array($history['context'] ?? null)) {
        $safeHistory['context'] = $history['context'];
    }
    if (!file_put_contents(ALERT_HISTORY_JSON, json_encode($safeHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX)) {
        json_err('写入告警历史失败，请检查权限');
    }
    @chmod(ALERT_HISTORY_JSON, 0666);
    json_out(['ok' => true, 'imported' => count($history['entries']), 'preview' => summarize_alert_history($history)]);
}

function human_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float)$bytes;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'GB') {
            return ($unit === 'B' ? (string)(int)$value : number_format($value, 1)) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

/**
 * 更新 DEPLOY_INFO.txt（在共享日志卷中）
 */
function update_deploy_info(array $s): void {
    $protectInfo = parse_protect_conf();
    $subscribePath = $protectInfo['subscribe_path'] ?? $s['subscribe_path'] ?? '—';
    $upstreamUrl   = $protectInfo['upstream_url']   ?? $s['upstream_url']   ?? '—';
    $adminUser     = $s['admin_user'] ?? ADMIN_USER;
    $siteTitle     = $s['site_title'] ?? SITE_TITLE;
    $now           = date('Y-m-d H:i:s');

    $content = <<<TXT
$siteTitle 部署信息
更新时间: $now

管理后台
  用户名: $adminUser
  （密码已隐藏，请从系统设置中修改）

订阅网关
  订阅路径: $subscribePath
  代理到:   $upstreamUrl
TXT;
    @file_put_contents(DEPLOY_INFO_FILE, $content, LOCK_EX);
}
