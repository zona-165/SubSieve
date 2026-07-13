<?php
require_once __DIR__ . '/_auth.php';

if (!defined('STATS_CACHE_JSON')) {
    define('STATS_CACHE_JSON', dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json');
}

$cacheTtl = 45;
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
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
$ips    = [];   // ip => [total,200,403,429,444]  (today only)
$tokens = [];   // token => [count, last_time]     (today only)
$badUas = [];   // ua => count (403 only, today)
$scannerReports = []; // latest suspicious subscription pulls
$profileSegments = []; // /24 segment => observed IP profile cells

// 全量日志用于可疑分析
$suspTokenIps = [];  // token => {ip => true}
$suspIpTokens = [];  // ip    => {token => true}

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
    $handle = fopen(LOG_FILE, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line);
            if ($line === '') continue;

            $pat = '/^(\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)"$/';
            if (!preg_match($pat, $line, $m)) continue;

            [, $ip, $time, $request, $status, , $ua] = $m;
            $status = (int)$status;
            collect_profile_segment($profileSegments, $ip, $time, $request, $status, $ua);

            // ── 今日统计 ──────────────────────────────────────────
            if (str_contains($line, "[$today:")) {
                if (!isset($ips[$ip])) $ips[$ip] = ['total'=>0,'s200'=>0,'s403'=>0,'s429'=>0,'s444'=>0];
                $ips[$ip]['total']++;
                if ($status === 200) $ips[$ip]['s200']++;
                elseif ($status === 403) $ips[$ip]['s403']++;
                elseif ($status === 429) $ips[$ip]['s429']++;
                elseif ($status === 444) $ips[$ip]['s444']++;

                if (preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)) {
                    $tok = $tm[1];
                    if (!isset($tokenBlacklist[$tok])) {
                        if (!isset($tokens[$tok])) $tokens[$tok] = ['count'=>0,'last_time'=>''];
                        $tokens[$tok]['count']++;
                        $tokens[$tok]['last_time'] = trim(preg_replace('/^\d+\/\w+\/\d+:/', '', preg_replace('/ \+\d+$/', '', $time)));
                    }
                }

                if ($status === 403 && $ua !== '') {
                    if (!isset($badUas[$ua])) $badUas[$ua] = 0;
                    $badUas[$ua]++;
                }
            }

            // ── 全量可疑分析（200 状态的订阅请求，排除白名单IP和Token黑名单）──
            if ($status === 200
                && !isset($whitelistIps[$ip])
                && preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)
            ) {
                $tok = $tm[1];
                if (!isset($tokenBlacklist[$tok])) {
                    $suspTokenIps[$tok][$ip] = true;
                    $suspIpTokens[$ip][$tok]  = true;
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
        fclose($handle);
    }
}

// Top IP（今日，最多返回500条，前端负责显示限制）
uasort($ips, fn($a,$b) => $b['total'] - $a['total']);
$topIps = [];
foreach (array_slice($ips, 0, 500, true) as $ip => $v) {
    $topIps[] = array_merge(['ip' => $ip], $v);
}

// Top Token（今日，最多返回500条，前端负责显示限制）
uasort($tokens, fn($a,$b) => $b['count'] - $a['count']);
$topTokens = [];
foreach (array_slice($tokens, 0, 500, true) as $tok => $v) {
    $topTokens[] = [
        'token'      => substr($tok, 0, 8) . '…',
        'token_full' => $tok,
        'count'      => $v['count'],
        'last_time'  => $v['last_time'],
    ];
}

// UA TOP（最多返回500条，前端负责显示限制）
arsort($badUas);
$badUaList = [];
foreach (array_slice($badUas, 0, 500, true) as $ua => $cnt) {
    $badUaList[] = ['ua' => $ua, 'count' => $cnt];
}

// 可疑 Token（日志周期内被 3+ 个不同IP拉取）
$SUSP_TOKEN_THRESHOLD = 3;
$suspTokenList = [];
foreach ($suspTokenIps as $tok => $ipSet) {
    $cnt = count($ipSet);
    if ($cnt >= $SUSP_TOKEN_THRESHOLD) {
        $suspTokenList[] = ['token' => $tok, 'ip_count' => $cnt, 'ips' => array_keys($ipSet)];
    }
}
usort($suspTokenList, fn($a,$b) => $b['ip_count'] - $a['ip_count']);

// 可疑 IP（日志周期内拉取了 3+ 个不同Token）
$SUSP_IP_THRESHOLD = 3;
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
            'last_time' => '',
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
try {
    $userProfiles = build_user_profiles($profileSegments);
} catch (Throwable $e) {
    $userProfiles = [];
}

