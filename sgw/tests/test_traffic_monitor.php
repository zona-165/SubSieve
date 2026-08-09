<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once dirname(__DIR__) . '/admin/src/lib/guard.php';
require_once dirname(__DIR__) . '/admin/src/lib/traffic_monitor.php';

function traffic_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function traffic_test_line(string $action, int $ts, string $sourceIp, array|string $payload, int $status = 200): string {
    $body = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : $payload;
    return json_encode([
        'time' => date(DATE_ATOM, $ts),
        'ip' => $sourceIp,
        'method' => 'POST',
        'uri' => '/api/v1/server/UniProxy/' . $action,
        'status' => $status,
        'bytes' => 32,
        'request_time' => 0.03,
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

function traffic_subscribe_line(string $ip, int $ts, string $token): string {
    return sprintf(
        '%s [%s] "GET /go/test/?token=%s HTTP/1.1" 200 128 "Clash.Meta"',
        $ip,
        date('d/M/Y:H:i:s O', $ts),
        rawurlencode($token)
    );
}

$now = strtotime('2026-08-09 12:00:00 UTC');
$gib = 1024 * 1024 * 1024;
$secret = 'traffic-unit-secret';
$rawUserId = 'airport-user-101';
$rawToken = 'raw-subscription-token';
$clientIp = '198.51.100.81';
$lines = [
    traffic_test_line('push', $now - 60, '192.0.2.10', [$rawUserId => [2 * $gib, 5 * $gib]]),
    traffic_test_line('push', $now - 30, '192.0.2.10', [$rawUserId => [1 * $gib, 5 * $gib]]),
    traffic_test_line('alive', $now - 20, '192.0.2.10', [$rawUserId => [$clientIp . '_node-7', '203.0.113.22']]),
    traffic_test_line('push', $now - 10, '192.0.2.11', '{invalid-json'),
    traffic_test_line('user', $now - 5, '192.0.2.10', ''),
    traffic_test_line('alivelist', $now - 4, '192.0.2.10', ''),
];
$subscriptionLines = [traffic_subscribe_line($clientIp, $now - 15, $rawToken)];
$settings = array_merge(traffic_default_settings(), [
    'traffic_user_hour_ips' => 3,
    'traffic_report_5m_requests' => 50,
]);

$result = traffic_analyze_logs($lines, $subscriptionLines, $settings, $now, '/go/test/', $secret);
$parsedAlive = traffic_parse_log_line($lines[2], '/api/v1/server/UniProxy', $secret);
$userFingerprint = traffic_user_fingerprint($rawUserId, $secret);
traffic_check(($result['summary']['observed_reports'] ?? 0) === 4, 'report count mismatch');
traffic_check(($result['summary']['push_reports'] ?? 0) === 3, 'push report count mismatch');
traffic_check(($result['summary']['alive_reports'] ?? 0) === 1, 'alive report count mismatch');
traffic_check(($result['summary']['users_24h'] ?? 0) === 1, 'user fingerprint count mismatch');
traffic_check(
    in_array($clientIp, $parsedAlive['users'][$userFingerprint]['ips'] ?? [], true),
    'alive IP with node suffix was not normalized'
);
traffic_check(($result['summary']['parse_errors'] ?? 0) === 1, 'invalid JSON was not counted');
traffic_check(($result['summary']['correlated_findings'] ?? 0) === 1, 'subscription correlation was not detected');
traffic_check(count($result['findings']) === 1, 'expected one user traffic finding');
$finding = $result['findings'][0];
traffic_check(($finding['kind'] ?? '') === 'traffic_user_anomaly', 'traffic finding kind mismatch');
traffic_check(str_starts_with((string)($finding['subject'] ?? ''), 'USR-'), 'raw user id was not fingerprinted');
traffic_check(($finding['linked_subscription_requests'] ?? 0) === 1, 'linked subscription request count missing');
traffic_check(($finding['linked_token_count'] ?? 0) === 1, 'linked Token fingerprint count missing');
traffic_check(isset($finding['trigger_details']['5 分钟流量']), 'traffic threshold detail missing');
traffic_check(isset($finding['trigger_details']['订阅关联']), 'subscription correlation detail missing');
$encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
traffic_check(!str_contains($encoded, $rawUserId), 'raw airport user id leaked into analysis');
traffic_check(!str_contains($encoded, $rawToken), 'raw subscription Token leaked into analysis');

$disabled = traffic_analyze_logs(
    $lines,
    $subscriptionLines,
    array_merge($settings, ['traffic_monitor_enabled' => 0]),
    $now,
    '/go/test/',
    $secret
);
traffic_check(count($disabled['findings']) === 0, 'disabled monitor still produced findings');
traffic_check(($disabled['summary']['observed_reports'] ?? 0) === 4, 'disabled monitor lost operational counters');

$analysisDisabled = traffic_analyze_logs(
    $lines,
    $subscriptionLines,
    array_merge($settings, ['traffic_monitor_enabled' => 1, 'traffic_analysis_enabled' => 0]),
    $now,
    '/go/test/',
    $secret
);
traffic_check(count($analysisDisabled['findings']) === 0, 'disabled traffic analysis still produced findings');
traffic_check(!empty($analysisDisabled['summary']['capture_enabled']), 'capture state was lost when analysis was disabled');
traffic_check(empty($analysisDisabled['summary']['analysis_enabled']), 'analysis state was not reported as disabled');

$floodLines = [];
for ($i = 0; $i < 10; $i++) {
    $floodLines[] = traffic_test_line('alive', $now - $i, '192.0.2.99', []);
}
$flood = traffic_analyze_logs(
    $floodLines,
    [],
    array_merge(traffic_default_settings(), ['traffic_report_5m_requests' => 10]),
    $now,
    '/go/test/',
    $secret
);
traffic_check(in_array('traffic_report_flood', array_column($flood['findings'], 'kind'), true), 'report flood finding missing');

$clamped = traffic_normalize_settings([
    'traffic_report_path' => 'invalid path',
    'traffic_user_5m_gb' => 0,
    'traffic_scan_lines' => 999999,
]);
traffic_check($clamped['traffic_report_path'] === '/api/v1/server/UniProxy', 'invalid report path was accepted');
traffic_check($clamped['traffic_user_5m_gb'] === 1, 'traffic threshold minimum clamp failed');
traffic_check($clamped['traffic_scan_lines'] === 100000, 'traffic scan maximum clamp failed');

$nginx = (string)file_get_contents(dirname(__DIR__) . '/gateway/nginx/nginx.conf');
$proxyTemplate = (string)file_get_contents(dirname(__DIR__) . '/gateway/nginx/uniproxy_proxy.conf.template');
traffic_check(str_contains($nginx, 'log_format  uniproxy  escape=json'), 'JSON traffic log format missing');
traffic_check(str_contains($nginx, '"uri":"$uri"'), 'traffic log does not use query-safe URI');
traffic_check(!str_contains($nginx, '"uri":"$request_uri"'), 'traffic log records query strings');
traffic_check(str_contains($proxyTemplate, 'proxy_pass'), 'UniProxy transparent proxy missing');
traffic_check(str_contains($proxyTemplate, 'location = /api/v2/server/config'), 'V2Node config proxy missing');
traffic_check(
    preg_match('#location = /api/v2/server/config\s*\{[^}]*access_log off;#s', $proxyTemplate) === 1,
    'V2Node config route may leak query credentials to the access log'
);
traffic_check(!str_contains($proxyTemplate, 'return 403') && !str_contains($proxyTemplate, 'limit_req'), 'subscription blocking leaked into traffic proxy');
traffic_check(str_contains($proxyTemplate, 'allow all;'), 'server-level IP blacklist can block traffic reports');
traffic_check(str_contains($proxyTemplate, 'client_body_buffer_size 8m;'), 'large JSON reports may not be available to the analyzer');

echo "traffic monitor tests passed\n";
