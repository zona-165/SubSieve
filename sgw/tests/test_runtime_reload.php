<?php
declare(strict_types=1);

$failures = [];
function reload_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$root = sys_get_temp_dir() . '/subsieve-reload-' . bin2hex(random_bytes(5));
$configDir = $root . '/subscribe';
mkdir($configDir, 0770, true);
$fakeNginx = $root . '/nginx';
$script = dirname(__DIR__) . '/gateway/scripts/reload_whitelist.sh';

$fake = <<<'SH'
#!/bin/sh
if [ "${FAKE_NGINX_FAIL_RELOAD:-0}" = "1" ] && [ "${1:-}" = "-s" ] && [ "${2:-}" = "reload" ]; then
    exit 1
fi
exit 0
SH;
file_put_contents($fakeNginx, $fake . "\n");
chmod($fakeNginx, 0755);

$input = $configDir . '/whitelist_ips.txt';
$output = $configDir . '/whitelist.conf';
file_put_contents($input, "1.1.1.1  # test\n");
file_put_contents($output, "old whitelist\n");

$baseCommand = 'SUBSCRIBE_DIR=' . escapeshellarg($configDir)
    . ' NGINX_BIN=' . escapeshellarg($fakeNginx)
    . ' bash ' . escapeshellarg($script);
$lines = [];
$code = 0;
exec($baseCommand . ' 2>&1', $lines, $code);
reload_expect($code === 0, '白名单成功场景执行失败: ' . implode('; ', $lines));
$applied = (string)file_get_contents($output);
reload_expect(str_contains($applied, '1.1.1.1 1;'), '成功场景没有生成新白名单配置');
reload_expect(!file_exists($output . '.prev'), '成功场景不应残留上一版备份');

$stable = "stable whitelist\n";
file_put_contents($output, $stable);
file_put_contents($input, "2.2.2.2\n");
$lines = [];
$code = 0;
exec('FAKE_NGINX_FAIL_RELOAD=1 ' . $baseCommand . ' 2>&1', $lines, $code);
reload_expect($code !== 0, '模拟 reload 失败时脚本必须返回失败');
reload_expect(file_get_contents($output) === $stable, 'reload 失败后没有恢复上一版白名单配置');
reload_expect(!file_exists($output . '.prev'), '失败回滚后不应残留上一版备份');

$watcher = dirname(__DIR__) . '/gateway/scripts/nginx_reload_watcher.sh';
$runtimeFile = $configDir . '/blacklist.conf';
$runtimePrev = $runtimeFile . '.prev';
$runtimeSignal = $configDir . '/.reload';
$watcherCommand = 'SUBSCRIBE_DIR=' . escapeshellarg($configDir)
    . ' LOG_DIR=' . escapeshellarg($root)
    . ' NGINX_BIN=' . escapeshellarg($fakeNginx)
    . ' RUN_ONCE=1 bash ' . escapeshellarg($watcher);

file_put_contents($runtimeFile, "candidate invalid\n");
file_put_contents($runtimePrev, "stable runtime\n");
file_put_contents($runtimeSignal, "blacklist.conf\n");
$lines = [];
$code = 0;
exec('FAKE_NGINX_FAIL_RELOAD=1 ' . $watcherCommand . ' 2>&1', $lines, $code);
reload_expect($code === 0, 'watcher 单次失败处理不应异常退出');
reload_expect(file_get_contents($runtimeFile) === "stable runtime\n", '通用规则应用失败后没有恢复上一版');
reload_expect(!file_exists($runtimePrev) && !file_exists($runtimeSignal), '通用规则回滚后没有清理备份或信号');

file_put_contents($runtimeFile, "candidate valid\n");
file_put_contents($runtimePrev, "stable runtime\n");
file_put_contents($runtimeSignal, "blacklist.conf\n");
$lines = [];
$code = 0;
exec($watcherCommand . ' 2>&1', $lines, $code);
reload_expect($code === 0, 'watcher 单次成功处理失败');
reload_expect(file_get_contents($runtimeFile) === "candidate valid\n", '通用规则成功应用后不应恢复旧文件');
reload_expect(!file_exists($runtimePrev) && !file_exists($runtimeSignal), '通用规则成功应用后没有清理备份或信号');

foreach (glob($configDir . '/*') ?: [] as $path) @unlink($path);
@unlink($fakeNginx);
@rmdir($configDir);
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, "运行时规则回滚测试失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "运行时规则回滚测试通过：白名单和通用规则的成功应用、Nginx 失败恢复均已验证。\n";
