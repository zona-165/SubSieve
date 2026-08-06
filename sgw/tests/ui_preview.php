<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    $empty = ['ok' => true, 'entries' => []];
    if ($path === '/api/security.php') {
        echo json_encode([
            'ok' => true,
            'mode' => 'observe',
            'generated_at' => date('Y-m-d H:i:s'),
            'scope' => '只分析订阅网关日志，不连接机场业务数据库。',
            'health' => [
                'state' => 'healthy', 'label' => '网关运行正常', 'issues' => [],
                'stats_cache_age' => 12, 'token_limit_state_age' => 8, 'cloud_rules_age' => 3600,
                'log_size' => 5242880, 'log_writable' => true, 'retention_days' => 14,
                'alert_enabled' => false, 'last_alert_check' => '',
            ],
            'metrics' => [
                'today_requests' => 1284, 'today_success' => 1196, 'today_ips' => 142,
                'observed_lines' => 30000, 'today_tokens' => 316, 'risk_findings' => 3,
                'today_blocked' => 88,
            ],
            'policy_counts' => ['ip_blacklist' => 24, 'token_blacklist' => 3],
            'pull_limits' => [
                'settings' => ['enabled' => true, 'enforce' => true, 'max_ips_24h' => 10, 'max_per_minute' => 10, 'suspend_hours' => 24],
                'summary' => ['active_tokens' => 316, 'suspended_tokens' => 2, 'pending_violations' => 0, 'max_unique_ips_24h' => 14, 'max_per_minute' => 13],
                'usage' => [
                    ['fingerprint' => 'TKN-7BAD134E1B23C662', 'unique_ips_24h' => 14, 'requests_24h' => 48, 'peak_per_minute' => 13, 'last_seen' => date('Y-m-d H:i:s'), 'suspended' => true, 'would_suspend' => false, 'suspended_until' => date('Y-m-d H:i:s', time() + 72000)],
                    ['fingerprint' => 'TKN-E9FA747D378F15A0', 'unique_ips_24h' => 7, 'requests_24h' => 18, 'peak_per_minute' => 4, 'last_seen' => date('Y-m-d H:i:s'), 'suspended' => false, 'would_suspend' => false, 'suspended_until' => ''],
                ],
            ],
            'mechanisms' => [
                ['state' => 'active', 'title' => '请求速率限制', 'detail' => '网关限速与 429 响应已启用'],
                ['state' => 'active', 'title' => '云服务商 / IDC CIDR', 'detail' => '5,381 条 IPv4 CIDR'],
                ['state' => 'active', 'title' => 'Token 拉取限制', 'detail' => '自动暂停生效 · 当前 2 个'],
                ['state' => 'active', 'title' => '多源 IP 情报', 'detail' => '142 个缓存画像'],
            ],
            'review_summary' => ['pending' => 1, 'watch' => 1, 'trusted' => 1, 'confirmed' => 0],
            'findings' => [[
                'key' => 'preview:risk', 'title' => '单 IP 高频拉取', 'subject' => '198.51.100.24',
                'count' => 36, 'threshold' => 30, 'window' => '1 分钟', 'source' => '本地日志',
                'last_seen' => date('Y-m-d H:i:s'), 'risk' => '关注', 'score' => 72,
                'reason' => '短时间请求频率达到观察阈值', 'review' => ['status' => 'pending', 'note' => ''],
            ]],
            'rules' => [
                'guard_observe_enabled' => 1, 'guard_ip_per_minute' => 30, 'guard_token_per_minute' => 20,
                'guard_token_hour_ips' => 8, 'guard_ip_hour_tokens' => 20, 'guard_ip_404_5m' => 40,
                'guard_scan_lines' => 30000,
            ],
            'recent_actions' => [[
                'time' => date('H:i:s'), 'type' => 'Token 自动暂停', 'subject' => 'TKN-7BAD134E1B23C662', 'detail' => '超过每分钟拉取上限',
            ]],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/logs.php') {
        echo json_encode(['ok' => true, 'logs' => [[
            'time' => date('Y-m-d H:i:s'), 'ip' => '198.51.100.24', 'status' => 200,
            'token' => 'preview-token-fingerprint', 'request' => 'GET /api/v1/client/subscribe?token=preview HTTP/2',
            'ua' => 'Clash.Meta/1.18',
        ]]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/stats.php') {
        echo json_encode([
            'ok' => true, 'scan_limit' => 30000,
            'top_ips' => [['ip' => '198.51.100.24', 'total' => 36, 's200' => 34, 's403' => 1, 's429' => 1, 's444' => 0]],
            'top_tokens' => [['token_full' => 'TKN-PREVIEW12345678', 'count' => 22, 'last_time' => date('H:i:s')]],
            'susp_tokens' => [], 'susp_ips' => [], 'scanner_reports' => [], 'user_profiles' => [], 'bad_uas' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/settings.php') {
        echo json_encode([
            'ok' => true,
            'settings' => [
                'site_title' => 'SubSieve', 'page_title' => 'SubSieve Admin', 'admin_user' => 'admin',
                'upstream_url' => 'https://panel.example.com', 'subscribe_path' => '/api/v1/client/subscribe',
                'gateway_port' => 443,
            ],
            'cert' => ['exists' => true, 'subject' => 'preview.example.com', 'issuer' => 'Preview CA', 'valid_from' => '2026-01-01', 'valid_to' => '2027-01-01', 'days_left' => 146],
            'stats_cache' => ['exists' => true, 'fresh' => true, 'age_seconds' => 12, 'mtime' => date('Y-m-d H:i:s'), 'size_text' => '362 KB', 'scan_limit' => 30000],
            'alert_history' => ['entries' => [], 'total' => 0],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/blacklist.php' && isset($_GET['cloud_cidrs'])) {
        echo json_encode(['ok' => true, 'cidrs' => []]);
        exit;
    }
    echo json_encode($empty, JSON_UNESCAPED_UNICODE);
    exit;
}

define('SETTINGS_JSON', '/tmp/subsieve-preview-settings.json');
define('PROTECT_CONF', '/tmp/subsieve-preview-protect.conf');
define('PAGE_TITLE', 'SubSieve Preview');
define('SITE_TITLE', 'SubSieve');
define('ADMIN_SECRET_PATH', '');
define('ADMIN_USER', 'admin');
define('GATEWAY_PORT', 443);
require dirname(__DIR__) . '/admin/src/views/dashboard.php';
