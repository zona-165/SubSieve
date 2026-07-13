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
