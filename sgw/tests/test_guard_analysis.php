<?php
date_default_timezone_set('UTC');
require_once dirname(__DIR__) . '/admin/src/lib/guard.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function log_line(string $ip, int $ts, string $token, int $status = 200, string $ua = 'Clash.Meta'): string {
    $time = date('d/M/Y:H:i:s O', $ts);
    $path = '/go/test/?token=' . rawurlencode($token);
    return sprintf('%s [%s] "GET %s HTTP/1.1" %d 128 "%s"', $ip, $time, $path, $status, $ua);
}

function cloud_log_line(string $ip, int $ts, string $provider, string $ua = 'curl/8.0'): string {
    $time = date('d/M/Y:H:i:s O', $ts);
    return sprintf(
        '%s [%s] "GET /go/test/?token=blocked-cloud HTTP/1.1" 403 19 "%s" "reason=cloud" "provider=%s"',
        $ip,
        $time,
        $ua,
        $provider
    );
}

$now = strtotime('2026-08-04 12:00:00 UTC');
$lines = [];

for ($i = 0; $i < 30; $i++) {
    $lines[] = log_line('198.51.100.10', $now - ($i % 30), 'rate-token');
}
for ($i = 0; $i < 8; $i++) {
    $lines[] = log_line('203.0.113.' . ($i + 1), $now - 600 - $i, 'shared-token');
}
for ($i = 0; $i < 20; $i++) {
    $lines[] = log_line('198.51.100.20', $now - 1200 - $i, 'many-token-' . $i);
}
for ($i = 0; $i < 40; $i++) {
    $lines[] = log_line('198.51.100.40', $now - 120 - ($i % 120), 'not-found-token', 404, 'curl/8.0');
}
$lines[] = cloud_log_line('192.0.2.88', $now - 10, 'aws');
$lines[] = cloud_log_line('192.0.2.88', $now - 5, 'aws');

$result = guard_analyze_logs($lines, guard_default_settings(), $now, '/go/test/', 'unit-test-secret');
$kinds = array_column($result['findings'], 'kind');
foreach (['ip_rate', 'token_rate', 'token_multi_ip', 'ip_multi_token', 'ip_404_flood'] as $kind) {
    check(in_array($kind, $kinds, true), "missing finding {$kind}");
}
check(in_array('idc_provider_block', $kinds, true), 'missing IDC provider block finding');
$cloudFinding = array_values(array_filter($result['findings'], fn(array $row): bool => ($row['kind'] ?? '') === 'idc_provider_block'))[0] ?? [];
check(($cloudFinding['provider_id'] ?? '') === 'aws', 'IDC provider id missing');
check(($cloudFinding['count'] ?? 0) === 2, 'IDC block count mismatch');
check(($cloudFinding['status_counts']['403'] ?? 0) === 2, 'IDC status evidence missing');
check(($cloudFinding['trigger_details']['网关动作'] ?? '') === 'HTTP 403 · Forbidden: Cloud IP', 'IDC trigger details missing');
$overlapFindings = guard_analyze_logs([
    cloud_log_line('192.0.2.99', $now - 2, 'aws'),
    cloud_log_line('192.0.2.99', $now - 1, 'azure'),
], ['guard_observe_enabled' => 0], $now, '/go/test/', 'unit-test-secret')['findings'];
check(count($overlapFindings) === 2, 'overlapping provider events were merged');
check(count(array_unique(array_column($overlapFindings, 'key'))) === 2, 'provider finding keys collided');
check(($result['metrics']['today_requests'] ?? 0) === count($lines), 'today request count mismatch');
check(($result['metrics']['today_ips'] ?? 0) === 12, 'unique IP count mismatch');
check(!str_contains(json_encode($result), 'shared-token'), 'raw Token leaked into guard result');

$disabled = guard_analyze_logs($lines, ['guard_observe_enabled' => 0], $now, '/go/test/', 'unit-test-secret');
check(count($disabled['findings']) === 1 && ($disabled['findings'][0]['kind'] ?? '') === 'idc_provider_block', 'disabled observation lost enforced IDC evidence');
check(($disabled['metrics']['today_requests'] ?? 0) === count($lines), 'disabled observation lost metrics');

$dailyRows = [[
    'ip' => '134.195.100.42',
    'total' => 233,
    's200' => 50,
    's403' => 70,
    's429' => 111,
    's444' => 2,
    'token_count' => 3,
    'last_time' => '2026-08-04 11:59:00',
]];
$dailyFindings = guard_add_daily_ip_volume_findings([], $dailyRows, guard_default_settings(), $now, 'unit-test-secret');
check(count($dailyFindings) === 1, 'daily IP volume finding was not created');
check(($dailyFindings[0]['kind'] ?? '') === 'daily_ip_volume', 'daily IP volume kind mismatch');
check(($dailyFindings[0]['count'] ?? 0) === 233, 'daily IP volume count mismatch');
check(($dailyFindings[0]['token_count'] ?? 0) === 3, 'daily IP token count mismatch');
check(($dailyFindings[0]['status_counts']['429'] ?? 0) === 111, 'daily IP status counts missing');
check(!str_contains(json_encode($dailyFindings), 'preview-token'), 'daily IP finding leaked Token data');

$whitelistedDaily = guard_add_daily_ip_volume_findings(
    [],
    $dailyRows,
    guard_default_settings(),
    $now,
    'unit-test-secret',
    ['134.195.100.42' => true]
);
check(count($whitelistedDaily) === 0, 'whitelisted daily IP produced a finding');
$disabledDaily = guard_add_daily_ip_volume_findings([], $dailyRows, ['guard_observe_enabled' => 0], $now, 'unit-test-secret');
check(count($disabledDaily) === 0, 'disabled observation produced a daily finding');

$clamped = guard_normalize_settings([
    'guard_ip_daily_requests' => 1,
    'guard_ip_per_minute' => 1,
    'guard_scan_lines' => 999999,
]);
check($clamped['guard_ip_daily_requests'] === 20, 'daily IP minimum clamp failed');
check($clamped['guard_ip_per_minute'] === 5, 'minimum clamp failed');
check($clamped['guard_scan_lines'] === 100000, 'maximum clamp failed');
check(guard_parse_log_line('invalid') === null, 'invalid log line was accepted');
check(guard_count_cloud_cidr_lines([
    '    203.0.113.0/24 1;',
    '    198.51.100.0/24 aws;',
    '    192.0.2.0/24 google_cloud;',
    '    default "";',
]) === 3, 'provider-id CIDR lines were not counted');

echo "guard analysis tests passed\n";