$payload = [
    'ok'          => true,
    'top_ips'     => $topIps,
    'top_tokens'  => $topTokens,
    'bad_uas'     => $badUaList,
    'susp_tokens' => $suspTokenList,
    'susp_ips'    => $suspIpList,
    'scanner_reports' => $scannerList,
    'user_profiles' => $userProfiles,
    'cached' => false,
];

@file_put_contents(STATS_CACHE_JSON, json_encode(['ts' => time(), 'data' => $payload], JSON_UNESCAPED_UNICODE), LOCK_EX);
json_out($payload);

function collect_profile_segment(array &$segments, string $ip, string $time, string $request, int $status, string $ua): void {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return;
    $parts = explode('.', $ip);
    if (count($parts) !== 4) return;
    $octets = array_map('intval', $parts);
    foreach ($octets as $octet) {
        if ($octet < 0 || $octet > 255) return;
    }
    $segment = $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0';
    $rangeEnd = $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.255';
    $lastOctet = $octets[3];
    if (!isset($segments[$segment])) {
        $segments[$segment] = [
            'base' => $segment,
            'range' => $segment . ' - ' . $rangeEnd,
            'ips' => [],
            'total' => 0,
            'last_time' => '',
        ];
    }
    if (!isset($segments[$segment]['ips'][$ip])) {
        $segments[$segment]['ips'][$ip] = [
            'ip' => $ip,
            'last_octet' => $lastOctet,
            'count' => 0,
            'bad' => 0,
            'ok' => 0,
            'tokens' => [],
            'uas' => [],
            'scanner' => false,
            'last_time' => '',
        ];
    }
    $segments[$segment]['total']++;
    $segments[$segment]['last_time'] = format_log_time($time);
    $cell =& $segments[$segment]['ips'][$ip];
    $cell['count']++;
    $cell['last_time'] = format_log_time($time);
    if ($status === 200) $cell['ok']++;
    if (in_array($status, [403, 429, 444], true)) $cell['bad']++;
    if (preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)) $cell['tokens'][$tm[1]] = true;
    $u = trim($ua);
    if ($u !== '' && $u !== '-') $cell['uas'][$u] = true;
    if (scanner_reason($ua) !== '') $cell['scanner'] = true;
}

function build_user_profiles(array $segments): array {
    if (!$segments) return [];
    uasort($segments, fn($a, $b) => ($b['total'] <=> $a['total']) ?: strcmp($a['base'], $b['base']));
    $cache = read_ip_intel_cache();
    $profiles = [];
    foreach (array_slice($segments, 0, 8, true) as $segment) {
        $cells = [];
        $summary = ['V'=>0, 'O'=>0, 'T'=>0, 'P'=>0, 'B'=>0, 'N'=>0];
        uasort($segment['ips'], fn($a, $b) => $a['last_octet'] <=> $b['last_octet']);
        foreach ($segment['ips'] as $ip => $cell) {
            $cached = isset($cache[$ip]) && is_array($cache[$ip]) && (time() - (int)($cache[$ip]['ts'] ?? 0) < 604800);
            $intel = $cached ? ($cache[$ip]['data'] ?? null) : null;
            $kind = profile_cell_kind($cell, $intel);
            $summary[$kind]++;
            $cells[] = [
                'ip' => $ip,
                'octet' => $cell['last_octet'],
                'kind' => $kind,
                'label' => $kind === 'N' ? '·' : $kind,
                'count' => $cell['count'],
                'token_count' => count($cell['tokens']),
                'ua_count' => count($cell['uas']),
                'last_time' => $cell['last_time'],
                'location' => $intel['location'] ?? '未查询',
                'asn' => $intel['asn'] ?? '未查询',
                'network_type' => $intel['network_type'] ?? '本地日志',
            ];
        }
        $profiles[] = [
            'range' => $segment['range'],
            'total' => $segment['total'],
            'ip_count' => count($segment['ips']),
            'last_time' => $segment['last_time'],
            'summary' => $summary,
            'cells' => array_slice($cells, 0, 256),
        ];
    }
    return $profiles;
}

