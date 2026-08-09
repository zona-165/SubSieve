<?php

function traffic_default_settings(): array {
    return [
        'traffic_monitor_enabled' => 1,
        'traffic_analysis_enabled' => 1,
        'traffic_report_path' => '/api/v1/server/UniProxy',
        'traffic_scan_lines' => 20000,
        'traffic_user_5m_gb' => 10,
        'traffic_user_1h_gb' => 50,
        'traffic_user_24h_gb' => 100,
        'traffic_user_hour_ips' => 10,
        'traffic_upload_ratio' => 5,
        'traffic_upload_min_gb' => 1,
        'traffic_report_5m_requests' => 120,
        'traffic_correlation_minutes' => 15,
    ];
}

function traffic_normalize_settings(array $settings): array {
    $defaults = traffic_default_settings();
    $result = $defaults;
    $result['traffic_monitor_enabled'] = array_key_exists('traffic_monitor_enabled', $settings)
        ? (!empty($settings['traffic_monitor_enabled']) ? 1 : 0)
        : 1;
    $result['traffic_analysis_enabled'] = array_key_exists('traffic_analysis_enabled', $settings)
        ? (!empty($settings['traffic_analysis_enabled']) ? 1 : 0)
        : 1;

    $path = trim((string)($settings['traffic_report_path'] ?? $defaults['traffic_report_path']));
    if ($path === '' || !str_starts_with($path, '/') || preg_match('/[\s{};#?]/', $path)) {
        $path = $defaults['traffic_report_path'];
    }
    $result['traffic_report_path'] = rtrim($path, '/') ?: $defaults['traffic_report_path'];

    $ranges = [
        'traffic_scan_lines' => [1000, 100000],
        'traffic_user_5m_gb' => [1, 10000],
        'traffic_user_1h_gb' => [1, 50000],
        'traffic_user_24h_gb' => [1, 100000],
        'traffic_user_hour_ips' => [2, 500],
        'traffic_upload_ratio' => [2, 100],
        'traffic_upload_min_gb' => [1, 10000],
        'traffic_report_5m_requests' => [10, 10000],
        'traffic_correlation_minutes' => [1, 180],
    ];
    foreach ($ranges as $key => [$min, $max]) {
        $value = is_numeric($settings[$key] ?? null) ? (int)$settings[$key] : $defaults[$key];
        $result[$key] = max($min, min($max, $value));
    }
    return $result;
}

function traffic_user_fingerprint(string $userId, string $secret): string {
    $key = $secret !== '' ? $secret : 'SubSieve-Traffic';
    return 'USR-' . strtoupper(substr(hash_hmac('sha256', $userId, $key), 0, 16));
}

function traffic_path_matches(string $uri, string $basePath): bool {
    $base = rtrim('/' . ltrim($basePath, '/'), '/');
    return $uri === $base || str_starts_with($uri, $base . '/');
}

function traffic_parse_log_line(string $line, string $basePath, string $secret): ?array {
    $outer = json_decode(trim($line), true);
    if (!is_array($outer)) return null;
    $uri = (string)($outer['uri'] ?? '');
    if (!traffic_path_matches($uri, $basePath)) return null;

    $ts = strtotime((string)($outer['time'] ?? '')) ?: 0;
    if ($ts <= 0) return null;
    $action = strtolower((string)basename($uri));
    if (!in_array($action, ['push', 'alive'], true)) $action = 'other';

    $payload = [];
    $body = (string)($outer['body'] ?? '');
    $bodyValid = true;
    if ($body !== '' && $body !== '-') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) $payload = $decoded;
        else $bodyValid = false;
    }

    $users = [];
    if ($bodyValid && in_array($action, ['push', 'alive'], true)) {
        $seen = 0;
        foreach ($payload as $userId => $value) {
            if (++$seen > 5000 || (!is_string($userId) && !is_int($userId))) break;
            $fingerprint = traffic_user_fingerprint((string)$userId, $secret);
            if ($action === 'push') {
                $upload = is_array($value) ? ($value['upload'] ?? $value[0] ?? 0) : 0;
                $download = is_array($value) ? ($value['download'] ?? $value[1] ?? 0) : 0;
                $users[$fingerprint] = [
                    'upload' => traffic_positive_int($upload),
                    'download' => traffic_positive_int($download),
                ];
            } else {
                $ips = [];
                foreach (is_array($value) ? $value : [] as $ip) {
                    $ip = trim((string)$ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[$ip] = true;
                    if (count($ips) >= 100) break;
                }
                $users[$fingerprint] = ['ips' => array_keys($ips)];
            }
        }
    }

    return [
        'ts' => $ts,
        'ip' => trim((string)($outer['ip'] ?? '')),
        'method' => strtoupper((string)($outer['method'] ?? '')),
        'uri' => $uri,
        'action' => $action,
        'status' => (int)($outer['status'] ?? 0),
        'users' => $users,
        'body_valid' => $bodyValid,
    ];
}

function traffic_positive_int(mixed $value): int {
    if (!is_numeric($value)) return 0;
    $number = (float)$value;
    if (!is_finite($number) || $number <= 0) return 0;
    return (int)min((float)PHP_INT_MAX, floor($number));
}

