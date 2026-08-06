<?php
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/lib/ip_intel_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err('Method not allowed', 405);

$parts = preg_split('/[\s,]+/', (string)($_GET['ips'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
$ips = [];
foreach ($parts ?: [] as $ip) {
    $ip = trim($ip);
    if (!ip_intel_is_public($ip)) continue;
    $ips[$ip] = true;
    if (count($ips) >= 50) break;
}

$cache = [];
if (file_exists(IP_INTEL_CACHE_JSON)) {
    $decoded = json_decode((string)file_get_contents(IP_INTEL_CACHE_JSON), true);
    if (is_array($decoded)) $cache = $decoded;
}

$entries = [];
$queued = [];
foreach (array_keys($ips) as $ip) {
    $entry = is_array($cache[$ip] ?? null) ? $cache[$ip] : [];
    $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
    $fresh = is_ip_intel_cache_fresh($entry);
    if (!$fresh) $queued[] = $ip;

    if (!$data || !empty($data['query_failed'])) {
        $entries[] = ['ip' => $ip, 'status' => 'pending', 'fresh' => false];
        continue;
    }

    $asnText = trim((string)($data['asn'] ?? ''));
    $asnNumber = trim((string)($data['asn_number'] ?? ''));
    if ($asnNumber === '' && preg_match('/\bAS\s*(\d+)/i', $asnText, $match)) $asnNumber = $match[1];
    $operator = trim((string)($data['operator'] ?? ''));
    if ($operator === '' && $asnText !== '') {
        $operator = trim((string)preg_replace('/^AS\s*\d+\s*/i', '', $asnText));
    }
    [$riskLevel, $riskLabel, $riskReason] = ip_intel_present_risk($data);
    $entries[] = [
        'ip' => $ip,
        'status' => 'ready',
        'fresh' => $fresh,
        'location' => $data['location'] ?? '未知地区',
        'operator' => $operator !== '' ? $operator : '未知运营商',
        'asn' => $asnNumber !== '' ? 'AS' . $asnNumber : ($asnText !== '' ? $asnText : '未知 ASN'),
        'route_prefix' => $data['route_prefix'] ?? '',
        'network_type' => $data['network_type'] ?? '未知网络',
        'risk_level' => $riskLevel,
        'risk_label' => $riskLabel,
        'risk_reason' => $riskReason,
        'source' => $data['source'] ?? '缓存情报',
        'source_count' => (int)($data['source_count'] ?? 0),
        'confidence' => $data['confidence'] ?? '未评估',
        'consensus' => $data['consensus'] ?? '',
        'updated_at' => isset($entry['ts']) ? date('Y-m-d H:i:s', (int)$entry['ts']) : '',
    ];
}

if ($queued) ip_intel_enqueue($queued);
json_out(['ok' => true, 'entries' => $entries, 'queued' => count($queued)]);

function ip_intel_present_risk(array $data): array {
    $signals = [];
    if (!empty($data['is_tor'])) $signals[] = 'Tor';
    if (!empty($data['is_vpn'])) $signals[] = 'VPN';
    if (!empty($data['is_proxy'])) $signals[] = '代理';
    if (!empty($data['is_hosting'])) $signals[] = '机房/托管';
    if ($signals) return ['high', '高风险', implode('、', array_unique($signals))];
    if (($data['confidence'] ?? '') === '低') return ['review', '需复核', '多源一致性较低'];
    return ['low', '低风险', $data['network_type'] ?? '未发现代理或机房信号'];
}
