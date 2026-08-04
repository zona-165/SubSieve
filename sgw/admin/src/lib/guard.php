<?php

function guard_default_settings(): array {
    return [
        'guard_observe_enabled' => 1,
        'guard_ip_per_minute' => 30,
        'guard_token_per_minute' => 20,
        'guard_token_hour_ips' => 8,
        'guard_ip_hour_tokens' => 20,
        'guard_ip_404_5m' => 40,
        'guard_scan_lines' => 30000,
    ];
}

function guard_normalize_settings(array $settings): array {
    $defaults = guard_default_settings();
    $ranges = [
        'guard_ip_per_minute' => [5, 5000],
        'guard_token_per_minute' => [5, 5000],
        'guard_token_hour_ips' => [2, 500],
        'guard_ip_hour_tokens' => [2, 1000],
        'guard_ip_404_5m' => [5, 5000],
        'guard_scan_lines' => [1000, 100000],
    ];

    $result = $defaults;
    $result['guard_observe_enabled'] = array_key_exists('guard_observe_enabled', $settings)
        ? (!empty($settings['guard_observe_enabled']) ? 1 : 0)
        : 1;
    foreach ($ranges as $key => [$min, $max]) {
        $value = is_numeric($settings[$key] ?? null) ? (int)$settings[$key] : $defaults[$key];
        $result[$key] = max($min, min($max, $value));
    }
    return $result;
}

function guard_token_fingerprint(string $token, string $secret): string {
    $key = $secret !== '' ? $secret : 'SubSieve-Guard';
    return 'TKN-' . strtoupper(substr(hash_hmac('sha256', $token, $key), 0, 16));
}

function guard_finding_key(string $kind, string $subject, string $secret): string {
    $key = $secret !== '' ? $secret : 'SubSieve-Guard';
    return $kind . ':' . substr(hash_hmac('sha256', $kind . '|' . $subject, $key), 0, 24);
}

function guard_parse_log_line(string $line): ?array {
    $pattern = '/^(\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)"$/';
    if (!preg_match($pattern, trim($line), $match)) return null;

    $date = DateTime::createFromFormat('d/M/Y:H:i:s O', $match[2]);
    if (!$date) return null;
    $request = $match[3];
    $parts = explode(' ', trim($request));
    $target = $parts[1] ?? '';
    $path = parse_url($target, PHP_URL_PATH) ?: '';
    $token = '';
    if (preg_match('/[?&]token=([^&\s"]+)/i', $target, $tokenMatch)) {
        $token = rawurldecode($tokenMatch[1]);
    }

    return [
        'ip' => $match[1],
        'time' => $match[2],
        'ts' => $date->getTimestamp(),
        'request' => $request,
        'path' => $path,
        'status' => (int)$match[4],
        'bytes' => (int)$match[5],
        'ua' => $match[6],
        'token' => $token,
    ];
}

function guard_path_matches(string $path, string $subscribePath): bool {
    $subscribePath = '/' . ltrim(trim($subscribePath), '/');
    if ($subscribePath === '/') return true;
    return $path === rtrim($subscribePath, '/') || str_starts_with($path, rtrim($subscribePath, '/') . '/');
}

