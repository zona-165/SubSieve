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

$result = guard_analyze_logs($lines, guard_default_settings(), $now, '/go/test/', 'unit-test-secret');
$kinds = array_column($result['findings'], 'kind');
foreach (['ip_rate', 'token_rate', 'token_multi_ip', 'ip_multi_token', 'ip_404_flood'] as $kind) {
    check(in_array($kind, $kinds, true), "missing finding {$kind}");
}
check(($result['metrics']['today_requests'] ?? 0) === count($lines), 'today request count mismatch');
check(($result['metrics']['today_ips'] ?? 0) === 11, 'unique IP count mismatch');
check(!str_contains(json_encode($result), 'shared-token'), 'raw Token leaked into guard result');

$disabled = guard_analyze_logs($lines, ['guard_observe_enabled' => 0], $now, '/go/test/', 'unit-test-secret');
check(count($disabled['findings']) === 0, 'disabled observation still produced findings');
check(($disabled['metrics']['today_requests'] ?? 0) === count($lines), 'disabled observation lost metrics');

$clamped = guard_normalize_settings([
    'guard_ip_per_minute' => 1,
    'guard_scan_lines' => 999999,
]);
check($clamped['guard_ip_per_minute'] === 5, 'minimum clamp failed');
check($clamped['guard_scan_lines'] === 100000, 'maximum clamp failed');
check(guard_parse_log_line('invalid') === null, 'invalid log line was accepted');

echo "guard analysis tests passed\n";