function traffic_gib_bytes(int $gib): int {
    return $gib * 1024 * 1024 * 1024;
}

function traffic_format_bytes(int $bytes): string {
    if ($bytes >= 1024 ** 3) return rtrim(rtrim(number_format($bytes / (1024 ** 3), 2, '.', ''), '0'), '.') . ' GiB';
    if ($bytes >= 1024 ** 2) return rtrim(rtrim(number_format($bytes / (1024 ** 2), 2, '.', ''), '0'), '.') . ' MiB';
    if ($bytes >= 1024) return rtrim(rtrim(number_format($bytes / 1024, 2, '.', ''), '0'), '.') . ' KiB';
    return $bytes . ' B';
}

function traffic_subscription_events(iterable $lines, int $now, string $subscribePath, string $secret): array {
    $events = [];
    foreach ($lines as $line) {
        $entry = guard_parse_log_line((string)$line);
        if (!$entry || $entry['status'] !== 200 || !guard_path_matches($entry['path'], $subscribePath)) continue;
        if ($entry['ts'] > $now + 300 || $entry['ts'] < $now - 90000) continue;
        $ip = (string)$entry['ip'];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
        $events[$ip][] = [
            'ts' => (int)$entry['ts'],
            'token' => $entry['token'] !== '' ? guard_token_fingerprint($entry['token'], $secret) : '',
        ];
    }
    return $events;
}

