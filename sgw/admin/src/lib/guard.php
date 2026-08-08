<?php

function guard_default_settings(): array {
    return [
        'guard_observe_enabled' => 1,
        'guard_ip_daily_requests' => 100,
        'guard_ip_per_minute' => 30,
        'guard_token_per_minute' => 20,
        'guard_token_hour_ips' => 8,
        'guard_ip_hour_tokens' => 20,
        'guard_ip_404_5m' => 40,
        'guard_scan_lines' => 30000,
        'guard_pull_limit_enabled' => 1,
        'guard_pull_limit_enforce' => 0,
        'guard_pull_limit_24h_ips' => 10,
        'guard_pull_limit_per_minute' => 10,
        'guard_pull_limit_suspend_hours' => 24,
    ];
}

function guard_normalize_settings(array $settings): array {
    $defaults = guard_default_settings();
    $ranges = [
        'guard_ip_daily_requests' => [20, 100000],
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
    $result['guard_pull_limit_enabled'] = array_key_exists('guard_pull_limit_enabled', $settings)
        ? (!empty($settings['guard_pull_limit_enabled']) ? 1 : 0)
        : 1;
    $result['guard_pull_limit_enforce'] = array_key_exists('guard_pull_limit_enforce', $settings)
        ? (!empty($settings['guard_pull_limit_enforce']) ? 1 : 0)
        : 0;
    foreach ($ranges as $key => [$min, $max]) {
        $value = is_numeric($settings[$key] ?? null) ? (int)$settings[$key] : $defaults[$key];
        $result[$key] = max($min, min($max, $value));
    }
    $pullRanges = [
        'guard_pull_limit_24h_ips' => [2, 200],
        'guard_pull_limit_per_minute' => [2, 300],
        'guard_pull_limit_suspend_hours' => [1, 168],
    ];
    foreach ($pullRanges as $key => [$min, $max]) {
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

function guard_add_daily_ip_volume_findings(
    array $findings,
    array $pullIps,
    array $settings,
    int $now,
    string $secret,
    array $whitelistIps = []
): array {
    $rules = guard_normalize_settings($settings);
    if (empty($rules['guard_observe_enabled'])) return $findings;
    $threshold = (int)$rules['guard_ip_daily_requests'];
    $result = [];
    foreach ($findings as $finding) {
        if (!empty($finding['key'])) $result[(string)$finding['key']] = $finding;
    }

    foreach ($pullIps as $row) {
        $ip = trim((string)($row['ip'] ?? ''));
        $count = (int)($row['total'] ?? 0);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $count < $threshold) continue;
        if (isset($whitelistIps[$ip]) || in_array($ip, $whitelistIps, true)) continue;
        $lastSeen = strtotime((string)($row['last_time'] ?? '')) ?: $now;
        $statusCounts = [
            '200' => (int)($row['s200'] ?? 0),
            '403' => (int)($row['s403'] ?? 0),
            '429' => (int)($row['s429'] ?? 0),
            '444' => (int)($row['s444'] ?? 0),
        ];
        $finding = guard_finding(
            'daily_ip_volume',
            $ip,
            '今日单 IP 高频拉取',
            $count,
            $threshold,
            '今日',
            "该 IP 今日累计拉取订阅 {$count} 次，已超过观察阈值 {$threshold} 次。",
            $lastSeen,
            $secret,
            [
                'status_counts' => $statusCounts,
                'token_count' => (int)($row['token_count'] ?? 0),
            ]
        );
        $result[$finding['key']] = $finding;
    }

    $result = array_values($result);
    usort($result, fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($b['last_seen_ts'] <=> $a['last_seen_ts']));
    return array_slice($result, 0, 100);
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

function guard_build_token_investigation(
    iterable $lines,
    string $fingerprint,
    array $settings,
    int $now,
    string $subscribePath,
    string $secret,
    array $intelCache = []
): ?array {
    if (!preg_match('/^TKN-[A-F0-9]{16}$/', $fingerprint)) return null;
    $rules = guard_normalize_settings($settings);
    $cutoff = $now - 86400;
    $rawToken = '';
    $events = [];
    $ips = [];
    $uas = [];
    $statusCounts = [];
    $minuteLocations = [];
    $firstSeen = 0;
    $lastSeen = 0;

    foreach ($lines as $line) {
        $entry = guard_parse_log_line((string)$line);
        if (!$entry || !guard_path_matches($entry['path'], $subscribePath)) continue;
        if ($entry['token'] === '' || $entry['ts'] < $cutoff || $entry['ts'] > $now + 300) continue;
        if (!hash_equals($fingerprint, guard_token_fingerprint($entry['token'], $secret))) continue;

        $rawToken = $entry['token'];
        $firstSeen = $firstSeen === 0 ? $entry['ts'] : min($firstSeen, $entry['ts']);
        $lastSeen = max($lastSeen, $entry['ts']);
        $status = (string)$entry['status'];
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

        if (!isset($ips[$entry['ip']])) {
            $intelEntry = is_array($intelCache[$entry['ip']] ?? null) ? $intelCache[$entry['ip']] : [];
            $intel = is_array($intelEntry['data'] ?? null) ? $intelEntry['data'] : [];
            $failed = !$intel || !empty($intel['query_failed']);
            $ips[$entry['ip']] = [
                'ip' => $entry['ip'],
                'count' => 0,
                'first_seen_ts' => $entry['ts'],
                'last_seen_ts' => $entry['ts'],
                'statuses' => [],
                'location' => $failed ? '等待情报' : (string)($intel['location'] ?? '未知地区'),
                'asn' => $failed ? '未查询' : (string)($intel['asn'] ?? '未查询'),
                'operator' => $failed ? '未查询' : (string)($intel['operator'] ?? '未知运营商'),
                'network_type' => $failed ? '未查询' : (string)($intel['network_type'] ?? '未知网络'),
                'high_risk' => !$failed && (!empty($intel['is_proxy']) || !empty($intel['is_vpn']) || !empty($intel['is_tor']) || !empty($intel['is_hosting'])),
                'intel_pending' => $failed,
            ];
        }
        $ips[$entry['ip']]['count']++;
        $ips[$entry['ip']]['first_seen_ts'] = min($ips[$entry['ip']]['first_seen_ts'], $entry['ts']);
        $ips[$entry['ip']]['last_seen_ts'] = max($ips[$entry['ip']]['last_seen_ts'], $entry['ts']);
        $ips[$entry['ip']]['statuses'][$status] = ($ips[$entry['ip']]['statuses'][$status] ?? 0) + 1;

        $ua = trim((string)$entry['ua']);
        $uaKey = $ua !== '' ? $ua : '（空 UA）';
        if (!isset($uas[$uaKey])) {
            $uas[$uaKey] = ['ua' => $uaKey, 'family' => guard_ua_family($ua), 'count' => 0, 'last_seen_ts' => 0];
        }
        $uas[$uaKey]['count']++;
        $uas[$uaKey]['last_seen_ts'] = max($uas[$uaKey]['last_seen_ts'], $entry['ts']);

        $location = (string)($ips[$entry['ip']]['location'] ?? '');
        if ($location !== '' && !in_array($location, ['等待情报', '未知地区'], true)) {
            $bucket = (int)floor($entry['ts'] / 600);
            $minuteLocations[$bucket][$location] = true;
        }
        $events[] = [
            'time' => date('Y-m-d H:i:s', $entry['ts']),
            'ts' => $entry['ts'],
            'ip' => $entry['ip'],
            'status' => $entry['status'],
            'ua' => $uaKey,
            'ua_family' => guard_ua_family($ua),
            'location' => $location !== '' ? $location : '等待情报',
        ];
    }

    if ($rawToken === '') return null;
    uasort($ips, fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: ($b['last_seen_ts'] <=> $a['last_seen_ts']));
    uasort($uas, fn(array $a, array $b): int => ($b['count'] <=> $a['count']) ?: ($b['last_seen_ts'] <=> $a['last_seen_ts']));
    usort($events, fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);

    $asnValues = [];
    $locationValues = [];
    $highRiskIps = 0;
    $pendingIps = [];
    foreach ($ips as &$row) {
        $row['first_seen'] = date('Y-m-d H:i:s', $row['first_seen_ts']);
        $row['last_seen'] = date('Y-m-d H:i:s', $row['last_seen_ts']);
        unset($row['first_seen_ts'], $row['last_seen_ts']);
        if ($row['asn'] !== '未查询' && $row['asn'] !== '查询失败') $asnValues[$row['asn']] = true;
        if (!in_array($row['location'], ['等待情报', '未知地区', '查询失败'], true)) $locationValues[$row['location']] = true;
        if (!empty($row['high_risk'])) $highRiskIps++;
        if (!empty($row['intel_pending'])) $pendingIps[] = $row['ip'];
    }
    unset($row);
    foreach ($uas as &$row) {
        $row['last_seen'] = date('Y-m-d H:i:s', $row['last_seen_ts']);
        unset($row['last_seen_ts']);
    }
    unset($row);
    $families = [];
    foreach ($uas as $row) $families[$row['family']] = true;
    $shortWindowRegions = false;
    foreach ($minuteLocations as $locations) {
        if (count($locations) >= 2) {
            $shortWindowRegions = true;
            break;
        }
    }

    $uniqueIps = count($ips);
    $uniqueAsns = count($asnValues);
    $uniqueFamilies = count($families);
    $score = 0;
    $evidence = [];
    if ($uniqueIps > $rules['guard_pull_limit_24h_ips']) {
        $score += 40;
        $evidence[] = '24 小时出现 ' . $uniqueIps . ' 个独立 IP，超过规则 ' . $rules['guard_pull_limit_24h_ips'] . ' 个';
    }
    if ($uniqueAsns >= 3) {
        $score += min(20, 8 + $uniqueAsns * 2);
        $evidence[] = '来源覆盖 ' . $uniqueAsns . ' 个 ASN';
    }
    if ($uniqueFamilies >= 3) {
        $score += min(15, 5 + $uniqueFamilies * 2);
        $evidence[] = '出现 ' . $uniqueFamilies . ' 类客户端特征';
    }
    if ($highRiskIps > 0) {
        $score += min(20, 10 + $highRiskIps * 3);
        $evidence[] = $highRiskIps . ' 个来源带有代理、VPN、Tor 或机房信号';
    }
    if ($shortWindowRegions) {
        $score += 20;
        $evidence[] = '10 分钟窗口内出现多个不同地区，建议人工复核';
    }
    $score = min(100, $score);
    if (!$evidence) $evidence[] = '当前 24 小时证据未发现显著共享特征';

    return [
        'fingerprint' => $fingerprint,
        'raw_token' => $rawToken,
        'summary' => [
            'requests_24h' => array_sum($statusCounts),
            'unique_ips' => $uniqueIps,
            'unique_asns' => $uniqueAsns,
            'unique_locations' => count($locationValues),
            'ua_families' => $uniqueFamilies,
            'first_seen' => $firstSeen > 0 ? date('Y-m-d H:i:s', $firstSeen) : '',
            'last_seen' => $lastSeen > 0 ? date('Y-m-d H:i:s', $lastSeen) : '',
            'score' => $score,
            'risk' => $score >= 70 ? '高风险' : ($score >= 40 ? '需复核' : '低风险'),
            'status_counts' => $statusCounts,
        ],
        'evidence' => $evidence,
        'ips' => array_slice(array_values($ips), 0, 30),
        'uas' => array_slice(array_values($uas), 0, 15),
        'events' => array_slice($events, 0, 50),
        'pending_intel_ips' => $pendingIps,
    ];
}

function guard_ua_family(string $ua): string {
    $value = strtolower(trim($ua));
    if ($value === '') return '空 UA';
    $families = [
        'shadowrocket' => 'Shadowrocket',
        'clash' => 'Clash',
        'stash' => 'Stash',
        'sing-box' => 'sing-box',
        'surge' => 'Surge',
        'quantumult' => 'Quantumult',
        'v2ray' => 'V2Ray',
        'curl' => 'curl',
        'wget' => 'wget',
        'python' => 'Python',
        'mozilla/' => '浏览器',
    ];
    foreach ($families as $needle => $label) {
        if (str_contains($value, $needle)) return $label;
    }
    $first = preg_split('/[\s\/]+/', trim($ua), 2)[0] ?? '其他客户端';
    return substr($first !== '' ? $first : '其他客户端', 0, 40);
}

function guard_analyze_pull_limits(
    iterable $lines,
    array $settings,
    int $now,
    string $subscribePath,
    string $secret,
    array $storedState = [],
    bool $mutate = false
): array {
    $rules = guard_normalize_settings($settings);
    $usage = [];
    $cutoff = $now - 86400;
    $state = is_array($storedState) ? $storedState : [];
    $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
    $history = is_array($state['history'] ?? null) ? $state['history'] : [];
    $resetCutoffs = [];
    foreach ($history as $entry) {
        $fingerprint = (string)($entry['fingerprint'] ?? '');
        if ($fingerprint === '') continue;
        $releasedTs = strtotime((string)($entry['released_at'] ?? '')) ?: 0;
        $status = (string)($entry['status'] ?? 'expired');
        $resetTs = in_array($status, ['released', 'disabled'], true)
            ? $releasedTs
            : max((int)($entry['until_ts'] ?? 0), $releasedTs);
        $resetCutoffs[$fingerprint] = max(
            $resetCutoffs[$fingerprint] ?? 0,
            $resetTs
        );
    }
    foreach ($entries as $entry) {
        $fingerprint = (string)($entry['fingerprint'] ?? '');
        if ($fingerprint === '') continue;
        $resetCutoffs[$fingerprint] = max($resetCutoffs[$fingerprint] ?? 0, (int)($entry['until_ts'] ?? 0));
    }

    foreach ($lines as $line) {
        $entry = guard_parse_log_line((string)$line);
        if (!$entry || !guard_path_matches($entry['path'], $subscribePath)) continue;
        if ($entry['token'] === '' || $entry['ts'] < $cutoff || $entry['ts'] > $now + 300) continue;

        $fingerprint = guard_token_fingerprint($entry['token'], $secret);
        if (!isset($usage[$fingerprint])) {
            $usage[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'raw_token' => $entry['token'],
                'ips' => [],
                'minute_buckets' => [],
                'rule_ips' => [],
                'rule_minute_buckets' => [],
                'requests_24h' => 0,
                'last_seen_ts' => 0,
            ];
        }
        $bucket = (int)floor($entry['ts'] / 60);
        $usage[$fingerprint]['ips'][$entry['ip']] = true;
        $usage[$fingerprint]['minute_buckets'][$bucket] = ($usage[$fingerprint]['minute_buckets'][$bucket] ?? 0) + 1;
        if ($entry['ts'] > ($resetCutoffs[$fingerprint] ?? 0)) {
            $usage[$fingerprint]['rule_ips'][$entry['ip']] = true;
            $usage[$fingerprint]['rule_minute_buckets'][$bucket] = ($usage[$fingerprint]['rule_minute_buckets'][$bucket] ?? 0) + 1;
        }
        $usage[$fingerprint]['requests_24h']++;
        $usage[$fingerprint]['last_seen_ts'] = max($usage[$fingerprint]['last_seen_ts'], $entry['ts']);
    }

    foreach ($entries as $fingerprint => $entry) {
        if ((int)($entry['until_ts'] ?? 0) > $now) continue;
        if ($mutate) {
            $entry['status'] = 'expired';
            $entry['released_at'] = date('Y-m-d H:i:s', $now);
            array_unshift($history, $entry);
            unset($entries[$fingerprint]);
        }
    }

    if ($mutate && (empty($rules['guard_pull_limit_enabled']) || empty($rules['guard_pull_limit_enforce']))) {
        foreach ($entries as $entry) {
            $entry['status'] = 'disabled';
            $entry['released_at'] = date('Y-m-d H:i:s', $now);
            array_unshift($history, $entry);
        }
        $entries = [];
    }

    $rows = [];
    $pending = 0;
    $maxIps = 0;
    $maxMinute = 0;
    $maxRuleIps = 0;
    $maxRuleMinute = 0;
    foreach ($usage as $fingerprint => $item) {
        $ipCount = count($item['ips']);
        $peakMinute = empty($item['minute_buckets']) ? 0 : max($item['minute_buckets']);
        $ruleIpCount = count($item['rule_ips']);
        $rulePeakMinute = empty($item['rule_minute_buckets']) ? 0 : max($item['rule_minute_buckets']);
        $violations = [];
        if ($ruleIpCount > $rules['guard_pull_limit_24h_ips']) $violations[] = '24h_unique_ips';
        if ($rulePeakMinute > $rules['guard_pull_limit_per_minute']) $violations[] = 'minute_rate';
        if ($violations) $pending++;

        $active = isset($entries[$fingerprint]) && (int)($entries[$fingerprint]['until_ts'] ?? 0) > $now;
        if ($mutate && !$active && $violations && !empty($rules['guard_pull_limit_enabled']) && !empty($rules['guard_pull_limit_enforce'])) {
            $until = $now + ($rules['guard_pull_limit_suspend_hours'] * 3600);
            $entries[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'status' => 'suspended',
                'reasons' => $violations,
                'started_ts' => $now,
                'started_at' => date('Y-m-d H:i:s', $now),
                'until_ts' => $until,
                'until' => date('Y-m-d H:i:s', $until),
                'unique_ips_24h' => $ruleIpCount,
                'peak_per_minute' => $rulePeakMinute,
                'requests_24h' => $item['requests_24h'],
                'last_seen' => date('Y-m-d H:i:s', $item['last_seen_ts']),
            ];
            $active = true;
        }

        $rows[] = [
            'fingerprint' => $fingerprint,
            'unique_ips_24h' => $ipCount,
            'rule_unique_ips' => $ruleIpCount,
            'requests_24h' => $item['requests_24h'],
            'peak_per_minute' => $peakMinute,
            'rule_peak_per_minute' => $rulePeakMinute,
            'rule_since' => ($resetCutoffs[$fingerprint] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$resetCutoffs[$fingerprint]) : '',
            'last_seen' => $item['last_seen_ts'] > 0 ? date('Y-m-d H:i:s', $item['last_seen_ts']) : '',
            'last_seen_ts' => $item['last_seen_ts'],
            'violations' => $violations,
            'would_suspend' => !empty($violations),
            'suspended' => $active,
            'suspended_until' => $active ? (string)($entries[$fingerprint]['until'] ?? '') : '',
            '_raw_token' => $item['raw_token'],
        ];
        $maxIps = max($maxIps, $ipCount);
        $maxMinute = max($maxMinute, $peakMinute);
        $maxRuleIps = max($maxRuleIps, $ruleIpCount);
        $maxRuleMinute = max($maxRuleMinute, $rulePeakMinute);
    }

    usort($rows, fn(array $a, array $b) => ((int)$b['suspended'] <=> (int)$a['suspended'])
        ?: ($b['unique_ips_24h'] <=> $a['unique_ips_24h'])
        ?: ($b['peak_per_minute'] <=> $a['peak_per_minute'])
        ?: ($b['requests_24h'] <=> $a['requests_24h']));

    $state = [
        'updated_at' => date('Y-m-d H:i:s', $now),
        'updated_ts' => $now,
        'entries' => $entries,
        'history' => array_slice($history, 0, 100),
    ];

    return [
        'settings' => [
            'enabled' => (bool)$rules['guard_pull_limit_enabled'],
            'enforce' => (bool)$rules['guard_pull_limit_enforce'],
            'max_ips_24h' => $rules['guard_pull_limit_24h_ips'],
            'max_per_minute' => $rules['guard_pull_limit_per_minute'],
            'suspend_hours' => $rules['guard_pull_limit_suspend_hours'],
        ],
        'summary' => [
            'active_tokens' => count($usage),
            'suspended_tokens' => count($entries),
            'pending_violations' => $pending,
            'max_unique_ips_24h' => $maxIps,
            'max_per_minute' => $maxMinute,
            'max_rule_unique_ips' => $maxRuleIps,
            'max_rule_per_minute' => $maxRuleMinute,
        ],
        'usage' => array_slice($rows, 0, 20),
        'updated_at' => date('Y-m-d H:i:s', $now),
        '_state' => $state,
        '_all_usage' => $rows,
    ];
}

function guard_refresh_pull_limits(?array $settings = null): array {
    $settings = $settings ?? guard_read_json(SETTINGS_JSON);
    $rules = guard_normalize_settings($settings);
    $subscribePath = trim((string)($settings['subscribe_path'] ?? '/api/v1/client/subscribe'));
    if ($subscribePath === '') $subscribePath = '/api/v1/client/subscribe';
    $secret = function_exists('guard_secret') ? guard_secret() : guard_runtime_secret();
    $lines = iterator_to_array(guard_tail_log_lines(LOG_FILE, $rules['guard_scan_lines']), false);
    $result = guard_analyze_pull_limits(
        $lines,
        $settings,
        time(),
        $subscribePath,
        $secret,
        guard_read_json(TOKEN_LIMIT_STATE_JSON),
        true
    );

    $state = $result['_state'];
    $activeTokens = [];
    foreach ($result['_all_usage'] as $row) {
        if (!empty($row['suspended']) && !empty($row['_raw_token'])) {
            $activeTokens[$row['fingerprint']] = $row['_raw_token'];
        }
    }
    $changedFiles = [];
    if (guard_write_if_changed(TOKEN_LIMIT_CONF, guard_token_limit_map_content($state['entries'], $activeTokens))) $changedFiles[] = TOKEN_LIMIT_CONF;
    if (guard_write_if_changed(TOKEN_LIMIT_RATE_CONF, guard_token_limit_rate_content($rules))) $changedFiles[] = TOKEN_LIMIT_RATE_CONF;
    if (guard_write_if_changed(TOKEN_LIMIT_APPLY_CONF, guard_token_limit_apply_content($rules))) $changedFiles[] = TOKEN_LIMIT_APPLY_CONF;
    guard_write_json_atomic(TOKEN_LIMIT_STATE_JSON, $state);
    $changed = count($changedFiles) > 0;
    if ($changed && function_exists('nginx_reload')) guard_signal_token_limit_reload($changedFiles);

    foreach ($result['usage'] as &$row) unset($row['_raw_token']);
    unset($row);
    unset($result['_state'], $result['_all_usage']);
    $result['config_changed'] = $changed;
    return $result;
}

function guard_release_pull_limit(string $fingerprint): bool {
    if (!preg_match('/^TKN-[A-F0-9]{16}$/', $fingerprint)) return false;
    $state = guard_read_json(TOKEN_LIMIT_STATE_JSON);
    $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
    if (!isset($entries[$fingerprint])) return false;
    $entry = $entries[$fingerprint];
    unset($entries[$fingerprint]);
    $entry['status'] = 'released';
    $entry['released_at'] = date('Y-m-d H:i:s');
    $history = is_array($state['history'] ?? null) ? $state['history'] : [];
    array_unshift($history, $entry);
    $state['entries'] = $entries;
    $state['history'] = array_slice($history, 0, 100);
    $state['updated_at'] = date('Y-m-d H:i:s');
    $state['updated_ts'] = time();
    guard_write_json_atomic(TOKEN_LIMIT_STATE_JSON, $state);
    $changed = guard_write_if_changed(TOKEN_LIMIT_CONF, guard_token_limit_map_content($entries, []));
    if ($changed && function_exists('nginx_reload')) guard_signal_token_limit_reload([TOKEN_LIMIT_CONF]);
    if (function_exists('invalidate_guard_cache')) invalidate_guard_cache();
    return true;
}

function guard_token_limit_map_content(array $entries, array $rawTokens): string {
    $existing = guard_existing_token_limit_lines();
    $lines = [
        '# Temporary Token suspensions - generated by SubSieve',
        'map $arg_token $is_token_temporarily_suspended {',
        '    default 0;',
    ];
    foreach ($entries as $fingerprint => $entry) {
        if ((int)($entry['until_ts'] ?? 0) <= time()) continue;
        if (isset($rawTokens[$fingerprint])) {
            $token = trim((string)$rawTokens[$fingerprint]);
            if ($token === '' || strlen($token) > 512 || preg_match('/[\x00-\x1F\x7F]/', $token)) continue;
            $pattern = preg_quote($token, '~');
            $pattern = str_replace(['\\', '"'], ['\\\\', '\\"'], $pattern);
            $line = '    "~^' . $pattern . '$" 1;';
        } elseif (isset($existing[$fingerprint])) {
            $line = $existing[$fingerprint];
        } else {
            continue;
        }
        $lines[] = '    # ' . $fingerprint;
        $lines[] = $line;
    }
    $lines[] = '}';
    return implode("\n", $lines) . "\n";
}

function guard_existing_token_limit_lines(): array {
    if (!file_exists(TOKEN_LIMIT_CONF)) return [];
    $result = [];
    $fingerprint = '';
    foreach (file(TOKEN_LIMIT_CONF, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*#\s*(TKN-[A-F0-9]{16})\s*$/', $line, $match)) {
            $fingerprint = $match[1];
            continue;
        }
        if ($fingerprint !== '' && preg_match('/^\s+"~\^.*\$"\s+1;\s*$/', $line)) {
            $result[$fingerprint] = $line;
            $fingerprint = '';
        }
    }
    return $result;
}

function guard_token_limit_rate_content(array $rules): string {
    $enabled = !empty($rules['guard_pull_limit_enabled']) && !empty($rules['guard_pull_limit_enforce']);
    $rate = max(2, min(300, (int)$rules['guard_pull_limit_per_minute']));
    $lines = [
        '# Token pull rate - generated by SubSieve',
        'map "$whitelist_ip:$arg_token" $token_pull_rate_key {',
        '    default "";',
    ];
    if ($enabled) $lines[] = '    "~^0:.+$" $arg_token;';
    $lines[] = '}';
    $lines[] = 'limit_req_zone $token_pull_rate_key zone=token_pull_limit:10m rate=' . $rate . 'r/m;';
    return implode("\n", $lines) . "\n";
}

function guard_token_limit_apply_content(array $rules): string {
    $burst = max(1, min(299, (int)$rules['guard_pull_limit_per_minute'] - 1));
    return '# Token pull rate application - generated by SubSieve' . "\n"
        . 'limit_req zone=token_pull_limit burst=' . $burst . ' nodelay;' . "\n";
}

function guard_write_if_changed(string $file, string $content): bool {
    $current = null;
    if (file_exists($file)) {
        $current = @file_get_contents($file);
        if ($current === false) throw new RuntimeException('cannot read ' . basename($file));
        if (hash_equals(hash('sha256', $current), hash('sha256', $content))) {
            guard_prepare_shared_config($file);
            return false;
        }
    }
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('cannot write ' . basename($file));
    guard_prepare_shared_config($tmp);
    if ($current !== null) {
        $backup = $file . '.prev';
        $backupTmp = $backup . '.tmp.' . getmypid();
        if (@file_put_contents($backupTmp, $current, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('cannot backup ' . basename($file));
        }
        guard_prepare_shared_config($backupTmp);
        if (!@rename($backupTmp, $backup)) {
            @unlink($tmp);
            @unlink($backupTmp);
            throw new RuntimeException('cannot backup ' . basename($file));
        }
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('cannot replace ' . basename($file));
    }
    guard_prepare_shared_config($file);
    return true;
}

function guard_prepare_shared_config(string $file): void {
    @chown($file, 'www-data');
    @chgrp($file, 'www-data');
    @chmod($file, 0660);
}

function guard_signal_token_limit_reload(array $files): void {
    $allowed = [TOKEN_LIMIT_CONF, TOKEN_LIMIT_RATE_CONF, TOKEN_LIMIT_APPLY_CONF];
    $names = [];
    foreach ($files as $file) {
        if (in_array($file, $allowed, true)) $names[] = basename($file);
    }
    if (!$names) return;
    guard_write_if_changed(TOKEN_LIMIT_RELOAD_MARKER, implode("\n", array_unique($names)) . "\n");
    nginx_reload();
}

function guard_runtime_secret(): string {
    $secret = file_exists(GUARD_SECRET_FILE) ? trim((string)@file_get_contents(GUARD_SECRET_FILE)) : '';
    return preg_match('/^[a-f0-9]{64}$/', $secret) ? $secret : hash('sha256', 'SubSieve-Guard');
}
