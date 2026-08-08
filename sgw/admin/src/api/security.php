<?php
if (PHP_SAPI === 'cli') {
    require_once dirname(__DIR__) . '/config.php';
} else {
    require_once __DIR__ . '/_auth.php';
}
require_once dirname(__DIR__) . '/lib/guard.php';

$method = PHP_SAPI === 'cli' ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = (string)($body['action'] ?? '');
    if ($action === 'review') save_guard_review($body);
    if ($action === 'release_pull_limit') {
        $fingerprint = trim((string)($body['fingerprint'] ?? ''));
        if (!guard_release_pull_limit($fingerprint)) json_err('暂停记录不存在或已解除', 404);
        json_out(['ok' => true, 'fingerprint' => $fingerprint]);
    }
    json_err('不支持的操作');
}

if (!in_array($method, ['GET', 'CLI'], true)) json_err('不支持的请求方式', 405);

$force = $method === 'CLI' || (isset($_GET['refresh']) && $_GET['refresh'] === '1');
$cache = !$force ? guard_read_json(GUARD_CACHE_JSON) : [];
if (!$force && isset($cache['ts'], $cache['data']) && time() - (int)$cache['ts'] <= 45) {
    $payload = attach_guard_reviews(is_array($cache['data']) ? $cache['data'] : []);
    $payload['cached'] = true;
    json_out($payload);
}

$payload = build_security_snapshot();
guard_write_json_atomic(GUARD_CACHE_JSON, ['ts' => time(), 'data' => $payload]);
$payload = attach_guard_reviews($payload);
$payload['cached'] = false;
json_out($payload);

function build_security_snapshot(): array {
    $settings = guard_read_json(SETTINGS_JSON);
    $rules = guard_normalize_settings($settings);
    $subscribePath = trim((string)($settings['subscribe_path'] ?? '/api/v1/client/subscribe'));
    if ($subscribePath === '') $subscribePath = '/api/v1/client/subscribe';
    $secret = guard_secret();
    $logLines = iterator_to_array(guard_tail_log_lines(LOG_FILE, $rules['guard_scan_lines']), false);
    $analysis = guard_analyze_logs(
        $logLines,
        $rules,
        time(),
        $subscribePath,
        $secret
    );
    $pullLimits = guard_analyze_pull_limits(
        $logLines,
        $rules,
        time(),
        $subscribePath,
        $secret,
        guard_read_json(TOKEN_LIMIT_STATE_JSON),
        false
    );
    foreach ($pullLimits['usage'] as &$row) unset($row['_raw_token']);
    unset($row);
    unset($pullLimits['_state'], $pullLimits['_all_usage']);

    $statsCacheFile = dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json';
    $statsCache = guard_read_json($statsCacheFile);
    $statsData = is_array($statsCache['data'] ?? null) ? $statsCache['data'] : [];
    $whitelistIps = guard_whitelist_ip_set();
    $findings = guard_add_daily_ip_volume_findings(
        $analysis['findings'] ?? [],
        is_array($statsData['pull_ips'] ?? null) ? $statsData['pull_ips'] : [],
        $rules,
        time(),
        $secret,
        $whitelistIps
    );
    $findings = enrich_guard_findings($findings, $statsData, $secret, $rules, $whitelistIps);
    $counts = guard_policy_counts();
    $health = guard_health_snapshot($settings, $counts, $statsCacheFile);
    $actions = guard_recent_actions($secret);

    $metrics = $analysis['metrics'] ?? [];
    $metrics['risk_findings'] = count($findings);
    $metrics['blocked_ips'] = $counts['ip_blacklist'];
    $metrics['blocked_tokens'] = $counts['token_blacklist'];
    $metrics['suspended_tokens'] = (int)($pullLimits['summary']['suspended_tokens'] ?? 0);

    return [
        'ok' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'subscribe_path' => $subscribePath,
        'mode' => !empty($rules['guard_observe_enabled']) ? 'observe' : 'paused',
        'scope' => '独立网关日志，不连接机场用户、订单或邮箱数据库',
        'metrics' => $metrics,
        'policy_counts' => $counts,
        'rules' => $rules,
        'health' => $health,
        'mechanisms' => guard_mechanisms($rules, $counts, $health),
        'pull_limits' => $pullLimits,
        'findings' => $findings,
        'recent_actions' => $actions,
    ];
}

