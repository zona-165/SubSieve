<?php
if (PHP_SAPI === 'cli') {
    require_once dirname(__DIR__) . '/config.php';
} else {
    require_once __DIR__ . '/_auth.php';
}
require_once dirname(__DIR__) . '/lib/ip_intel_queue.php';
require_once dirname(__DIR__) . '/lib/guard.php';

if (!defined('STATS_CACHE_JSON')) {
    define('STATS_CACHE_JSON', dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json');
}

$cacheTtl = 120;
$statsSettings = guard_read_json(SETTINGS_JSON);
$statsRules = guard_normalize_settings($statsSettings);
$maxScanLines = (int)$statsRules['guard_scan_lines'];
$statsSubscribePath = trim((string)($statsSettings['subscribe_path'] ?? '/api/v1/client/subscribe'));
if ($statsSubscribePath === '') $statsSubscribePath = '/api/v1/client/subscribe';
$forceRefresh = PHP_SAPI === 'cli' || (isset($_GET['refresh']) && $_GET['refresh'] === '1');
if (!$forceRefresh && file_exists(STATS_CACHE_JSON)) {
    $cacheRaw = @file_get_contents(STATS_CACHE_JSON);
    $cache = $cacheRaw ? json_decode($cacheRaw, true) : null;
    if (is_array($cache) && isset($cache['ts'], $cache['data']) && time() - (int)$cache['ts'] <= $cacheTtl) {
        $data = is_array($cache['data']) ? $cache['data'] : [];
        $data['cached'] = true;
        json_out($data);
    }
}

$today  = date('d/M/Y');
$pullIps = [];  // ip => subscription pull counts (today only)
$scannerReports = []; // latest suspicious subscription pulls

// 全量日志用于可疑分析
$suspTokenIps = [];  // token => {ip => true}
$suspIpTokens = [];  // ip    => {token => true}
$suspTokenLast = [];
$suspIpLast = [];

// 读取Token黑名单（用于从统计中排除）
$tokenBlacklist = [];
if (file_exists(TOKEN_BLACKLIST_JSON)) {
    $tbData = json_decode(file_get_contents(TOKEN_BLACKLIST_JSON), true);
    if (is_array($tbData)) {
        foreach ($tbData as $e) {
            if (!empty($e['token'])) $tokenBlacklist[$e['token']] = true;
        }
    }
}

// 读取白名单（用于排除）
$whitelistIps = [];
if (file_exists(WHITELIST_IPS)) {
    foreach (file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $wl) {
        $wl = trim($wl);
        if ($wl === '' || str_starts_with($wl, '#')) continue;
        $ip = strtok($wl, " \t#");
        if ($ip) $whitelistIps[$ip] = true;
    }
}

if (file_exists(LOG_FILE)) {
    foreach (tail_log_lines(LOG_FILE, $maxScanLines) as $line) {
        $line = rtrim($line);
        if ($line === '') continue;

        $pat = '/^(\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)"(?: "reason=([^"]*)" "provider=([^"]*)")?$/';
        if (!preg_match($pat, $line, $m)) continue;

        [, $ip, $time, $request, $status, , $ua] = $m;
        $status = (int)$status;
        $requestPath = extract_request_path($request);
        $isSubscription = guard_path_matches($requestPath, $statsSubscribePath);
        // ── 今日统计 ──────────────────────────────────────────
        if (str_contains($line, "[$today:")) {
            if ($isSubscription) {
                if (!isset($pullIps[$ip])) {
                    $pullIps[$ip] = ['total'=>0,'s200'=>0,'s403'=>0,'s429'=>0,'s444'=>0,'tokens'=>[],'last_time'=>''];
                }
                $pullIps[$ip]['total']++;
                if ($status === 200) $pullIps[$ip]['s200']++;
                elseif ($status === 403) $pullIps[$ip]['s403']++;
                elseif ($status === 429) $pullIps[$ip]['s429']++;
                elseif ($status === 444) $pullIps[$ip]['s444']++;
                $pullIps[$ip]['last_time'] = format_log_time($time);
                if (preg_match('/[?&]token=([^&\s]+)/i', $request, $pullTokenMatch)) {
                    $pullIps[$ip]['tokens'][rawurldecode($pullTokenMatch[1])] = true;
                }
            }
        }

        // ── 近段日志可疑分析（200 状态订阅请求，排除白名单IP和Token黑名单）──
        if ($status === 200
            && $isSubscription
            && !isset($whitelistIps[$ip])
            && preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)
        ) {
            $tok = rawurldecode($tm[1]);
            if (!isset($tokenBlacklist[$tok])) {
                $suspTokenIps[$tok][$ip] = true;
                $suspIpTokens[$ip][$tok]  = true;
                $formattedTime = format_log_time($time);
                $suspTokenLast[$tok] = $formattedTime;
                $suspIpLast[$ip] = $formattedTime;
                $scannerReason = scanner_reason($ua);
                if ($scannerReason !== '') {
                    $key = $ip . '|' . $tok;
                    $path = extract_request_path($request);
                    $scannerReports[$key] = [
                        'ip' => $ip,
                        'token' => $tok,
                        'path' => $path,
                        'ua' => $ua,
                        'reason' => $scannerReason,
                        'time' => format_log_time($time),
                        'risk' => '高危',
                        'score' => 90,
                        'email' => '',
                        'user_id' => '',
                        'location' => '未查询',
                        'asn' => '未查询',
                        'query_source' => '本地日志',
                    ];
                }
            }
        }
    }
}

