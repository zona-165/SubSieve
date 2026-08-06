<?php
declare(strict_types=1);

$tmp = tempnam(sys_get_temp_dir(), 'subsieve-intel-queue-');
if ($tmp === false) throw new RuntimeException('无法创建临时文件');
file_put_contents($tmp, '{}');
define('IP_INTEL_QUEUE_JSON', $tmp);
require_once dirname(__DIR__) . '/admin/src/lib/ip_intel_queue.php';

function check_intel(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

check_intel(ip_intel_enqueue(['8.8.8.8', '1.1.1.1', '127.0.0.1', 'invalid']) === 2, '仅应接受公网 IP');
$first = ip_intel_take(1);
check_intel(count($first) === 1 && in_array($first[0], ['8.8.8.8', '1.1.1.1'], true), '应取出最早的一条队列记录');
$second = ip_intel_take(5);
check_intel(count($second) === 1 && $second[0] !== $first[0], '应保留并取出另一条记录');
check_intel(ip_intel_take(5) === [], '取完后队列应为空');

$fresh = ['ts' => time(), 'data' => ['intel_version' => 2, 'confidence' => '高', 'source_count' => 4]];
$stale = ['ts' => time() - 700000, 'data' => ['intel_version' => 2, 'confidence' => '高', 'source_count' => 4]];
check_intel(is_ip_intel_cache_fresh($fresh), '新鲜高置信缓存应可复用');
check_intel(!is_ip_intel_cache_fresh($stale), '超过七天的缓存应刷新');

@unlink($tmp);
echo "IP 情报队列测试通过\n";
