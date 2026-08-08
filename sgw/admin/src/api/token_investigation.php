<?php
require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/lib/guard.php';
require_once dirname(__DIR__) . '/lib/ip_intel_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) json_err('请求格式无效');

$settings = guard_read_json(SETTINGS_JSON);
$rules = guard_normalize_settings($settings);
$secret = guard_runtime_secret();
$fingerprint = strtoupper(trim((string)($body['fingerprint'] ?? '')));
$rawToken = trim((string)($body['token'] ?? ''));
if ($rawToken !== '') {
    if (strlen($rawToken) > 512 || preg_match('/[\x00-\x1F\x7F]/', $rawToken)) json_err('Token 格式无效');
    $fingerprint = guard_token_fingerprint($rawToken, $secret);
}
if (!preg_match('/^TKN-[A-F0-9]{16}$/', $fingerprint)) json_err('Token 指纹格式无效');

$subscribePath = trim((string)($settings['subscribe_path'] ?? '/api/v1/client/subscribe'));
if ($subscribePath === '') $subscribePath = '/api/v1/client/subscribe';
$intelCache = guard_read_json(IP_INTEL_CACHE_JSON);
$lines = guard_tail_log_lines(LOG_FILE, $rules['guard_scan_lines']);
$profile = guard_build_token_investigation(
    $lines,
    $fingerprint,
    $settings,
    time(),
    $subscribePath,
    $secret,
    $intelCache
);
if (!$profile) json_err('最近日志中未找到该 Token', 404);

$pendingIps = is_array($profile['pending_intel_ips'] ?? null) ? $profile['pending_intel_ips'] : [];
if ($pendingIps) ip_intel_enqueue($pendingIps);
unset($profile['pending_intel_ips']);

$limitState = guard_read_json(TOKEN_LIMIT_STATE_JSON);
$entry = is_array($limitState['entries'][$fingerprint] ?? null) ? $limitState['entries'][$fingerprint] : [];
$profile['suspended'] = (int)($entry['until_ts'] ?? 0) > time();
$profile['suspended_until'] = $profile['suspended'] ? (string)($entry['until'] ?? '') : '';
$profile['blacklisted'] = false;
foreach (guard_read_json(TOKEN_BLACKLIST_JSON) as $row) {
    if (isset($row['token']) && hash_equals((string)$row['token'], (string)$profile['raw_token'])) {
        $profile['blacklisted'] = true;
        break;
    }
}

json_out(['ok' => true, 'profile' => $profile, 'intel_queued' => count($pendingIps)]);