function guard_analyze_logs(iterable $lines, array $settings, int $now, string $subscribePath, string $secret): array {
    $rules = guard_normalize_settings($settings);
    $today = date('Y-m-d', $now);
    $todayIps = [];
    $todayTokens = [];
    $statusCounts = [];
    $ipMinute = [];
    $tokenMinute = [];
    $tokenHourIps = [];
    $ipHourTokens = [];
    $ip404 = [];
    $lastSeen = [];
    $todayRequests = 0;
    $todayBytes = 0;
    $observed = 0;
    $lastEvent = 0;

    foreach ($lines as $line) {
        $entry = guard_parse_log_line((string)$line);
        if (!$entry || !guard_path_matches($entry['path'], $subscribePath)) continue;
        $observed++;
        $lastEvent = max($lastEvent, $entry['ts']);
        $age = $now - $entry['ts'];
        if ($age < -300) continue;

        $ip = $entry['ip'];
        $tokenRef = $entry['token'] !== '' ? guard_token_fingerprint($entry['token'], $secret) : '';
        if (date('Y-m-d', $entry['ts']) === $today) {
            $todayRequests++;
            $todayBytes += max(0, $entry['bytes']);
            $todayIps[$ip] = true;
            if ($tokenRef !== '') $todayTokens[$tokenRef] = true;
            $statusCounts[$entry['status']] = ($statusCounts[$entry['status']] ?? 0) + 1;
        }

        if ($age >= 0 && $age <= 60) {
            $ipMinute[$ip] = ($ipMinute[$ip] ?? 0) + 1;
            $lastSeen['ip:' . $ip] = max($lastSeen['ip:' . $ip] ?? 0, $entry['ts']);
            if ($tokenRef !== '') {
                $tokenMinute[$tokenRef] = ($tokenMinute[$tokenRef] ?? 0) + 1;
                $lastSeen['token:' . $tokenRef] = max($lastSeen['token:' . $tokenRef] ?? 0, $entry['ts']);
            }
        }
        if ($age >= 0 && $age <= 3600 && $tokenRef !== '') {
            $tokenHourIps[$tokenRef][$ip] = true;
            $ipHourTokens[$ip][$tokenRef] = true;
            $lastSeen['token_ips:' . $tokenRef] = max($lastSeen['token_ips:' . $tokenRef] ?? 0, $entry['ts']);
            $lastSeen['ip_tokens:' . $ip] = max($lastSeen['ip_tokens:' . $ip] ?? 0, $entry['ts']);
        }
        if ($age >= 0 && $age <= 300 && $entry['status'] === 404) {
            $ip404[$ip] = ($ip404[$ip] ?? 0) + 1;
            $lastSeen['404:' . $ip] = max($lastSeen['404:' . $ip] ?? 0, $entry['ts']);
        }
    }

    $findings = [];
    if (!empty($rules['guard_observe_enabled'])) {
        foreach ($ipMinute as $ip => $count) {
            if ($count < $rules['guard_ip_per_minute']) continue;
            $findings[] = guard_finding(
                'ip_rate', $ip, '单 IP 高频拉取', $count, $rules['guard_ip_per_minute'], '1 分钟',
                '同一来源在一分钟内重复访问订阅入口。', $lastSeen['ip:' . $ip] ?? $now, $secret
            );
        }
        foreach ($tokenMinute as $tokenRef => $count) {
            if ($count < $rules['guard_token_per_minute']) continue;
            $findings[] = guard_finding(
                'token_rate', $tokenRef, '单 Token 高频拉取', $count, $rules['guard_token_per_minute'], '1 分钟',
                '同一 Token 指纹在一分钟内被重复拉取。', $lastSeen['token:' . $tokenRef] ?? $now, $secret
            );
        }
        foreach ($tokenHourIps as $tokenRef => $ips) {
            $count = count($ips);
            if ($count < $rules['guard_token_hour_ips']) continue;
            $findings[] = guard_finding(
                'token_multi_ip', $tokenRef, 'Token 跨多 IP 拉取', $count, $rules['guard_token_hour_ips'], '1 小时',
                '同一 Token 指纹在一小时内出现多个来源 IP。', $lastSeen['token_ips:' . $tokenRef] ?? $now, $secret,
                ['sample_ips' => array_slice(array_keys($ips), 0, 8)]
            );
        }
        foreach ($ipHourTokens as $ip => $tokens) {
            $count = count($tokens);
            if ($count < $rules['guard_ip_hour_tokens']) continue;
            $findings[] = guard_finding(
                'ip_multi_token', $ip, 'IP 拉取多个 Token', $count, $rules['guard_ip_hour_tokens'], '1 小时',
                '同一来源 IP 在一小时内拉取了多个 Token 指纹。', $lastSeen['ip_tokens:' . $ip] ?? $now, $secret
            );
        }
        foreach ($ip404 as $ip => $count) {
            if ($count < $rules['guard_ip_404_5m']) continue;
            $findings[] = guard_finding(
                'ip_404_flood', $ip, '订阅入口 404 洪水', $count, $rules['guard_ip_404_5m'], '5 分钟',
                '同一来源在五分钟内产生大量 404，可能在扫描订阅入口。', $lastSeen['404:' . $ip] ?? $now, $secret
            );
        }
    }

    usort($findings, fn(array $a, array $b) => ($b['score'] <=> $a['score']) ?: ($b['last_seen_ts'] <=> $a['last_seen_ts']));
    $blocked = ($statusCounts[403] ?? 0) + ($statusCounts[429] ?? 0) + ($statusCounts[444] ?? 0);

    return [
        'rules' => $rules,
        'metrics' => [
            'today_requests' => $todayRequests,
            'today_ips' => count($todayIps),
            'today_tokens' => count($todayTokens),
            'today_success' => $statusCounts[200] ?? 0,
            'today_blocked' => $blocked,
            'today_bytes' => $todayBytes,
            'status_counts' => $statusCounts,
            'observed_lines' => $observed,
            'last_event_at' => $lastEvent > 0 ? date('Y-m-d H:i:s', $lastEvent) : '',
        ],
        'findings' => array_slice($findings, 0, 100),
    ];
}

function guard_finding(
    string $kind,
    string $subject,
    string $title,
    int $count,
    int $threshold,
    string $window,
    string $reason,
    int $lastSeen,
    string $secret,
    array $extra = []
): array {
    $ratio = $threshold > 0 ? $count / $threshold : 1;
    $score = min(100, 70 + (int)round(min(1.5, max(0, $ratio - 1)) * 20));
    return array_merge([
        'key' => guard_finding_key($kind, $subject, $secret),
        'kind' => $kind,
        'title' => $title,
        'subject' => $subject,
        'count' => $count,
        'threshold' => $threshold,
        'window' => $window,
        'reason' => $reason,
        'score' => $score,
        'risk' => $score >= 90 ? '高危' : '关注',
        'last_seen_ts' => $lastSeen,
        'last_seen' => date('Y-m-d H:i:s', $lastSeen),
    ], $extra);
}

function guard_tail_log_lines(string $file, int $maxLines): iterable {
    if (!file_exists($file) || $maxLines <= 0) return;
    try {
        $object = new SplFileObject($file, 'r');
        $object->seek(PHP_INT_MAX);
        $last = $object->key();
        $object->seek(max(0, $last - $maxLines + 1));
        while (!$object->eof()) {
            $line = $object->fgets();
            if ($line !== false && trim($line) !== '') yield $line;
        }
    } catch (Throwable $e) {
        $handle = @fopen($file, 'r');
        if (!$handle) return;
        $buffer = [];
        while (($line = fgets($handle)) !== false) {
            $buffer[] = $line;
            if (count($buffer) > $maxLines) array_shift($buffer);
        }
        fclose($handle);
        foreach ($buffer as $line) yield $line;
    }
}

function guard_read_json(string $file): array {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function guard_write_json_atomic(string $file, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0666);
    $ok = @rename($tmp, $file);
    @unlink($tmp);
    return $ok;
}