function traffic_analyze_logs(
    iterable $lines,
    iterable $subscriptionLines,
    array $settings,
    int $now,
    string $subscribePath,
    string $secret
): array {
    $rules = traffic_normalize_settings($settings);
    $users = [];
    $source5m = [];
    $reports = 0;
    $pushReports = 0;
    $aliveReports = 0;
    $parseErrors = 0;
    $lastReport = 0;

    foreach ($lines as $line) {
        $entry = traffic_parse_log_line((string)$line, $rules['traffic_report_path'], $secret);
        if (!$entry) continue;
        $reports++;
        $lastReport = max($lastReport, $entry['ts']);
        if (!$entry['body_valid']) $parseErrors++;
        if ($entry['action'] === 'push') $pushReports++;
        if ($entry['action'] === 'alive') $aliveReports++;
        $age = $now - $entry['ts'];
        if ($age < -300 || $age > 86400 || $entry['status'] < 200 || $entry['status'] >= 300) continue;
        if ($age <= 300 && filter_var($entry['ip'], FILTER_VALIDATE_IP)) {
            $source5m[$entry['ip']] = ($source5m[$entry['ip']] ?? 0) + 1;
        }

        foreach ($entry['users'] as $fingerprint => $payload) {
            $users[$fingerprint] ??= [
                'upload_5m' => 0, 'download_5m' => 0,
                'upload_1h' => 0, 'download_1h' => 0,
                'upload_24h' => 0, 'download_24h' => 0,
                'ips_1h' => [], 'last_seen' => 0,
            ];
            $users[$fingerprint]['last_seen'] = max($users[$fingerprint]['last_seen'], $entry['ts']);
            if ($entry['action'] === 'push') {
                $upload = (int)($payload['upload'] ?? 0);
                $download = (int)($payload['download'] ?? 0);
                $users[$fingerprint]['upload_24h'] += $upload;
                $users[$fingerprint]['download_24h'] += $download;
                if ($age <= 3600) {
                    $users[$fingerprint]['upload_1h'] += $upload;
                    $users[$fingerprint]['download_1h'] += $download;
                }
                if ($age <= 300) {
                    $users[$fingerprint]['upload_5m'] += $upload;
                    $users[$fingerprint]['download_5m'] += $download;
                }
            } elseif ($entry['action'] === 'alive' && $age <= 3600) {
                foreach ($payload['ips'] ?? [] as $ip) $users[$fingerprint]['ips_1h'][$ip] = true;
            }
        }
    }

    $findings = [];
    $analysisEnabled = !empty($rules['traffic_monitor_enabled']) && !empty($rules['traffic_analysis_enabled']);
    $subscriptionEvents = $analysisEnabled
        ? traffic_subscription_events($subscriptionLines, $now, $subscribePath, $secret)
        : [];
    $total24h = 0;
    $correlated = 0;
    foreach ($users as $fingerprint => $row) {
        $totals = [
            '5 分钟' => $row['upload_5m'] + $row['download_5m'],
            '1 小时' => $row['upload_1h'] + $row['download_1h'],
            '24 小时' => $row['upload_24h'] + $row['download_24h'],
        ];
        $total24h += $totals['24 小时'];
        if (!$analysisEnabled) continue;

        $thresholds = [
            '5 分钟' => traffic_gib_bytes($rules['traffic_user_5m_gb']),
            '1 小时' => traffic_gib_bytes($rules['traffic_user_1h_gb']),
            '24 小时' => traffic_gib_bytes($rules['traffic_user_24h_gb']),
        ];
        $triggerDetails = [];
        $peakCount = 0;
        $peakThreshold = 1;
        $peakWindow = '';
        foreach ($totals as $window => $bytes) {
            if ($bytes < $thresholds[$window]) continue;
            $triggerDetails[$window . '流量'] = traffic_format_bytes($bytes) . ' / 阈值 ' . traffic_format_bytes($thresholds[$window]);
            if ($bytes / $thresholds[$window] > $peakCount / $peakThreshold) {
                $peakCount = $bytes;
                $peakThreshold = $thresholds[$window];
                $peakWindow = $window;
            }
        }

        $upload24h = (int)$row['upload_24h'];
        $download24h = (int)$row['download_24h'];
        $uploadRatio = $download24h > 0 ? $upload24h / $download24h : ($upload24h > 0 ? 999 : 0);
        if ($upload24h >= traffic_gib_bytes($rules['traffic_upload_min_gb']) && $uploadRatio >= $rules['traffic_upload_ratio']) {
            $triggerDetails['上传占比'] = number_format($uploadRatio, 1) . ' 倍 / 阈值 ' . $rules['traffic_upload_ratio'] . ' 倍';
            if ($peakWindow === '') {
                $peakCount = (int)round($uploadRatio * 100);
                $peakThreshold = $rules['traffic_upload_ratio'] * 100;
                $peakWindow = '24 小时';
            }
        }
        $aliveIps = array_keys($row['ips_1h']);
        if (count($aliveIps) >= $rules['traffic_user_hour_ips']) {
            $triggerDetails['在线 IP'] = count($aliveIps) . ' 个 / 阈值 ' . $rules['traffic_user_hour_ips'] . ' 个';
            if ($peakWindow === '') {
                $peakCount = count($aliveIps);
                $peakThreshold = $rules['traffic_user_hour_ips'];
                $peakWindow = '1 小时';
            }
        }
        if ($triggerDetails === []) continue;

        $correlationWindow = $rules['traffic_correlation_minutes'] * 60;
        $linkedRequests = 0;
        $linkedTokens = [];
        foreach ($aliveIps as $ip) {
            foreach ($subscriptionEvents[$ip] ?? [] as $event) {
                if (abs((int)$event['ts'] - (int)$row['last_seen']) > $correlationWindow) continue;
                $linkedRequests++;
                if ($event['token'] !== '') $linkedTokens[$event['token']] = true;
            }
        }
        if ($linkedRequests > 0) {
            $correlated++;
            $triggerDetails['订阅关联'] = $linkedRequests . ' 次成功拉取 · ' . count($linkedTokens) . ' 个 Token 指纹';
        }

        $finding = guard_finding(
            'traffic_user_anomaly', $fingerprint, 'UniProxy 用户流量异常',
            max(1, $peakCount), max(1, $peakThreshold), $peakWindow ?: '近期',
            '节点上报的增量流量、在线 IP 或上传占比超过观察阈值，仅作为人工复核证据。',
            (int)$row['last_seen'], $secret,
            [
                'source' => $linkedRequests > 0 ? 'UniProxy + 订阅日志关联' : 'UniProxy 流量上报',
                'user_fingerprint' => $fingerprint,
                'sample_ips' => array_slice($aliveIps, 0, 8),
                'linked_subscription_requests' => $linkedRequests,
                'linked_token_count' => count($linkedTokens),
                'trigger_details' => $triggerDetails,
            ]
        );
        $finding['score'] = min(100, max(78, (int)$finding['score'] + ($linkedRequests > 0 ? 8 : 0)));
        $finding['risk'] = $finding['score'] >= 90 ? '高危' : '关注';
        $findings[] = $finding;
    }

    if ($analysisEnabled) {
        foreach ($source5m as $ip => $count) {
            if ($count < $rules['traffic_report_5m_requests']) continue;
            $finding = guard_finding(
                'traffic_report_flood', $ip, 'UniProxy 上报频率异常',
                $count, $rules['traffic_report_5m_requests'], '5 分钟',
                '同一节点来源在五分钟内产生大量流量上报请求，请检查节点循环或重试配置。',
                $lastReport ?: $now, $secret,
                ['source' => 'UniProxy 流量上报', 'trigger_details' => ['上报频率' => $count . ' 次 / 5 分钟']]
            );
            $findings[] = $finding;
        }
    }

    usort($findings, fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($b['last_seen_ts'] <=> $a['last_seen_ts']));
    return [
        'summary' => [
            'enabled' => $analysisEnabled,
            'capture_enabled' => !empty($rules['traffic_monitor_enabled']),
            'analysis_enabled' => !empty($rules['traffic_analysis_enabled']),
            'path' => $rules['traffic_report_path'],
            'observed_reports' => $reports,
            'push_reports' => $pushReports,
            'alive_reports' => $aliveReports,
            'users_24h' => count($users),
            'bytes_24h' => $total24h,
            'bytes_24h_label' => traffic_format_bytes($total24h),
            'last_report_ts' => $lastReport,
            'last_report_at' => $lastReport > 0 ? date('Y-m-d H:i:s', $lastReport) : '',
            'parse_errors' => $parseErrors,
            'correlated_findings' => $correlated,
        ],
        'findings' => array_slice($findings, 0, 100),
        'rules' => $rules,
    ];
}