function enrich_guard_findings(
    array $findings,
    array $statsData,
    string $secret,
    array $settings = [],
    array $whitelistIps = []
): array {
    $rules = guard_normalize_settings($settings);
    $result = [];
    $liveIpTokenSubjects = [];
    $liveTokenIpSubjects = [];
    foreach ($findings as $finding) {
        if (empty($finding['source'])) $finding['source'] = '实时日志阈值';
        $result[$finding['key']] = $finding;
        if (($finding['kind'] ?? '') === 'ip_multi_token') $liveIpTokenSubjects[(string)($finding['subject'] ?? '')] = true;
        if (($finding['kind'] ?? '') === 'token_multi_ip') $liveTokenIpSubjects[(string)($finding['subject'] ?? '')] = true;
    }

    foreach (array_slice($statsData['scanner_reports'] ?? [], 0, 20) as $row) {
        $ip = trim((string)($row['ip'] ?? ''));
        if ($ip === '' || isset($whitelistIps[$ip])) continue;
        $token = (string)($row['token'] ?? '');
        $subject = $ip;
        $key = guard_finding_key('scanner', $ip . '|' . $token . '|' . (string)($row['ua'] ?? ''), $secret);
        $result[$key] = [
            'key' => $key,
            'kind' => 'scanner',
            'title' => '脚本/扫描器拉取订阅',
            'subject' => $subject,
            'token_fingerprint' => $token !== '' ? guard_token_fingerprint($token, $secret) : '',
            'count' => 1,
            'threshold' => 1,
            'window' => '近期日志',
            'reason' => (string)($row['reason'] ?? '可疑客户端特征'),
            'score' => (int)($row['score'] ?? 90),
            'risk' => (string)($row['risk'] ?? '高危'),
            'last_seen_ts' => strtotime((string)($row['time'] ?? '')) ?: 0,
            'last_seen' => (string)($row['time'] ?? ''),
            'source' => '统计缓存',
            'ua' => (string)($row['ua'] ?? ''),
            'path' => (string)($row['path'] ?? ''),
            'location' => (string)($row['location'] ?? '未查询'),
            'asn' => (string)($row['asn'] ?? '未查询'),
        ];
    }

    foreach (array_slice($statsData['susp_ips'] ?? [], 0, 20) as $row) {
        $ip = trim((string)($row['ip'] ?? ''));
        if ($ip === '' || isset($whitelistIps[$ip])) continue;
        $count = (int)($row['token_count'] ?? 0);
        if ($count < $rules['guard_ip_hour_tokens'] || isset($liveIpTokenSubjects[$ip])) continue;
        $finding = guard_finding(
            'history_ip_tokens', $ip, '日志窗口 IP 拉取多个 Token', $count,
            $rules['guard_ip_hour_tokens'], '最近日志窗口',
            '成功订阅请求中的 Token 去重统计超过观察阈值。',
            strtotime((string)($row['last_time'] ?? '')) ?: time(), $secret,
            ['token_count' => $count]
        );
        $finding['source'] = '统计缓存';
        $result[$finding['key']] = $finding;
    }

    foreach (array_slice($statsData['susp_tokens'] ?? [], 0, 20) as $row) {
        $token = trim((string)($row['token'] ?? ''));
        if ($token === '') continue;
        $tokenRef = guard_token_fingerprint($token, $secret);
        $count = (int)($row['ip_count'] ?? 0);
        if ($count < $rules['guard_token_hour_ips'] || isset($liveTokenIpSubjects[$tokenRef])) continue;
        $finding = guard_finding(
            'history_token_ips', $tokenRef, '日志窗口 Token 被多 IP 拉取', $count,
            $rules['guard_token_hour_ips'], '最近日志窗口',
            'Token 指纹在最近日志窗口内出现多个来源 IP。',
            strtotime((string)($row['last_time'] ?? '')) ?: time(), $secret,
            ['sample_ips' => array_slice(is_array($row['ips'] ?? null) ? $row['ips'] : [], 0, 8)]
        );
        $finding['source'] = '统计缓存';
        $result[$finding['key']] = $finding;
    }

    $result = array_values($result);
    $intelCache = guard_read_json(IP_INTEL_CACHE_JSON);
    foreach ($result as &$finding) {
        $subject = (string)($finding['subject'] ?? '');
        if (!filter_var($subject, FILTER_VALIDATE_IP)) continue;
        $intelEntry = is_array($intelCache[$subject] ?? null) ? $intelCache[$subject] : [];
        $intel = is_array($intelEntry['data'] ?? null) ? $intelEntry['data'] : [];
        if (!$intel || !empty($intel['query_failed'])) continue;
        $finding['location'] = (string)($intel['location'] ?? ($finding['location'] ?? '未查询'));
        $finding['asn'] = (string)($intel['asn'] ?? ($finding['asn'] ?? '未查询'));
        $finding['operator'] = (string)($intel['operator'] ?? '未知运营商');
        $finding['network_type'] = (string)($intel['network_type'] ?? '未知网络');
    }
    unset($finding);
    usort($result, fn(array $a, array $b) => ($b['score'] <=> $a['score']) ?: (($b['last_seen_ts'] ?? 0) <=> ($a['last_seen_ts'] ?? 0)));
    return array_slice($result, 0, 100);
}

