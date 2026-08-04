<?php
declare(strict_types=1);

$sgwRoot = dirname(__DIR__);
$failures = [];

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function normalizedConfig(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("无法读取 $path");
    }
    return str_replace('\\$', '$', $content);
}

function assertRuleOrder(string $name, string $content): void
{
    $needles = [
        'set $block_reason "";',
        'if ($bad_subscribe_ua = 1)',
        'if ($is_custom_bad_ua = 1)',
        'if ($is_ua_whitelisted = 1)',
        'if ($is_cloud_ip = 1)',
        'if ($is_token_blacklisted = 1)',
        'if ($whitelist_ip = 1)',
    ];

    $cursor = 0;
    foreach ($needles as $needle) {
        $position = strpos($content, $needle, $cursor);
        check($position !== false, "$name 缺少规则或优先级错误：$needle");
        if ($position === false) {
            return;
        }
        $cursor = $position + strlen($needle);
    }
}

function blockReason(array $state): string
{
    $reason = '';
    if ($state['bad_ua']) {
        $reason = 'ua';
    }
    if ($state['custom_bad_ua']) {
        $reason = 'ua';
    }
    if ($state['ua_whitelist']) {
        $reason = '';
    }
    if ($state['cloud']) {
        $reason = 'cloud';
    }
    if ($state['token']) {
        $reason = 'token';
    }
    if ($state['ip_whitelist']) {
        $reason = '';
    }
    return $reason;
}

$template = normalizedConfig($sgwRoot . '/gateway/nginx/subscribe_protect.conf.template');
$settings = normalizedConfig($sgwRoot . '/admin/src/api/settings.php');
assertRuleOrder('网关模板', $template);
assertRuleOrder('设置页生成模板', $settings);

$defaults = [
    'bad_ua' => false,
    'custom_bad_ua' => false,
    'ua_whitelist' => false,
    'cloud' => false,
    'token' => false,
    'ip_whitelist' => false,
];
$cases = [
    '普通请求放行' => [[], ''],
    '可疑 UA 被拦截' => [['bad_ua' => true], 'ua'],
    'UA 白名单只豁免 UA' => [['bad_ua' => true, 'ua_whitelist' => true], ''],
    'UA 白名单不能绕过 IDC' => [['cloud' => true, 'ua_whitelist' => true], 'cloud'],
    'UA 白名单不能绕过 Token' => [['token' => true, 'ua_whitelist' => true], 'token'],
    'Token 黑名单保持最高拦截原因' => [['cloud' => true, 'token' => true], 'token'],
    '显式 IP 白名单可豁免 IDC' => [['cloud' => true, 'ip_whitelist' => true], ''],
    '显式 IP 白名单可豁免 Token' => [['token' => true, 'ip_whitelist' => true], ''],
];

foreach ($cases as $name => [$overrides, $expected]) {
    $actual = blockReason(array_replace($defaults, $overrides));
    check($actual === $expected, "{$name}：期望 '$expected'，实际 '$actual'");
}

$updater = normalizedConfig($sgwRoot . '/gateway/scripts/update_cloud_geo.sh');
$candidateTest = strpos($updater, 'nginx -t -c "$TEST_CONF"');
$atomicMove = strpos($updater, 'mv "$OUTPUT_TMP" "$OUTPUT"');
$fullTest = strpos($updater, 'if ! nginx -t >/dev/null');
check($candidateTest !== false, '云 IP 更新脚本缺少候选配置校验');
check($atomicMove !== false, '云 IP 更新脚本缺少原子替换');
check($fullTest !== false, '云 IP 更新脚本缺少完整配置校验');
if ($candidateTest !== false && $atomicMove !== false && $fullTest !== false) {
    check($candidateTest < $atomicMove, '候选配置必须在替换前校验');
    check($atomicMove < $fullTest, '完整配置必须在替换后、重载前校验');
}
check(str_contains($updater, 'restore_previous'), '云 IP 更新脚本缺少上一版回滚逻辑');
check(str_contains($updater, ".data.prefixes[]?.prefix // empty"), 'RIPE CIDR 必须使用 jq 结构化解析');
check(str_contains($updater, ".prefixes[]?.ip_prefix // empty"), 'AWS CIDR 必须使用 jq 结构化解析');
check(!str_contains($updater, "grep -o '\"ip_prefix\":\""), 'AWS CIDR 不得依赖固定 JSON 空格格式');

if ($failures !== []) {
    fwrite(STDERR, "封禁规则测试失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "封禁规则测试通过：UA 白名单不能绕过 IDC/Token，云 CIDR 更新具备校验与回滚。\n";