function profile_cell_kind(array $cell, ?array $intel): string {
    $tags = $intel['tags'] ?? [];
    $network = $intel['network_type'] ?? '';
    $asn = strtolower($intel['asn'] ?? '');
    if (!empty($cell['scanner']) || (int)($cell['bad'] ?? 0) > 0) return 'B';
    if (str_contains($network, 'Tor') || str_contains($asn, 'tor')) return 'T';
    if (!empty($intel['is_proxy']) || str_contains($network, '代理') || str_contains($network, 'VPN')) return 'P';
    if (!empty($intel['is_hosting']) || str_contains($network, '机房') || str_contains($network, '托管')) return 'O';
    foreach ($tags as $tag) {
        if (str_contains($tag, '代理') || str_contains($tag, 'VPN')) return 'P';
        if (str_contains($tag, '机房') || str_contains($tag, '托管')) return 'O';
    }
    return 'N';
}

function scanner_reason(string $ua): string {
    $u = strtolower(trim($ua));
    if ($u === '' || $u === '-') return 'empty_or_invalid_user_agent';
    $patterns = [
        'clash' => 'proxy_client_user_agent',
        'curl' => 'script_user_agent',
        'wget' => 'script_user_agent',
        'python' => 'script_user_agent',
        'go-http-client' => 'script_user_agent',
        'java' => 'script_user_agent',
        'node-fetch' => 'script_user_agent',
        'okhttp' => 'script_user_agent',
        'httpclient' => 'script_user_agent',
        'postman' => 'tool_user_agent',
    ];
    foreach ($patterns as $needle => $reason) {
        if (str_contains($u, $needle)) return $reason;
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
    foreach ($reports as &$report) {
        $ip = $report['ip'] ?? '';
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) continue;
        $cached = isset($cache[$ip]) && is_array($cache[$ip]) && (time() - (int)($cache[$ip]['ts'] ?? 0) < 604800);
        if (!$cached) continue;
        $intel = $cache[$ip]['data'] ?? null;
        if (!$intel) continue;
        $report['location'] = $intel['location'] ?? '未查询';
        $report['asn'] = $intel['asn'] ?? '未查询';
        $report['query_source'] = $intel['source'] ?? 'ip-api';
        $report['network_type'] = $intel['network_type'] ?? '未知网络';
        $report['intel_tags'] = $intel['tags'] ?? [];
        if (!empty($intel['is_proxy']) || !empty($intel['is_hosting'])) {
            $report['score'] = max((int)($report['score'] ?? 90), 95);
            $report['risk'] = '极高危';
        }
    }
    unset($report);
    return $reports;
}

function read_ip_intel_cache(): array {
    if (!file_exists(IP_INTEL_CACHE_JSON)) return [];
    $data = json_decode((string)file_get_contents(IP_INTEL_CACHE_JSON), true);
    return is_array($data) ? $data : [];
}

function write_ip_intel_cache(array $cache): void {
    @file_put_contents(IP_INTEL_CACHE_JSON, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function get_ip_intel(string $ip, array &$cache, bool &$dirty): ?array {
    $now = time();
    if (isset($cache[$ip]) && is_array($cache[$ip]) && ($now - (int)($cache[$ip]['ts'] ?? 0) < 604800)) {
        return $cache[$ip]['data'] ?? null;
    }
    $intel = query_ip_api($ip);
    if (!$intel) {
        $intel = [
            'location' => '查询失败',
            'asn' => '查询失败',
            'source' => 'ip-api',
            'network_type' => '未知网络',
            'tags' => ['情报查询失败'],
            'is_proxy' => false,
            'is_hosting' => false,
        ];
    }
    $cache[$ip] = ['ts' => $now, 'data' => $intel];
    $dirty = true;
    return $intel;
}

function query_ip_api(string $ip): ?array {
    $fields = 'status,message,country,regionName,city,isp,org,as,proxy,hosting,mobile';
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=' . rawurlencode($fields) . '&lang=zh-CN';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 1.2,
            'header' => "User-Agent: SubSieve-IP-Intel/1.0\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') return null;
    $parts = array_filter([$data['country'] ?? '', $data['regionName'] ?? '', $data['city'] ?? '']);
    $tags = [];
    if (!empty($data['proxy'])) $tags[] = '代理/VPN';
    if (!empty($data['hosting'])) $tags[] = '机房/托管';
    if (!empty($data['mobile'])) $tags[] = '移动网络';
    if (!$tags) $tags[] = '普通运营商网络';
    return [
        'location' => $parts ? implode(' / ', $parts) : '未知地区',
        'asn' => trim(($data['as'] ?? '') . ' ' . ($data['isp'] ?? '') . ' ' . ($data['org'] ?? '')),
        'source' => 'ip-api',
        'network_type' => implode('、', $tags),
        'tags' => $tags,
        'is_proxy' => !empty($data['proxy']),
        'is_hosting' => !empty($data['hosting']),
    ];
}