uasort($pullIps, fn($a,$b) => $b['total'] - $a['total']);
$pullIpList = [];
foreach (array_slice($pullIps, 0, 500, true) as $ip => $v) {
    $v['token_count'] = count($v['tokens'] ?? []);
    unset($v['tokens']);
    $pullIpList[] = array_merge(['ip' => $ip], $v);
}
if (PHP_SAPI === 'cli') warm_ip_intel_candidates(array_column($pullIpList, 'ip'), 5);

// 可疑 Token（日志周期内被多个不同 IP 拉取）
$SUSP_TOKEN_THRESHOLD = (int)$statsRules['guard_token_hour_ips'];
$suspTokenList = [];
foreach ($suspTokenIps as $tok => $ipSet) {
    $cnt = count($ipSet);
    if ($cnt >= $SUSP_TOKEN_THRESHOLD) {
        $suspTokenList[] = ['token' => $tok, 'ip_count' => $cnt, 'ips' => array_keys($ipSet), 'last_time' => (string)($suspTokenLast[$tok] ?? '')];
    }
}
usort($suspTokenList, fn($a,$b) => $b['ip_count'] - $a['ip_count']);

// 可疑 IP（日志周期内拉取多个不同 Token）
$SUSP_IP_THRESHOLD = (int)$statsRules['guard_ip_hour_tokens'];
$suspIpList = [];
foreach ($suspIpTokens as $ip => $tokSet) {
    $cnt = count($tokSet);
    if ($cnt >= $SUSP_IP_THRESHOLD) {
        $score = min(100, 45 + ($cnt * 12));
        $risk = $score >= 90 ? '极高危' : ($score >= 75 ? '高危' : '可疑');
        $suspIpList[] = [
            'ip' => $ip,
            'token_count' => $cnt,
            'request_count' => 0,
            'max_per_second' => 0,
            'score' => $score,
            'risk' => $risk,
            'tokens' => array_slice(array_keys($tokSet), 0, 8),
            'paths' => [],
            'uas' => [],
            'last_time' => (string)($suspIpLast[$ip] ?? ''),
            'reasons' => [
                "触发 IP {$ip} 在日志周期内拉取了 {$cnt} 个不同 Token，超过阈值 {$SUSP_IP_THRESHOLD}。",
                '该判断来自成功订阅请求的 Token 去重统计，建议结合日志页按 IP 复核请求路径和 UA。',
            ],
        ];
    }
}
usort($suspIpList, fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($b['token_count'] <=> $a['token_count']));

