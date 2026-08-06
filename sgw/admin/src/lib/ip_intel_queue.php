<?php
declare(strict_types=1);

function ip_intel_is_public(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function is_ip_intel_cache_fresh($entry): bool {
    if (!is_array($entry)) return false;
    $age = time() - (int)($entry['ts'] ?? 0);
    $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
    $failed = !empty($data['query_failed'])
        || ($data['location'] ?? '') === '查询失败'
        || in_array('情报查询失败', is_array($data['tags'] ?? null) ? $data['tags'] : [], true);
    if (!$failed && (int)($data['intel_version'] ?? 0) < 2) return false;
    if ($failed) $ttl = 600;
    elseif (($data['confidence'] ?? '') === '高' && (int)($data['source_count'] ?? 0) >= 4) $ttl = 604800;
    elseif (($data['confidence'] ?? '') === '高') $ttl = 259200;
    elseif (($data['confidence'] ?? '') === '中') $ttl = 86400;
    else $ttl = 21600;
    return $age >= 0 && $age < $ttl;
}

function ip_intel_enqueue(array $ips): int {
    $valid = [];
    foreach ($ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip !== '' && ip_intel_is_public($ip)) $valid[$ip] = time();
    }
    if (!$valid) return 0;

    $fh = @fopen(IP_INTEL_QUEUE_JSON, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) fclose($fh);
        return 0;
    }
    $queue = ip_intel_read_queue_handle($fh);
    foreach ($valid as $ip => $ts) {
        if (!isset($queue[$ip])) $queue[$ip] = $ts;
    }
    if (count($queue) > 1000) {
        asort($queue, SORT_NUMERIC);
        $queue = array_slice($queue, -1000, null, true);
    }
    ip_intel_write_queue_handle($fh, $queue);
    flock($fh, LOCK_UN);
    fclose($fh);
    return count($valid);
}

function ip_intel_take(int $limit): array {
    $limit = max(1, min(20, $limit));
    $fh = @fopen(IP_INTEL_QUEUE_JSON, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) fclose($fh);
        return [];
    }
    $queue = ip_intel_read_queue_handle($fh);
    asort($queue, SORT_NUMERIC);
    $ips = array_slice(array_keys($queue), 0, $limit);
    foreach ($ips as $ip) unset($queue[$ip]);
    ip_intel_write_queue_handle($fh, $queue);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ips;
}

function ip_intel_read_queue_handle($fh): array {
    rewind($fh);
    $raw = stream_get_contents($fh);
    $queue = $raw !== false && $raw !== '' ? json_decode($raw, true) : [];
    return is_array($queue) ? $queue : [];
}

function ip_intel_write_queue_handle($fh, array $queue): void {
    $json = json_encode($queue, JSON_UNESCAPED_UNICODE);
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, $json === false ? '{}' : $json);
    fflush($fh);
}
