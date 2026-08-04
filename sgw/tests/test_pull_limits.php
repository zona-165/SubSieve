<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');
define('TOKEN_LIMIT_CONF', sys_get_temp_dir() . '/subsieve-token-limit-test.conf');
require_once dirname(__DIR__) . '/admin/src/lib/guard.php';

$failures = [];
function expect_limit(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$now = strtotime('2026-08-04 12:00:00');
$path = '/api/v1/client/subscribe';
$secret = str_repeat('a', 64);
$lines = [];

function limit_log(string $ip, int $ts, string $token, string $path): string {
    return sprintf(
        '%s [%s] "GET %s?token=%s HTTP/1.1" 200 128 "clash"',
        $ip,
        date('d/M/Y:H:i:s O', $ts),
        $path,
        rawurlencode($token)
    );
}

for ($i = 1; $i <= 11; $i++) {
    $lines[] = limit_log("10.0.0.$i", $now - 120 + $i, 'multi-ip-token', $path);
}
for ($i = 0; $i < 11; $i++) {
    $lines[] = limit_log('20.0.0.1', $now - 20 + $i, 'fast-token', $path);
}
$lines[] = limit_log('30.0.0.1', $now - 10, 'normal-token', $path);

$settings = [
    'guard_pull_limit_enabled' => 1,
    'guard_pull_limit_enforce' => 0,
    'guard_pull_limit_24h_ips' => 10,
    'guard_pull_limit_per_minute' => 10,
    'guard_pull_limit_suspend_hours' => 24,
];

$observe = guard_analyze_pull_limits($lines, $settings, $now, $path, $secret, [], false);
expect_limit(($observe['summary']['active_tokens'] ?? 0) === 3, '应统计 3 个活跃 Token');
expect_limit(($observe['summary']['pending_violations'] ?? 0) === 2, '应识别 2 个超限 Token');
expect_limit(count($observe['_state']['entries'] ?? []) === 0, '监控模式不得创建暂停状态');

$settings['guard_pull_limit_enforce'] = 1;
$enforced = guard_analyze_pull_limits($lines, $settings, $now, $path, $secret, [], true);
$entries = $enforced['_state']['entries'] ?? [];
expect_limit(count($entries) === 2, '自动暂停模式应创建 2 个暂停状态');
foreach ($entries as $fingerprint => $entry) {
    expect_limit(str_starts_with($fingerprint, 'TKN-'), '暂停状态必须使用 Token 指纹作为键');
    expect_limit(($entry['until_ts'] ?? 0) === $now + 86400, '暂停时长应为 24 小时');
}
$stateJson = json_encode($enforced['_state'], JSON_UNESCAPED_UNICODE);
expect_limit(!str_contains((string)$stateJson, 'multi-ip-token'), '暂停状态不得保存明文 Token');
expect_limit(!str_contains((string)$stateJson, 'fast-token'), '暂停状态不得保存明文 Token');

$rawTokens = [];
foreach ($enforced['_all_usage'] as $row) {
    if (!empty($row['suspended'])) $rawTokens[$row['fingerprint']] = $row['_raw_token'];
}
$map = guard_token_limit_map_content($entries, $rawTokens);
expect_limit(substr_count($map, ' 1;') === 2, 'Nginx 暂停映射应包含 2 条精确规则');
expect_limit(str_contains($map, 'map $arg_token $is_token_temporarily_suspended'), 'Nginx 暂停映射变量缺失');

$rate = guard_token_limit_rate_content(guard_normalize_settings($settings));
expect_limit(str_contains($rate, 'rate=10r/m'), 'Token 频率应为 10 次/分钟');
expect_limit(str_contains($rate, '"~^0:.+$" $arg_token;'), '启用自动暂停时应启用 Token 频率键');
$settings['guard_pull_limit_enforce'] = 0;
$disabledRate = guard_token_limit_rate_content(guard_normalize_settings($settings));
expect_limit(!str_contains($disabledRate, '"~^0:.+$" $arg_token;'), '监控模式不得执行 Nginx Token 频率限制');

if ($failures !== []) {
    fwrite(STDERR, "Token 拉取限制测试失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Token 拉取限制测试通过\n";