function guard_secret(): string {
    $secret = file_exists(GUARD_SECRET_FILE) ? trim((string)@file_get_contents(GUARD_SECRET_FILE)) : '';
    if (preg_match('/^[a-f0-9]{64}$/', $secret)) return $secret;
    return hash('sha256', ADMIN_SECRET_PATH . '|' . ADMIN_USER . '|SubSieve-Guard');
}

function guard_policy_counts(): array {
    $limitState = guard_read_json(TOKEN_LIMIT_STATE_JSON);
    return [
        'ip_blacklist' => count_json_entries(BLACKLIST_JSON),
        'token_blacklist' => count_json_entries(TOKEN_BLACKLIST_JSON),
        'ua_blacklist' => count_json_entries(UA_BLACKLIST_JSON),
        'ua_whitelist' => count_json_entries(UA_WHITELIST_JSON),
        'ip_whitelist' => count_list_entries(WHITELIST_IPS),
        'cloud_cidrs' => count_cloud_cidrs(),
        'ip_intel_cache' => count(guard_read_json(IP_INTEL_CACHE_JSON)),
        'token_suspended' => count(is_array($limitState['entries'] ?? null) ? $limitState['entries'] : []),
    ];
}

function guard_whitelist_ip_set(): array {
    $result = [];
    if (!file_exists(WHITELIST_IPS)) return $result;
    foreach (file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $ip = strtok($line, " \t#");
        if ($ip !== false && filter_var($ip, FILTER_VALIDATE_IP)) $result[$ip] = true;
    }
    return $result;
}

function count_json_entries(string $file): int {
    return count(guard_read_json($file));
}

function count_list_entries(string $file): int {
    if (!file_exists($file)) return 0;
    $count = 0;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) $count++;
    }
    return $count;
}