$scannerList = array_values($scannerReports);
usort($scannerList, fn($a,$b) => strcmp($b['time'], $a['time']));
$scannerList = array_slice($scannerList, 0, 100);
$scannerList = enrich_scanner_reports($scannerList);

$payload = [
    'ok'          => true,
    'pull_ips'    => $pullIpList,
    'susp_tokens' => $suspTokenList,
    'susp_ips'    => $suspIpList,
    'scanner_reports' => $scannerList,
    'cached' => false,
    'scan_limit' => $maxScanLines,
];

write_stats_cache($payload);
json_out($payload);

function write_stats_cache(array $payload): void {
    $tmp = STATS_CACHE_JSON . '.tmp.' . getmypid();
    $json = json_encode(['ts' => time(), 'data' => $payload], JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, STATS_CACHE_JSON);
    }
    @unlink($tmp);
}

function tail_log_lines(string $file, int $maxLines): iterable {
    try {
        $obj = new SplFileObject($file, 'r');
        $obj->seek(PHP_INT_MAX);
        $last = $obj->key();
        $start = max(0, $last - $maxLines + 1);
        $obj->seek($start);
        while (!$obj->eof()) {
            $line = $obj->fgets();
            if ($line !== false) yield $line;
        }
    } catch (Throwable $e) {
        $handle = @fopen($file, 'r');
        if (!$handle) return;
        $buf = [];
        while (($line = fgets($handle)) !== false) {
            $buf[] = $line;
            if (count($buf) > $maxLines) array_shift($buf);
        }
        fclose($handle);
        foreach ($buf as $line) yield $line;
    }
}

function scanner_reason(string $ua): string {
    $u = strtolower(trim($ua));
    if ($u === '' || $u === '-') return 'empty_or_invalid_user_agent';
    if ($u === 'clash') return 'generic_proxy_client_user_agent';
    $patterns = [
        '/^curl(?:\/|$)/' => 'script_user_agent',
        '/^wget(?:\/|$)/' => 'script_user_agent',
        '/^python(?:[\/\s-]|$)/' => 'script_user_agent',
        '/^go-http-client(?:\/|$)/' => 'script_user_agent',
        '/^java(?:\/|$)/' => 'script_user_agent',
        '/^libcurl(?:\/|$)/' => 'script_user_agent',
        '/^node-fetch(?:\/|$)/' => 'script_user_agent',
        '/^axios(?:\/|$)/' => 'script_user_agent',
        '/^postmanruntime(?:\/|$)/' => 'tool_user_agent',
    ];
    foreach ($patterns as $pattern => $reason) {
        if (preg_match($pattern, $u)) return $reason;
    }
    return '';
}

function extract_request_path(string $request): string {
    $parts = explode(' ', trim($request));
    $target = $parts[1] ?? $request;
    $path = parse_url($target, PHP_URL_PATH);
    return $path ?: $target;
}

function format_log_time(string $time): string {
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $time);
    return $dt ? $dt->format('Y/m/d H:i:s') : preg_replace('/ \+\d+$/', '', $time);
}

function enrich_scanner_reports(array $reports): array {
    $cache = read_ip_intel_cache();
    $dirty = false;
    $lookups = 0;
    $allowRemoteLookup = PHP_SAPI === 'cli';
    foreach ($reports as &$report) {
        $ip = $report['ip'] ?? '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
        $cached = isset($cache[$ip]) && is_ip_intel_cache_fresh($cache[$ip]);
        if (!$cached && (!$allowRemoteLookup || $lookups >= 5)) continue;
        if (!$cached) $lookups++;
        $intel = get_ip_intel($ip, $cache, $dirty);
        if (!$intel) continue;
        $report['location'] = $intel['location'] ?? '未查询';
        $report['asn'] = $intel['asn'] ?? '未查询';
        $report['query_source'] = $intel['source'] ?? '多源查询';
        $report['intel_sources'] = is_array($intel['sources'] ?? null) ? $intel['sources'] : [];
        $report['intel_source_count'] = (int)($intel['source_count'] ?? 0);
        $report['intel_confidence'] = $intel['confidence'] ?? '未评估';
        $report['intel_consensus'] = $intel['consensus'] ?? '';
        $report['route_prefix'] = $intel['route_prefix'] ?? '';
        $report['network_type'] = $intel['network_type'] ?? '未知网络';
        $report['intel_tags'] = $intel['tags'] ?? [];
        if (!empty($intel['is_proxy']) || !empty($intel['is_hosting']) || !empty($intel['is_tor'])) {
            $report['score'] = max((int)($report['score'] ?? 90), 95);
            $report['risk'] = '极高危';
        }
    }
    unset($report);
    if ($dirty) write_ip_intel_cache($cache);
    return $reports;
}

