<?php
require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    json_err('maintenance only supports CLI', 403);
}

$action = $argv[1] ?? 'prune-logs';
if ($action === 'prune-logs') {
    $result = prune_old_logs(LOG_RETENTION_DAYS);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}
if ($action === 'check-alerts') {
    $result = check_alerts();
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
}

echo json_encode(['ok' => false, 'error' => 'unknown action'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(1);

function prune_old_logs(int $retentionDays): array {
    if ($retentionDays <= 0) {
        return ['ok' => true, 'disabled' => true, 'retention_days' => $retentionDays];
    }
    if (!file_exists(LOG_FILE)) {
        return ['ok' => true, 'retention_days' => $retentionDays, 'deleted' => 0, 'kept' => 0, 'missing' => true];
    }

    $cutoff = strtotime('-' . $retentionDays . ' days');
    $in = @fopen(LOG_FILE, 'r');
    if (!$in) {
        return ['ok' => false, 'error' => 'cannot open log file', 'file' => LOG_FILE];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ss_prune_');
    if ($tmp === false) {
        fclose($in);
        return ['ok' => false, 'error' => 'cannot create temp file'];
    }
    $out = @fopen($tmp, 'w');
    if (!$out) {
        fclose($in);
        @unlink($tmp);
        return ['ok' => false, 'error' => 'cannot write temp file'];
    }

    $deleted = 0;
    $kept = 0;
    while (($line = fgets($in)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        $ts = extract_log_date_ts($trimmed);
        if ($ts !== null && $ts < $cutoff) {
            $deleted++;
            continue;
        }
        fwrite($out, rtrim($line, "\r\n") . "\n");
        $kept++;
    }
    fclose($in);
    fclose($out);

    if (!rename($tmp, LOG_FILE)) {
        if (!copy($tmp, LOG_FILE)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'cannot replace log file'];
        }
        @unlink($tmp);
    }
    @chmod(LOG_FILE, 0666);
    if ($deleted > 0) {
        @unlink(dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json');
    }

    return [
        'ok' => true,
        'retention_days' => $retentionDays,
        'deleted' => $deleted,
        'kept' => $kept,
        'time' => date('Y-m-d H:i:s'),
    ];
}

function extract_log_date_ts(string $line): ?int {
    if (!preg_match('/\[(\d{2}\/\w+\/\d{4})/', $line, $m)) return null;
    $d = DateTime::createFromFormat('d/M/Y', $m[1]);
    return $d ? $d->getTimestamp() : null;
}

function check_alerts(): array {
    $settings = read_json_file(SETTINGS_JSON);
    if (empty($settings['alert_enabled'])) {
        return ['ok' => true, 'disabled' => true, 'time' => date('Y-m-d H:i:s')];
    }

    $cacheFile = dirname(IP_INTEL_CACHE_JSON) . '/stats_cache.json';
    if (!file_exists($cacheFile)) {
        return ['ok' => true, 'sent' => 0, 'missing_cache' => true, 'time' => date('Y-m-d H:i:s')];
    }

    $cache = read_json_file($cacheFile);
    $data = $cache['data'] ?? [];
    if (!is_array($data) || !$data) {
        return ['ok' => true, 'sent' => 0, 'empty_cache' => true, 'time' => date('Y-m-d H:i:s')];
    }

    $events = build_alert_events($data, $cache);
    $state = read_json_file(ALERT_STATE_JSON);
    $now = time();
    $sent = 0;
    $errors = [];

    foreach ($events as $event) {
        $key = $event['key'];
        $lastSent = (int)($state[$key] ?? 0);
        if ($lastSent > 0 && ($now - $lastSent) < 3600) {
            continue;
        }
        $result = send_alert_message_maintenance($settings, $event['text']);
        if ($result['ok']) {
            $state[$key] = $now;
            $sent++;
        } else {
            $errors[] = $result['error'] ?? 'unknown';
        }
    }

    prune_alert_state($state, $now);
    write_json_file(ALERT_STATE_JSON, $state);

    return [
        'ok' => count($errors) === 0,
        'events' => count($events),
        'sent' => $sent,
        'errors' => $errors,
        'time' => date('Y-m-d H:i:s'),
    ];
}

function build_alert_events(array $data, array $cache): array {
    $events = [];
    $cacheTs = (int)($cache['ts'] ?? 0);
    if ($cacheTs > 0 && time() - $cacheTs > 180) {
        $events[] = [
            'key' => 'stats_cache_stale',
            'text' => "SubSieve 统计缓存可能停滞\n缓存超过 3 分钟未更新，请检查 admin 容器或维护日志。",
        ];
    }

    foreach (array_slice($data['scanner_reports'] ?? [], 0, 5) as $row) {
        $ip = (string)($row['ip'] ?? '');
        $token = (string)($row['token'] ?? '');
        $ua = (string)($row['ua'] ?? '');
        $score = (int)($row['score'] ?? 0);
        if ($ip === '' || $score < 80) continue;
        $events[] = [
            'key' => 'scanner:' . $ip . ':' . substr(hash('sha1', $token . $ua), 0, 12),
            'text' => alert_text('脚本/扫描器拉取订阅', $ip, $score, [
                'Token' => short_token($token),
                'UA' => $ua ?: '-',
                '原因' => (string)($row['reason'] ?? '-'),
                '路径' => (string)($row['path'] ?? '-'),
            ]),
        ];
    }

    foreach (array_slice($data['susp_ips'] ?? [], 0, 5) as $row) {
        $ip = (string)($row['ip'] ?? '');
        $score = (int)($row['score'] ?? 0);
        if ($ip === '' || $score < 90) continue;
        $events[] = [
            'key' => 'susp_ip:' . $ip . ':' . (int)($row['token_count'] ?? 0),
            'text' => alert_text('可疑 IP 拉取多 Token', $ip, $score, [
                'Token 数' => (string)($row['token_count'] ?? 0),
                '来源' => (string)($row['country'] ?? '未查询') . ' / ' . (string)($row['city'] ?? '本地日志'),
                'ASN' => (string)($row['asn'] ?? '-'),
            ]),
        ];
    }

    foreach (array_slice($data['susp_tokens'] ?? [], 0, 5) as $row) {
        $token = (string)($row['token'] ?? '');
        $ipCount = (int)($row['ip_count'] ?? 0);
        if ($token === '' || $ipCount < 3) continue;
        $events[] = [
            'key' => 'susp_token:' . hash('sha1', $token) . ':' . $ipCount,
            'text' => "SubSieve 告警\n类型：可疑 Token 被多 IP 拉取\n风险：高危\nToken：" . short_token($token) . "\nIP 数：" . $ipCount . "\n操作建议：进入分析页复核后再处理。",
        ];
    }

    return $events;
}

function alert_text(string $type, string $ip, int $score, array $fields): string {
    $lines = [
        'SubSieve 告警',
        '类型：' . $type,
        '风险：高危｜评分 ' . $score,
        'IP：' . $ip,
    ];
    foreach ($fields as $k => $v) {
        $value = trim((string)$v);
        if ($value === '') $value = '-';
        $lines[] = $k . '：' . $value;
    }
    $lines[] = '时间：' . date('Y-m-d H:i:s');
    $lines[] = '操作建议：进入分析页复核后再封禁。';
    return implode("\n", $lines);
}

function read_json_file(string $file): array {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_json_file(string $file, array $data): bool {
    $ok = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    @chmod($file, 0666);
    return $ok;
}

function prune_alert_state(array &$state, int $now): void {
    foreach ($state as $key => $ts) {
        if (!is_int($ts) && !ctype_digit((string)$ts)) {
            unset($state[$key]);
            continue;
        }
        if ($now - (int)$ts > 86400) unset($state[$key]);
    }
}

function short_token(string $token): string {
    if ($token === '') return '-';
    return strlen($token) <= 16 ? $token : substr($token, 0, 12) . '...' . substr($token, -6);
}

function send_alert_message_maintenance(array $settings, string $text): array {
    $channel = $settings['alert_channel'] ?? 'webhook';
    if ($channel === 'telegram') {
        $token = trim((string)($settings['alert_telegram_bot_token'] ?? ''));
        $chatId = trim((string)($settings['alert_telegram_chat_id'] ?? ''));
        if ($token === '' || $chatId === '') return ['ok' => false, 'error' => 'telegram not configured'];
        return http_json_post_maintenance('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }
    $url = trim((string)($settings['alert_webhook_url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'webhook not configured'];
    return http_json_post_maintenance($url, [
        'title' => 'SubSieve 告警',
        'text' => $text,
        'source' => 'SubSieve',
        'time' => date('Y-m-d H:i:s'),
    ]);
}

function http_json_post_maintenance(string $url, array $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    if ($raw === false) return ['ok' => false, 'error' => 'request failed'];
    if ($status >= 400) return ['ok' => false, 'error' => 'HTTP ' . $status . ': ' . substr($raw, 0, 160)];
    return ['ok' => true, 'status' => $status];
}
