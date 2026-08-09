<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/src/config.php';

$failures = [];
function atomic_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

function atomic_source(string $relative): string {
    $path = dirname(__DIR__) . '/' . $relative;
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('无法读取 ' . $path);
    return $source;
}

$root = sys_get_temp_dir() . '/subsieve-atomic-' . bin2hex(random_bytes(5));
mkdir($root, 0770, true);
$first = $root . '/first.conf';
$second = $root . '/second.json';
file_put_contents($first, "old first\n");
file_put_contents($second, "old second\n");

$ok = subsieve_atomic_write_files([
    $first => "new first\n",
    $second => "new second\n",
], 0660);
atomic_expect($ok, '多文件原子写入应成功');
atomic_expect(file_get_contents($first) === "new first\n", '第一个文件未更新');
atomic_expect(file_get_contents($second) === "new second\n", '第二个文件未更新');
clearstatcache(true, $first);
atomic_expect((fileperms($first) & 0777) === 0660, '原子写入没有应用指定权限');

file_put_contents($first, "stable first\n");
file_put_contents($second, "stable second\n");
$replaceCalls = 0;
$replaceWithFailure = static function (string $from, string $to) use (&$replaceCalls): bool {
    $replaceCalls++;
    if ($replaceCalls === 2) return false;
    return rename($from, $to);
};
$rolledBack = subsieve_atomic_write_files([
    $first => "broken first\n",
    $second => "broken second\n",
], 0660, $replaceWithFailure);
atomic_expect(!$rolledBack, '第二个文件替换失败时事务必须失败');
atomic_expect(file_get_contents($first) === "stable first\n", '事务失败后第一个文件没有回滚');
atomic_expect(file_get_contents($second) === "stable second\n", '事务失败后第二个文件被改动');

$beforeStageFailure = file_get_contents($first);
$stageFailure = subsieve_atomic_write_files([
    $first => "must not commit\n",
    $root . '/missing/target.conf' => "cannot stage\n",
]);
atomic_expect(!$stageFailure, '候选文件无法创建时事务必须失败');
atomic_expect(file_get_contents($first) === $beforeStageFailure, '候选写入失败时不应修改已有文件');

$jsonFile = $root . '/settings.json';
atomic_expect(subsieve_atomic_write_json($jsonFile, ['enabled' => true, 'limit' => 10]), 'JSON 原子写入应成功');
$json = json_decode((string)file_get_contents($jsonFile), true);
atomic_expect(($json['enabled'] ?? false) === true && ($json['limit'] ?? 0) === 10, 'JSON 原子写入内容不正确');

$contracts = [
    'admin/src/api/blacklist.php' => 'subsieve_atomic_write_nginx_files',
    'admin/src/api/whitelist.php' => 'subsieve_atomic_write_nginx_files',
    'admin/src/api/ua_blacklist.php' => 'subsieve_atomic_write_nginx_files',
    'admin/src/api/ua_whitelist.php' => 'subsieve_atomic_write_nginx_files',
    'admin/src/api/token_blacklist.php' => 'write_token_blacklist_files',
    'admin/src/api/settings.php' => 'subsieve_atomic_write_json',
    'admin/src/maintenance.php' => 'subsieve_atomic_write_json',
];
foreach ($contracts as $file => $needle) {
    atomic_expect(str_contains(atomic_source($file), $needle), $file . ' 未接入事务写入');
}

$criticalDirectWrites = '/file_put_contents\s*\(\s*(BLACKLIST_JSON|WHITELIST_IPS|UA_BLACKLIST_JSON|UA_WHITELIST_JSON|TOKEN_BLACKLIST_JSON|SETTINGS_JSON|ALERT_HISTORY_JSON|ALERT_STATE_JSON|PROTECT_CONF)/';
foreach (array_keys($contracts) as $file) {
    atomic_expect(!preg_match($criticalDirectWrites, atomic_source($file)), $file . ' 仍直接覆盖关键配置文件');
}

$leftovers = glob($root . '/*') ?: [];
foreach ($leftovers as $path) {
    if (is_file($path)) @unlink($path);
}
@unlink($root . '/.subsieve-write.lock');
@rmdir($root);

if ($failures !== []) {
    fwrite(STDERR, "原子写入测试失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "原子写入测试通过：多文件提交、故障回滚和关键 API 接入均已验证。\n";