function count_cloud_cidrs(): int {
    if (!file_exists(CLOUD_GEO_CONF)) return 0;
    $count = 0;
    foreach (file(CLOUD_GEO_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(?:\d{1,3}\.){3}\d{1,3}\/\d+\s+1;\s*$/', $line)) $count++;
    }
    return $count;
}

function guard_health_snapshot(array $settings, array $counts, string $statsCacheFile): array {
    clearstatcache();
    $statsAge = file_exists($statsCacheFile) ? max(0, time() - (int)filemtime($statsCacheFile)) : null;
    $cloudAge = file_exists(CLOUD_GEO_CONF) ? max(0, time() - (int)filemtime(CLOUD_GEO_CONF)) : null;
    $logSize = file_exists(LOG_FILE) ? (int)filesize(LOG_FILE) : 0;
    $alertHistory = guard_read_json(ALERT_HISTORY_JSON);
    $limitState = guard_read_json(TOKEN_LIMIT_STATE_JSON);
    $limitAge = isset($limitState['updated_ts']) ? max(0, time() - (int)$limitState['updated_ts']) : null;
    $lastAlertCheck = (string)($alertHistory['status']['last_check'] ?? '');
    $issues = [];
    if (!file_exists(PROTECT_CONF) || !str_contains((string)@file_get_contents(PROTECT_CONF), 'limit_req')) $issues[] = '订阅保护配置缺失';
    if (!is_readable(LOG_FILE) || !is_writable(LOG_FILE)) $issues[] = '日志文件不可读写';
    if ($statsAge === null || $statsAge > 180) $issues[] = '统计缓存超过 3 分钟未更新';
    if ($counts['cloud_cidrs'] <= 0) $issues[] = '云厂商 CIDR 规则为空';
    if (!file_exists(TOKEN_LIMIT_RATE_CONF) || !file_exists(TOKEN_LIMIT_CONF)) $issues[] = 'Token 拉取限制配置缺失';

    return [
        'state' => count($issues) === 0 ? 'healthy' : (count($issues) <= 2 ? 'attention' : 'degraded'),
        'label' => count($issues) === 0 ? '运行正常' : (count($issues) <= 2 ? '需要关注' : '服务异常'),
        'issues' => $issues,
        'stats_cache_age' => $statsAge,
        'cloud_rules_age' => $cloudAge,
        'log_size' => $logSize,
        'log_writable' => is_writable(LOG_FILE),
        'config_writable' => is_writable(dirname(SETTINGS_JSON)),
        'last_alert_check' => $lastAlertCheck,
        'retention_days' => LOG_RETENTION_DAYS,
        'alert_enabled' => !empty($settings['alert_enabled']),
        'token_limit_state_age' => $limitAge,
    ];
}

function guard_mechanisms(array $settings, array $counts, array $health): array {
    $protect = file_exists(PROTECT_CONF) ? (string)@file_get_contents(PROTECT_CONF) : '';
    $uaConf = file_exists(UA_CUSTOM_CONF) ? (string)@file_get_contents(UA_CUSTOM_CONF) : '';
    $tokenConf = file_exists(TOKEN_BLACKLIST_CONF) ? (string)@file_get_contents(TOKEN_BLACKLIST_CONF) : '';
    $ipPolicyReady = file_exists(BLACKLIST_CONF) && file_exists(WHITELIST_CONF);
    $items = [
        ['key' => 'gateway', 'title' => '订阅入口防护', 'state' => $protect !== '' ? 'active' : 'error', 'detail' => $protect !== '' ? 'Nginx 订阅路径规则已加载' : 'protect.conf 缺失'],
        ['key' => 'rate_limit', 'title' => '请求速率限制', 'state' => str_contains($protect, 'limit_req') ? 'active' : 'error', 'detail' => str_contains($protect, 'limit_req') ? '网关限速与 429 响应已启用' : '未检测到 limit_req'],
        ['key' => 'cloud', 'title' => '云服务商 / IDC CIDR', 'state' => $counts['cloud_cidrs'] > 0 ? 'active' : 'error', 'detail' => $counts['cloud_cidrs'] . ' 条 IPv4 CIDR'],
        ['key' => 'ip_policy', 'title' => 'IP 名单策略', 'state' => $ipPolicyReady ? 'active' : 'error', 'detail' => '白名单 ' . $counts['ip_whitelist'] . ' / 黑名单 ' . $counts['ip_blacklist']],
        ['key' => 'ua_policy', 'title' => 'UA 识别与策略', 'state' => str_contains($uaConf, 'is_custom_bad_ua') ? 'active' : 'error', 'detail' => '放行 ' . $counts['ua_whitelist'] . ' / 拦截 ' . $counts['ua_blacklist']],
        ['key' => 'token_policy', 'title' => 'Token 精确拦截', 'state' => str_contains($tokenConf, 'is_token_blacklisted') ? 'active' : 'error', 'detail' => $counts['token_blacklist'] . ' 个 Token 规则'],
        ['key' => 'pull_limit', 'title' => '自动执行规则', 'state' => empty($settings['guard_pull_limit_enabled']) ? 'paused' : (!empty($settings['guard_pull_limit_enforce']) ? 'active' : 'optional'), 'detail' => empty($settings['guard_pull_limit_enabled']) ? '用量统计已关闭' : (!empty($settings['guard_pull_limit_enforce']) ? '限速与自动暂停生效 · 当前暂停 ' . $counts['token_suspended'] . ' 个' : '仅统计 · 自动执行未开启')],
        ['key' => 'observation', 'title' => '风险预警规则', 'state' => !empty($settings['guard_observe_enabled']) ? 'active' : 'paused', 'detail' => !empty($settings['guard_observe_enabled']) ? '提前生成风险证据，不自动封禁' : '风险预警已关闭'],
        ['key' => 'stats_cache', 'title' => '后台统计预热', 'state' => ($health['stats_cache_age'] !== null && $health['stats_cache_age'] <= 180) ? 'active' : 'warn', 'detail' => $health['stats_cache_age'] === null ? '缓存不存在' : $health['stats_cache_age'] . ' 秒前更新'],
        ['key' => 'intel', 'title' => '多源 IP 情报', 'state' => 'active', 'detail' => $counts['ip_intel_cache'] . ' 个缓存画像'],
        ['key' => 'retention', 'title' => '日志保留与清理', 'state' => LOG_RETENTION_DAYS > 0 ? 'active' : 'paused', 'detail' => LOG_RETENTION_DAYS > 0 ? '保留 ' . LOG_RETENTION_DAYS . ' 天' : '自动清理已关闭'],
        ['key' => 'alerts', 'title' => '高危事件推送', 'state' => !empty($settings['alert_enabled']) ? 'active' : 'optional', 'detail' => !empty($settings['alert_enabled']) ? '每分钟检查并去重' : '未启用（可选）'],
    ];
    return $items;
}

function guard_recent_actions(string $secret): array {
    $actions = [];
    foreach (guard_read_json(BLACKLIST_JSON) as $row) {
        $actions[] = [
            'time' => (string)($row['added_at'] ?? ''),
            'type' => 'IP 黑名单',
            'subject' => (string)($row['ip'] ?? ''),
            'detail' => (string)($row['comment'] ?? '手动添加'),
            'status' => 'active',
        ];
    }
    foreach (guard_read_json(TOKEN_BLACKLIST_JSON) as $row) {
        $token = (string)($row['token'] ?? '');
        $actions[] = [
            'time' => (string)($row['added_at'] ?? ''),
            'type' => 'Token 黑名单',
            'subject' => $token !== '' ? guard_token_fingerprint($token, $secret) : '-',
            'detail' => (string)($row['comment'] ?? '手动添加'),
            'status' => 'active',
        ];
    }
    $limitState = guard_read_json(TOKEN_LIMIT_STATE_JSON);
    foreach ($limitState['entries'] ?? [] as $row) {
        $actions[] = [
            'time' => (string)($row['started_at'] ?? ''),
            'type' => 'Token 临时暂停',
            'subject' => (string)($row['fingerprint'] ?? ''),
            'detail' => '暂停至 ' . (string)($row['until'] ?? '-'),
            'status' => 'active',
        ];
    }
    foreach (array_slice($limitState['history'] ?? [], 0, 12) as $row) {
        $actions[] = [
            'time' => (string)($row['released_at'] ?? $row['started_at'] ?? ''),
            'type' => 'Token 暂停解除',
            'subject' => (string)($row['fingerprint'] ?? ''),
            'detail' => (string)($row['status'] ?? 'released'),
            'status' => 'released',
        ];
    }
    $history = guard_read_json(ALERT_HISTORY_JSON);
    foreach (array_slice($history['entries'] ?? [], 0, 20) as $row) {
        $actions[] = [
            'time' => (string)($row['time'] ?? ''),
            'type' => (string)($row['title'] ?? '告警检查'),
            'subject' => '',
            'detail' => (string)($row['summary'] ?? ''),
            'status' => (string)($row['status'] ?? 'sent'),
        ];
    }
    usort($actions, fn(array $a, array $b) => strcmp((string)$b['time'], (string)$a['time']));
    return array_slice($actions, 0, 12);
}

function attach_guard_reviews(array $payload): array {
    $reviews = guard_read_json(GUARD_REVIEW_JSON);
    $entries = is_array($reviews['entries'] ?? null) ? $reviews['entries'] : [];
    $summary = ['pending' => 0, 'watch' => 0, 'trusted' => 0, 'confirmed' => 0];
    foreach ($payload['findings'] ?? [] as &$finding) {
        $review = is_array($entries[$finding['key']] ?? null) ? $entries[$finding['key']] : [];
        $status = (string)($review['status'] ?? 'pending');
        if (!isset($summary[$status])) $status = 'pending';
        $finding['review'] = [
            'status' => $status,
            'note' => (string)($review['note'] ?? ''),
            'updated_at' => (string)($review['updated_at'] ?? ''),
        ];
        $summary[$status]++;
    }
    unset($finding);
    $payload['review_summary'] = $summary;
    return $payload;
}

function save_guard_review(array $body): void {
    $key = trim((string)($body['key'] ?? ''));
    $status = trim((string)($body['status'] ?? 'pending'));
    $note = safe_comment((string)($body['note'] ?? ''));
    if (!preg_match('/^[a-z0-9_]+:[a-f0-9]{24}$/', $key)) json_err('风险记录标识无效');
    if (!in_array($status, ['pending', 'watch', 'trusted', 'confirmed'], true)) json_err('复核状态无效');
    if (strlen($note) > 200) $note = substr($note, 0, 200);

    $reviews = guard_read_json(GUARD_REVIEW_JSON);
    $entries = is_array($reviews['entries'] ?? null) ? $reviews['entries'] : [];
    $entries[$key] = [
        'status' => $status,
        'note' => $note,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    uasort($entries, fn(array $a, array $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    $entries = array_slice($entries, 0, 500, true);
    if (!guard_write_json_atomic(GUARD_REVIEW_JSON, ['entries' => $entries])) json_err('保存复核状态失败');
    json_out(['ok' => true, 'review' => $entries[$key]]);
}