function read_ip_intel_cache(): array {
    if (!file_exists(IP_INTEL_CACHE_JSON)) return [];
    $data = json_decode((string)file_get_contents(IP_INTEL_CACHE_JSON), true);
    return is_array($data) ? $data : [];
}

function write_ip_intel_cache(array $cache): void {
    $tmp = IP_INTEL_CACHE_JSON . '.tmp.' . getmypid();
    $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, IP_INTEL_CACHE_JSON);
    }
    @unlink($tmp);
}

function warm_ip_intel_candidates(array $fallbackIps, int $limit): void {
    $limit = max(1, min(10, $limit));
    $cache = read_ip_intel_cache();
    $candidates = ip_intel_take($limit);
    foreach ($fallbackIps as $ip) {
        if (count($candidates) >= $limit) break;
        if (!ip_intel_is_public((string)$ip) || in_array($ip, $candidates, true)) continue;
        if (!isset($cache[$ip]) || !is_ip_intel_cache_fresh($cache[$ip])) $candidates[] = $ip;
    }
    if (!$candidates) return;
    $dirty = false;
    foreach ($candidates as $ip) {
        if (isset($cache[$ip]) && is_ip_intel_cache_fresh($cache[$ip])) continue;
        get_ip_intel($ip, $cache, $dirty);
    }
    if ($dirty) write_ip_intel_cache($cache);
}

function get_ip_intel(string $ip, array &$cache, bool &$dirty): ?array {
    $now = time();
    if (isset($cache[$ip]) && is_ip_intel_cache_fresh($cache[$ip])) {
        return $cache[$ip]['data'] ?? null;
    }
    $intel = query_multi_source_ip($ip, $cache, $dirty);
    if (!$intel) {
        $intel = [
            'location' => '查询失败',
            'asn' => '查询失败',
            'asn_number' => '',
            'operator' => '未知运营商',
            'source' => '多源查询',
            'sources' => [],
            'source_count' => 0,
            'confidence' => '无',
            'consensus' => '',
            'route_prefix' => '',
            'network_type' => '未知网络',
            'tags' => ['情报查询失败'],
            'is_proxy' => false,
            'is_hosting' => false,
            'is_tor' => false,
            'intel_version' => 2,
            'query_failed' => true,
        ];
    }
    $cache[$ip] = ['ts' => $now, 'data' => $intel];
    $dirty = true;
    return $intel;
}

function query_multi_source_ip(string $ip, array &$cache, bool &$dirty): ?array {
    $results = [];

    // ipwho.is 免费接口按调用方 IP 计日额度，预留余量避免触发硬限制。
    if (consume_ip_intel_budget($cache, 'ipwho.is', 900, $dirty)) {
        $result = query_ipwho_source($ip);
        if ($result) $results[] = $result;
    }

    foreach ([query_ip_api_source($ip), query_geojs_source($ip), query_ripe_source($ip)] as $result) {
        if ($result) $results[] = $result;
    }

    return $results ? merge_ip_intel_sources($results) : null;
}

function consume_ip_intel_budget(array &$cache, string $provider, int $dailyLimit, bool &$dirty): bool {
    $day = gmdate('Y-m-d');
    $usage = $cache['_meta']['provider_usage'][$provider] ?? [];
    $count = ($usage['day'] ?? '') === $day ? (int)($usage['count'] ?? 0) : 0;
    if ($count >= $dailyLimit) return false;
    $cache['_meta']['provider_usage'][$provider] = ['day' => $day, 'count' => $count + 1];
    $dirty = true;
    return true;
}

