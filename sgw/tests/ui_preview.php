<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    $empty = ['ok' => true, 'entries' => []];
    if ($path === '/api/token_investigation.php') {
        echo json_encode([
            'ok' => true,
            'profile' => [
                'fingerprint' => 'TKN-7BAD134E1B23C662', 'raw_token' => 'preview-token-fingerprint',
                'suspended' => true, 'suspended_until' => date('Y-m-d H:i:s', time() + 72000), 'blacklisted' => false,
                'summary' => [
                    'requests_24h' => 48, 'unique_ips' => 14, 'unique_asns' => 4, 'unique_locations' => 3,
                    'ua_families' => 3, 'first_seen' => date('Y-m-d H:i:s', time() - 72000),
                    'last_seen' => date('Y-m-d H:i:s'), 'score' => 88, 'risk' => '高风险',
                    'status_counts' => ['200' => 42, '429' => 6],
                ],
                'evidence' => ['24 小时出现 14 个独立 IP，超过规则 10 个', '来源覆盖 4 个 ASN', '10 分钟窗口内出现多个不同地区，建议人工复核'],
                'ips' => [
                    ['ip' => '198.51.100.24', 'count' => 20, 'last_seen' => date('Y-m-d H:i:s'), 'location' => '中国 / 浙江 / 杭州', 'asn' => 'AS4134', 'operator' => 'CHINANET-BACKBONE', 'network_type' => '普通运营商网络', 'high_risk' => false, 'intel_pending' => false],
                    ['ip' => '203.0.113.18', 'count' => 12, 'last_seen' => date('Y-m-d H:i:s', time() - 300), 'location' => '中国 / 广东 / 广州', 'asn' => 'AS9808', 'operator' => 'China Mobile', 'network_type' => '机房/托管', 'high_risk' => true, 'intel_pending' => false],
                ],
                'uas' => [
                    ['ua' => 'Clash.Meta/1.18', 'family' => 'Clash', 'count' => 26, 'last_seen' => date('Y-m-d H:i:s')],
                    ['ua' => 'Shadowrocket/2.2', 'family' => 'Shadowrocket', 'count' => 12, 'last_seen' => date('Y-m-d H:i:s', time() - 300)],
                    ['ua' => 'python-requests/2.32', 'family' => 'Python', 'count' => 10, 'last_seen' => date('Y-m-d H:i:s', time() - 600)],
                ],
                'events' => [
                    ['time' => date('Y-m-d H:i:s'), 'ip' => '198.51.100.24', 'status' => 200, 'ua_family' => 'Clash', 'location' => '中国 / 浙江 / 杭州'],
                    ['time' => date('Y-m-d H:i:s', time() - 180), 'ip' => '203.0.113.18', 'status' => 429, 'ua_family' => 'Python', 'location' => '中国 / 广东 / 广州'],
                ],
            ],
            'intel_queued' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/security.php') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $action = (string)($body['action'] ?? '');
            $keys = $action === 'batch_review'
                ? (is_array($body['keys'] ?? null) ? array_values(array_unique($body['keys'])) : [])
                : [(string)($body['key'] ?? '')];
            $status = (string)($body['status'] ?? 'pending');
            $note = (string)($body['note'] ?? '');
            $reviews = [];
            foreach ($keys as $key) {
                if ($key === '') continue;
                $reviews[$key] = ['status' => $status, 'note' => $note, 'updated_at' => date('Y-m-d H:i:s')];
            }
            if ($action === 'batch_review') {
                echo json_encode(['ok' => true, 'updated' => count($reviews), 'reviews' => $reviews], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => true, 'review' => reset($reviews)], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        $previewFindings = [];
        $previewSummary = ['pending' => 0, 'watch' => 0, 'trusted' => 0, 'confirmed' => 0];
        $findingKinds = ['daily_ip_volume', 'token_multi_ip', 'ip_multi_token', 'scanner', 'idc_provider_block'];
        for ($i = 1; $i <= 13; $i++) {
            $status = $i <= 7 ? 'pending' : ($i <= 9 ? 'watch' : ($i <= 12 ? 'trusted' : 'confirmed'));
            $kind = $findingKinds[($i - 1) % count($findingKinds)];
            $isDaily = $i === 1;
            $isToken = $kind === 'token_multi_ip';
            $previewSummary[$status]++;
            $isCloud = $kind === 'idc_provider_block';
            $previewFindings[] = [
                'key' => $kind . ':' . substr(hash('sha256', 'preview-' . $i), 0, 24),
                'kind' => $kind,
                'title' => $isCloud ? '云厂商 / IDC 自动拦截' : ($isDaily ? '今日单 IP 高频拉取' : ($isToken ? 'Token 跨多 IP 拉取' : ($kind === 'scanner' ? '脚本/扫描器拉取订阅' : 'IP 拉取多个 Token'))),
                'subject' => $isToken ? 'TKN-' . strtoupper(substr(hash('sha256', 'token-' . $i), 0, 16)) : '198.51.100.' . (20 + $i),
                'count' => $isDaily ? 233 : 30 + $i,
                'threshold' => $isDaily ? 100 : 30,
                'window' => $isDaily ? '今日' : ($i % 2 === 0 ? '最近日志窗口' : '1 分钟'),
                'source' => $isCloud ? 'Nginx 自动拦截' : ($isDaily ? '订阅路径统计' : '本地日志'),
                'last_seen' => date('Y-m-d H:i:s', time() - $i * 90),
                'risk' => $isCloud ? '极高危' : ($isDaily ? '极高危' : ($i <= 4 ? '高危' : '关注')),
                'score' => $isCloud ? 95 : ($isDaily ? 100 : max(62, 96 - $i * 2)),
                'reason' => $isCloud ? '请求来源命中已启用的 Amazon Web Services CIDR 策略，网关已自动返回 403。' : ($isDaily ? '该 IP 今日累计拉取订阅 233 次，已超过观察阈值 100 次。' : '请求行为达到观察阈值，等待管理员复核。'),
                'status_counts' => $isCloud ? ['403' => 7] : ($isDaily ? ['200' => 50, '403' => 70, '429' => 111, '444' => 2] : []),
                'token_count' => $isDaily ? 3 : 0,
                'location' => $isToken ? '' : 'Singapore / Central Singapore',
                'asn' => $isToken ? '' : 'AS46997',
                'operator' => $isToken ? '' : 'Black Mesa Corporation',
                'network_type' => $isToken ? '' : '机房/托管',
                'automatic_block' => $isCloud,
                'provider_id' => $isCloud ? 'aws' : '',
                'provider_name' => $isCloud ? 'Amazon Web Services' : '',
                'provider_asns' => $isCloud ? ['AS16509','AS14618'] : [],
                'provider_keywords' => $isCloud ? ['amazon web services','amazon technologies'] : [],
                'sample_paths' => $isCloud ? ['/api/v1/client/subscribe'] : [],
                'sample_uas' => $isCloud ? ['curl/8.0'] : [],
                'trigger_details' => $isCloud ? ['触发规则'=>'Amazon Web Services CIDR 自动拦截','网关动作'=>'HTTP 403 · Forbidden: Cloud IP','命中厂商'=>'Amazon Web Services','命中次数'=>'7'] : [],
                'review' => ['status' => $status, 'note' => ''],
            ];
        }
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
                'summary' => ['active_tokens' => 316, 'suspended_tokens' => 2, 'pending_violations' => 0, 'max_unique_ips_24h' => 14, 'max_per_minute' => 13, 'max_rule_unique_ips' => 9, 'max_rule_per_minute' => 8],
                'usage' => [
                    ['fingerprint' => 'TKN-7BAD134E1B23C662', 'unique_ips_24h' => 14, 'rule_unique_ips' => 0, 'requests_24h' => 48, 'peak_per_minute' => 13, 'rule_peak_per_minute' => 0, 'rule_since' => date('Y-m-d H:i:s', time() + 72000), 'last_seen' => date('Y-m-d H:i:s'), 'suspended' => true, 'would_suspend' => false, 'suspended_until' => date('Y-m-d H:i:s', time() + 72000)],
                    ['fingerprint' => 'TKN-E9FA747D378F15A0', 'unique_ips_24h' => 7, 'rule_unique_ips' => 7, 'requests_24h' => 18, 'peak_per_minute' => 4, 'rule_peak_per_minute' => 4, 'rule_since' => '', 'last_seen' => date('Y-m-d H:i:s'), 'suspended' => false, 'would_suspend' => false, 'suspended_until' => ''],
                ],
            ],
            'mechanisms' => [
                ['key' => 'rate_limit', 'state' => 'active', 'title' => '请求速率限制', 'detail' => '网关限速与 429 响应已启用'],
                ['key' => 'cloud', 'state' => 'active', 'title' => '云服务商 / IDC CIDR', 'detail' => '5,381 条 IPv4 CIDR'],
                ['key' => 'pull_limit', 'state' => 'active', 'title' => '自动执行规则', 'detail' => '限速与自动暂停生效 · 当前暂停 2 个'],
                ['key' => 'intel', 'state' => 'active', 'title' => '多源 IP 情报', 'detail' => '142 个缓存画像'],
            ],
            'review_summary' => $previewSummary,
            'findings' => $previewFindings,
            'rules' => [
                'guard_observe_enabled' => 1, 'guard_ip_daily_requests' => 100,
                'guard_ip_per_minute' => 30, 'guard_token_per_minute' => 8,
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
        $previewLogs = [];
        $samples = [
            ['198.51.100.24', 200, 'Clash.Meta/1.18'],
            ['198.51.100.24', 200, 'Clash.Meta/1.18'],
            ['198.51.100.24', 403, 'python-requests/2.32'],
            ['198.51.100.24', 429, 'Clash.Meta/1.18'],
            ['203.0.113.18', 200, 'Shadowrocket/2.2'],
            ['203.0.113.18', 444, 'curl/8.7'],
            ['203.0.113.18', 404, 'Mozilla/5.0'],
            ['192.0.2.66', 200, 'sing-box/1.11'],
        ];
        foreach ($samples as $i => [$ip, $status, $ua]) {
            $previewLogs[] = [
                'time' => date('Y-m-d H:i:s', time() - $i * 37), 'ip' => $ip, 'status' => $status,
                'token' => 'preview-token-' . (($i % 3) + 1),
                'request' => 'GET /api/v1/client/subscribe?token=preview-' . (($i % 3) + 1) . ' HTTP/2',
                'ua' => $ua,
            ];
        }
        echo json_encode(['ok' => true, 'logs' => $previewLogs], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/ip_intel.php') {
        $ips = preg_split('/[\s,]+/', (string)($_GET['ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $entries = [];
        foreach (array_values(array_unique($ips)) as $i => $ip) {
            $entries[] = [
                'ip' => $ip, 'status' => 'ready', 'fresh' => true,
                'location' => $i % 2 ? '中国 / 广东 / 广州' : '中国 / 浙江 / 杭州',
                'operator' => $i % 2 ? 'China Mobile Communications Group' : 'CHINANET-BACKBONE',
                'asn' => $i % 2 ? 'AS9808' : 'AS4134',
                'route_prefix' => '', 'network_type' => $i === 1 ? '机房/托管' : '普通运营商网络',
                'risk_level' => $i === 1 ? 'high' : 'low', 'risk_label' => $i === 1 ? '高风险' : '低风险',
                'risk_reason' => $i === 1 ? '机房/托管' : '未发现代理或机房信号',
                'source' => 'ip-api、ipwho.is、GeoJS、RIPEstat', 'source_count' => 4,
                'confidence' => '高', 'consensus' => '国家3/3｜ASN4/4',
            ];
        }
        echo json_encode(['ok' => true, 'entries' => $entries, 'queued' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/ai.php') {
        $providers = [
            'openai'=>['name'=>'OpenAI','adapter'=>'openai_compatible','base_url'=>'https://api.openai.com/v1','model'=>'gpt-4.1-mini'],
            'deepseek'=>['name'=>'DeepSeek','adapter'=>'openai_compatible','base_url'=>'https://api.deepseek.com','model'=>'deepseek-v4-flash'],
            'qwen'=>['name'=>'通义千问','adapter'=>'openai_compatible','base_url'=>'https://dashscope.aliyuncs.com/compatible-mode/v1','model'=>'qwen-plus'],
            'kimi'=>['name'=>'Kimi','adapter'=>'openai_compatible','base_url'=>'https://api.moonshot.cn/v1','model'=>'kimi-k3'],
            'anthropic'=>['name'=>'Anthropic Claude','adapter'=>'anthropic','base_url'=>'https://api.anthropic.com','model'=>'claude-haiku-4-5-20251001'],
            'gemini'=>['name'=>'Google Gemini','adapter'=>'gemini','base_url'=>'https://generativelanguage.googleapis.com','model'=>'gemini-2.5-flash'],
            'custom'=>['name'=>'自定义兼容接口','adapter'=>'openai_compatible','base_url'=>'','model'=>''],
        ];
        echo json_encode([
            'ok'=>true,
            'settings'=>['enabled'=>1,'auto_analyze'=>1,'auto_interval_minutes'=>30,'provider'=>'deepseek','adapter'=>'openai_compatible','base_url'=>'https://api.deepseek.com','model'=>'deepseek-v4-flash','include_ip'=>0,'include_ua'=>0,'include_path'=>1,'max_findings'=>10,'has_api_key'=>true,'providers'=>$providers],
            'analysis'=>['latest'=>[
                'id'=>'AIR-PREVIEW','generated_at'=>date('Y-m-d H:i:s'),'provider'=>'deepseek','provider_name'=>'DeepSeek','model'=>'deepseek-v4-flash','scope'=>'queue','finding_count'=>5,'advisory_only'=>true,
                'decision'=>['risk_level'=>'high','confidence'=>86,'verdict'=>'存在高频共享与自动化拉取风险','summary'=>'多项独立证据同时指向异常拉取，但运营商 NAT 和客户端自动更新仍可能造成误报，建议先核对时间窗口再处置。','evidence'=>['单一来源在今日窗口内累计请求 233 次','同一 Token 指纹在多个来源 IP 出现','部分请求命中脚本客户端特征'],'false_positive_factors'=>['移动网络或公司出口 NAT 会共享公网 IP','客户端故障可能造成短时重复拉取'],'recommendations'=>['核对最近 24 小时来源分布和 UA 变化','先观察或临时限速，再决定是否封禁'],'proposed_actions'=>['标记为持续观察','人工确认后临时暂停 Token'],'needs_human_review'=>true,'execution'=>'none'],
            ],'history'=>[],'last_attempt_ts'=>time(),'last_error'=>''],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($path === '/api/stats.php') {
        echo json_encode([
            'ok' => true, 'scan_limit' => 30000,
            'pull_ips' => [[
                'ip' => '198.51.100.21', 'total' => 233, 's200' => 50, 's403' => 70,
                's429' => 111, 's444' => 2, 'token_count' => 3, 'last_time' => date('Y-m-d H:i:s'),
            ]],
            'susp_tokens' => [[
                'token' => 'preview-token-fingerprint', 'ip_count' => 7,
            ]],
            'susp_ips' => [[
                'ip' => '198.51.100.24', 'token_count' => 6, 'request_count' => 42,
                'risk' => '高危', 'score' => 92, 'paths' => ['/api/v1/client/subscribe'],
                'uas' => ['python-requests/2.32'], 'tokens' => ['preview-token'],
                'reasons' => ['同一 IP 在统计窗口内拉取多个 Token'],
            ]],
            'scanner_reports' => [[
                'ip' => '203.0.113.18', 'token' => 'preview-token', 'risk' => '高危', 'score' => 90,
                'location' => '中国 / 浙江 / 杭州', 'asn' => 'AS4134 CHINANET-BACKBONE',
                'network_type' => '普通运营商网络', 'query_source' => '本地预览',
                'path' => '/api/v1/client/subscribe', 'ua' => 'python-requests/2.32',
                'reason' => 'automation_client', 'time' => date('Y-m-d H:i:s'),
            ]],
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
    if ($path === '/api/blacklist.php') {
        if (isset($_GET['cloud_cidrs'])) {
            echo json_encode(['ok' => true, 'cidrs' => []]);
            exit;
        }
        $providers = [
            ['id'=>'aliyun','name'=>'阿里云','asns'=>['AS45102','AS37963','AS134963'],'keywords'=>['阿里云','alibabacloud','aliyun'],'default_enabled'=>true,'enabled'=>true,'active'=>true,'count'=>227,'active_count'=>227,'available'=>true],
            ['id'=>'aws','name'=>'Amazon Web Services','asns'=>['AS16509','AS14618'],'keywords'=>['amazon web services','amazon technologies'],'default_enabled'=>true,'enabled'=>true,'active'=>true,'count'=>7878,'active_count'=>7878,'available'=>true],
            ['id'=>'hetzner','name'=>'Hetzner','asns'=>['AS24940','AS213230'],'keywords'=>['hetzner online','hetzner'],'default_enabled'=>false,'enabled'=>false,'active'=>false,'count'=>420,'active_count'=>0,'available'=>true],
            ['id'=>'inspur_cloud','name'=>'浪潮云','asns'=>[],'keywords'=>['浪潮云','inspur cloud'],'default_enabled'=>false,'enabled'=>false,'active'=>false,'count'=>0,'active_count'=>0,'available'=>false],
        ];
        echo json_encode(['ok'=>true,'entries'=>[],'idc_summary'=>$providers,'cloud_provider_status'=>['status'=>'ready','message'=>'云厂商规则已应用，共 8105 条 CIDR']], JSON_UNESCAPED_UNICODE);
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