function fetch_ip_intel_json(string $url, float $timeout = 1.2): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'max_redirects' => 2,
            'header' => "User-Agent: SubSieve-IP-Intel/2.0\r\nAccept: application/json\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function query_ip_api_source(string $ip): ?array {
    $fields = 'status,message,country,countryCode,regionName,city,isp,org,as,asname,proxy,hosting,mobile';
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=' . rawurlencode($fields) . '&lang=zh-CN';
    $data = fetch_ip_intel_json($url);
    if (!$data || ($data['status'] ?? '') !== 'success') return null;
    return [
        'provider' => 'ip-api',
        'country' => trim((string)($data['country'] ?? '')),
        'country_code' => strtoupper(trim((string)($data['countryCode'] ?? ''))),
        'region' => trim((string)($data['regionName'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'asn_number' => normalize_asn_number($data['as'] ?? ''),
        'organization' => trim((string)($data['org'] ?? $data['asname'] ?? '')),
        'isp' => trim((string)($data['isp'] ?? '')),
        'proxy' => (bool)($data['proxy'] ?? false),
        'vpn' => null,
        'tor' => null,
        'hosting' => (bool)($data['hosting'] ?? false),
        'mobile' => (bool)($data['mobile'] ?? false),
        'prefix' => '',
    ];
}

function query_ipwho_source(string $ip): ?array {
    $data = fetch_ip_intel_json('https://ipwho.is/' . rawurlencode($ip));
    if (!$data || ($data['success'] ?? false) !== true) return null;
    $connection = is_array($data['connection'] ?? null) ? $data['connection'] : [];
    $security = is_array($data['security'] ?? null) ? $data['security'] : [];
    return [
        'provider' => 'ipwho.is',
        'country' => trim((string)($data['country'] ?? '')),
        'country_code' => strtoupper(trim((string)($data['country_code'] ?? ''))),
        'region' => trim((string)($data['region'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'asn_number' => normalize_asn_number($connection['asn'] ?? ''),
        'organization' => trim((string)($connection['org'] ?? '')),
        'isp' => trim((string)($connection['isp'] ?? '')),
        'proxy' => nullable_ip_intel_bool($security, 'proxy'),
        'vpn' => nullable_ip_intel_bool($security, 'vpn'),
        'tor' => nullable_ip_intel_bool($security, 'tor'),
        'hosting' => nullable_ip_intel_bool($security, 'hosting'),
        'mobile' => nullable_ip_intel_bool($security, 'mobile'),
        'prefix' => '',
    ];
}

function query_geojs_source(string $ip): ?array {
    $data = fetch_ip_intel_json('https://get.geojs.io/v1/ip/geo/' . rawurlencode($ip) . '.json');
    if (!$data) return null;
    if (array_is_list($data) && isset($data[0]) && is_array($data[0])) $data = $data[0];
    if (isset($data['error']) || !isset($data['ip'])) return null;
    $asn = normalize_asn_number($data['asn'] ?? '');
    if ($asn === '64512') $asn = '';
    return [
        'provider' => 'GeoJS',
        'country' => trim((string)($data['country'] ?? '')),
        'country_code' => strtoupper(trim((string)($data['country_code'] ?? ''))),
        'region' => trim((string)($data['region'] ?? '')),
        'city' => trim((string)($data['city'] ?? '')),
        'asn_number' => $asn,
        'organization' => trim((string)($data['organization_name'] ?? '')),
        'isp' => '',
        'proxy' => null,
        'vpn' => null,
        'tor' => null,
        'hosting' => null,
        'mobile' => null,
        'prefix' => '',
        'accuracy' => isset($data['accuracy']) ? (int)$data['accuracy'] : null,
    ];
}

function query_ripe_source(string $ip): ?array {
    $url = 'https://stat.ripe.net/data/network-info/data.json?resource=' . rawurlencode($ip);
    $data = fetch_ip_intel_json($url);
    $network = is_array($data['data'] ?? null) ? $data['data'] : [];
    $asns = is_array($network['asns'] ?? null) ? $network['asns'] : [];
    $normalizedAsns = unique_ip_intel_values(array_map('normalize_asn_number', $asns));
    $asn = $normalizedAsns[0] ?? '';
    $prefix = trim((string)($network['prefix'] ?? ''));
    if (!$asn && !$prefix) return null;
    return [
        'provider' => 'RIPEstat',
        'country' => '',
        'country_code' => '',
        'region' => '',
        'city' => '',
        'asn_number' => $asn,
        'asn_numbers' => $normalizedAsns,
        'organization' => '',
        'isp' => '',
        'proxy' => null,
        'vpn' => null,
        'tor' => null,
        'hosting' => null,
        'mobile' => null,
        'prefix' => $prefix,
    ];
}

function nullable_ip_intel_bool(array $data, string $key): ?bool {
    return array_key_exists($key, $data) ? (bool)$data[$key] : null;
}

function normalize_asn_number($value): string {
    return preg_match('/(?:AS)?\s*(\d+)/i', trim((string)$value), $match) ? $match[1] : '';
}

function ip_intel_consensus(array $sources, string $field, bool $uppercase = false): array {
    $counts = [];
    $values = [];
    foreach ($sources as $source) {
        $value = trim((string)($source[$field] ?? ''));
        if ($value === '') continue;
        $key = $uppercase ? strtoupper($value) : strtolower((string)preg_replace('/\s+/u', ' ', $value));
        if (!isset($counts[$key])) {
            $counts[$key] = 0;
            $values[$key] = $value;
        }
        $counts[$key]++;
    }
    if (!$counts) return ['', 0, 0];
    $winner = '';
    $best = 0;
    foreach ($counts as $key => $count) {
        if ($count > $best) {
            $winner = $key;
            $best = $count;
        }
    }
    return [$values[$winner], $best, array_sum($counts)];
}

function ip_intel_any_true(array $sources, string $field): bool {
    foreach ($sources as $source) {
        if (($source[$field] ?? null) === true) return true;
    }
    return false;
}

function unique_ip_intel_values(array $values): array {
    $result = [];
    $seen = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $key = strtolower($value);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $result[] = $value;
    }
    return $result;
}

function merge_ip_intel_sources(array $sources): array {
    [$countryCode, $countryVotes, $countryTotal] = ip_intel_consensus($sources, 'country_code', true);
    [$country] = ip_intel_consensus($sources, 'country');
    [$region, $regionVotes, $regionTotal] = ip_intel_consensus($sources, 'region');
    [$city, $cityVotes, $cityTotal] = ip_intel_consensus($sources, 'city');
    [$majorityAsn] = ip_intel_consensus($sources, 'asn_number', true);

    // RIPE 的 BGP 起源 ASN 优先；多起源网段则选取与其他来源一致的 ASN。
    $ripeAsns = [];
    $routePrefix = '';
    foreach ($sources as $source) {
        if (($source['provider'] ?? '') !== 'RIPEstat') continue;
        $ripeAsns = is_array($source['asn_numbers'] ?? null)
            ? $source['asn_numbers']
            : array_filter([trim((string)($source['asn_number'] ?? ''))]);
        $routePrefix = trim((string)($source['prefix'] ?? ''));
        break;
    }
    $selectedAsn = $majorityAsn !== '' && in_array($majorityAsn, $ripeAsns, true)
        ? $majorityAsn
        : ($ripeAsns[0] ?? $majorityAsn);
    $asnVotes = 0;
    $asnTotal = 0;
    foreach ($sources as $source) {
        if (($source['provider'] ?? '') === 'RIPEstat') {
            $sourceAsns = is_array($source['asn_numbers'] ?? null)
                ? $source['asn_numbers']
                : array_filter([trim((string)($source['asn_number'] ?? ''))]);
            if (!$sourceAsns) continue;
            $asnTotal++;
            if ($selectedAsn !== '' && in_array($selectedAsn, $sourceAsns, true)) $asnVotes++;
            continue;
        }
        $asn = trim((string)($source['asn_number'] ?? ''));
        if ($asn === '') continue;
        $asnTotal++;
        if ($selectedAsn !== '' && $asn === $selectedAsn) $asnVotes++;
    }

    $organizationValues = [];
    foreach (['ipwho.is', 'ip-api', 'GeoJS'] as $preferredProvider) {
        foreach ($sources as $source) {
            if (($source['provider'] ?? '') !== $preferredProvider) continue;
            $sourceAsn = trim((string)($source['asn_number'] ?? ''));
            if ($selectedAsn !== '' && $sourceAsn !== '' && $sourceAsn !== $selectedAsn) continue;
            $organizationValues[] = $source['organization'] ?? '';
            $organizationValues[] = $source['isp'] ?? '';
        }
    }
    $organizations = array_slice(unique_ip_intel_values($organizationValues), 0, 2);
    $asnParts = $selectedAsn !== '' ? ['AS' . $selectedAsn] : [];
    array_push($asnParts, ...$organizations);

    $isProxy = ip_intel_any_true($sources, 'proxy');
    $isVpn = ip_intel_any_true($sources, 'vpn');
    $isTor = ip_intel_any_true($sources, 'tor');
    $isHosting = ip_intel_any_true($sources, 'hosting');
    $isMobile = ip_intel_any_true($sources, 'mobile');
    $tags = [];
    if ($isTor) $tags[] = 'Tor';
    if ($isVpn) $tags[] = 'VPN';
    if ($isProxy) $tags[] = '代理';
    if ($isHosting) $tags[] = '机房/托管';
    if ($isMobile) $tags[] = '移动网络';
    if (!$tags) $tags[] = '普通运营商网络';

    $sourceNames = unique_ip_intel_values(array_map(
        static fn(array $source): string => (string)($source['provider'] ?? ''),
        $sources
    ));
    $sourceCount = count($sourceNames);
    $countryAgreement = $countryTotal < 2 || $countryVotes >= 2;
    $regionAgreement = $regionTotal < 2 || $regionVotes >= 2;
    $cityAgreement = $cityTotal < 2 || $cityVotes >= 2;
    $asnAgreement = $asnTotal < 2 || $asnVotes >= 2;
    if ($sourceCount >= 3 && $countryAgreement && $regionAgreement && $cityAgreement && $asnAgreement) {
        $confidence = '高';
    } elseif ($sourceCount >= 2 && $countryAgreement && $asnAgreement) {
        $confidence = '中';
    } else {
        $confidence = '低';
    }

    $consensusParts = [];
    if ($countryTotal) $consensusParts[] = '国家' . $countryVotes . '/' . $countryTotal;
    if ($regionTotal) $consensusParts[] = '地区' . $regionVotes . '/' . $regionTotal;
    if ($cityTotal) $consensusParts[] = '城市' . $cityVotes . '/' . $cityTotal;
    if ($asnTotal) $consensusParts[] = 'ASN' . $asnVotes . '/' . $asnTotal;
    $locationParts = array_values(array_filter([$country, $region, $city], static fn($value): bool => $value !== ''));

    return [
        'location' => $locationParts ? implode(' / ', $locationParts) : ($countryCode ?: '未知地区'),
        'country_code' => $countryCode,
        'asn' => $asnParts ? implode(' ', $asnParts) : '未查询',
        'asn_number' => $selectedAsn,
        'operator' => $organizations ? implode(' / ', $organizations) : '未知运营商',
        'route_prefix' => $routePrefix,
        'source' => implode('、', $sourceNames),
        'sources' => $sourceNames,
        'source_count' => $sourceCount,
        'confidence' => $confidence,
        'consensus' => implode('｜', $consensusParts),
        'network_type' => implode('、', $tags),
        'tags' => $tags,
        'is_proxy' => $isProxy || $isVpn,
        'is_vpn' => $isVpn,
        'is_tor' => $isTor,
        'is_hosting' => $isHosting,
        'is_mobile' => $isMobile,
        'intel_version' => 2,
        'query_failed' => false,
    ];
}
