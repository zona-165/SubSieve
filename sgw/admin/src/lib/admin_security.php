<?php

function admin_security_read_json(string $path, array $default = []): array {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function admin_security_update_json(string $path, array $default, callable $mutator, int $mode = 0600): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;

    $handle = @fopen($path, 'c+');
    if (!$handle) return false;
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = $default;
    $next = $mutator($data);
    if (!is_array($next)) $next = $data;
    $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = $json !== false;
    if ($ok) {
        rewind($handle);
        $ok = ftruncate($handle, 0) && fwrite($handle, $json . "\n") !== false && fflush($handle);
    }
    @chmod($path, $mode);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $ok;
}

function admin_security_write_json(string $path, array $data, int $mode = 0600): bool {
    return admin_security_update_json($path, [], static fn(array $_current): array => $data, $mode);
}

function admin_public_settings(array $settings): array {
    $public = $settings;
    $public['admin_password_hashed'] = !empty($settings['admin_pass_hash']);
    $public['admin_totp_enabled'] = !empty($settings['admin_totp_enabled']) && !empty($settings['admin_totp_secret']);
    $public['alert_webhook_configured'] = !empty($settings['alert_webhook_url']);
    $public['alert_telegram_token_configured'] = !empty($settings['alert_telegram_bot_token']);
    foreach (['admin_pass', 'admin_pass_hash', 'admin_totp_secret', 'admin_auth_version', 'alert_webhook_url', 'alert_telegram_bot_token'] as $key) {
        unset($public[$key]);
    }
    return $public;
}

function admin_csrf_token(): string {
    $token = $_SESSION['_csrf_token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
    }
    return $token;
}

function admin_csrf_rotate(): string {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

function admin_csrf_is_valid(string $provided): bool {
    $expected = $_SESSION['_csrf_token'] ?? '';
    return is_string($expected) && $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function admin_password_hash_value(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

function admin_password_verify_values(string $candidate, string $hash, string $legacy = ''): array {
    if ($hash !== '') {
        $valid = password_verify($candidate, $hash);
        return [
            'valid' => $valid,
            'legacy' => false,
            'needs_rehash' => $valid && password_needs_rehash($hash, PASSWORD_DEFAULT),
        ];
    }
    $valid = $legacy !== '' && hash_equals($legacy, $candidate);
    return ['valid' => $valid, 'legacy' => $valid, 'needs_rehash' => $valid];
}

function admin_password_check(string $candidate): array {
    $hash = defined('ADMIN_PASS_HASH') ? (string)ADMIN_PASS_HASH : '';
    $legacy = defined('ADMIN_PASS') ? (string)ADMIN_PASS : '';
    return admin_password_verify_values($candidate, $hash, $legacy);
}

function admin_store_password_hash(string $password): bool {
    if (!defined('SETTINGS_JSON')) return false;
    $hash = admin_password_hash_value($password);
    if ($hash === '') return false;
    return admin_security_update_json(SETTINGS_JSON, [], static function (array $settings) use ($hash): array {
        $settings['admin_pass_hash'] = $hash;
        unset($settings['admin_pass']);
        return $settings;
    });
}

function admin_login_guard_key(string $ip): string {
    $secret = '';
    if (defined('GUARD_SECRET_FILE') && is_file(GUARD_SECRET_FILE)) {
        $secret = trim((string)@file_get_contents(GUARD_SECRET_FILE));
    }
    if ($secret === '') $secret = 'subsieve-login-guard-local-key';
    return hash_hmac('sha256', $ip !== '' ? $ip : 'unknown', $secret);
}

function admin_login_guard_limits(): array {
    return [
        'max_failures' => defined('LOGIN_MAX_FAILURES') ? max(2, (int)LOGIN_MAX_FAILURES) : 5,
        'window' => defined('LOGIN_FAILURE_WINDOW') ? max(60, (int)LOGIN_FAILURE_WINDOW) : 600,
        'lock_seconds' => defined('LOGIN_LOCK_SECONDS') ? max(60, (int)LOGIN_LOCK_SECONDS) : 900,
    ];
}

function admin_login_guard_entry_status(array $entry, int $now): array {
    $limits = admin_login_guard_limits();
    $failures = array_values(array_filter(
        is_array($entry['failures'] ?? null) ? $entry['failures'] : [],
        static fn($ts): bool => is_numeric($ts) && (int)$ts > $now - $limits['window']
    ));
    $lockUntil = (int)($entry['lock_until'] ?? 0);
    $locked = $lockUntil > $now;
    return [
        'locked' => $locked,
        'retry_after' => $locked ? $lockUntil - $now : 0,
        'failures' => count($failures),
        'attempts_remaining' => max(0, $limits['max_failures'] - count($failures)),
        '_failures' => $failures,
        '_lock_until' => $locked ? $lockUntil : 0,
    ];
}

function admin_login_guard_status(string $ip, ?int $now = null): array {
    $now ??= time();
    $data = defined('LOGIN_GUARD_JSON') ? admin_security_read_json(LOGIN_GUARD_JSON, ['entries' => []]) : ['entries' => []];
    $key = admin_login_guard_key($ip);
    return admin_login_guard_entry_status($data['entries'][$key] ?? [], $now);
}

function admin_login_guard_record_failure(string $ip, ?int $now = null): array {
    $now ??= time();
    $result = [];
    if (!defined('LOGIN_GUARD_JSON')) return admin_login_guard_entry_status([], $now);
    $key = admin_login_guard_key($ip);
    $limits = admin_login_guard_limits();
    admin_security_update_json(LOGIN_GUARD_JSON, ['version' => 1, 'entries' => []], static function (array $data) use ($key, $now, $limits, &$result): array {
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
        foreach ($entries as $entryKey => $entry) {
            $status = admin_login_guard_entry_status(is_array($entry) ? $entry : [], $now);
            $lastSeen = (int)($entry['last_seen'] ?? 0);
            if (!$status['locked'] && !$status['failures'] && $lastSeen < $now - $limits['window']) unset($entries[$entryKey]);
        }
        $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];
        $status = admin_login_guard_entry_status($entry, $now);
        if (!$status['locked']) {
            $failures = $status['_failures'];
            $failures[] = $now;
            $lockUntil = 0;
            if (count($failures) >= $limits['max_failures']) {
                $lockUntil = $now + $limits['lock_seconds'];
                $failures = [];
            }
            $entry = ['failures' => $failures, 'lock_until' => $lockUntil, 'last_seen' => $now];
            $entries[$key] = $entry;
        }
        if (count($entries) > 500) {
            uasort($entries, static fn(array $a, array $b): int => ((int)($b['last_seen'] ?? 0)) <=> ((int)($a['last_seen'] ?? 0)));
            $entries = array_slice($entries, 0, 500, true);
        }
        $result = admin_login_guard_entry_status($entries[$key] ?? $entry, $now);
        return ['version' => 1, 'entries' => $entries];
    });
    return $result ?: admin_login_guard_status($ip, $now);
}

function admin_login_guard_record_success(string $ip): bool {
    if (!defined('LOGIN_GUARD_JSON')) return true;
    $key = admin_login_guard_key($ip);
    return admin_security_update_json(LOGIN_GUARD_JSON, ['version' => 1, 'entries' => []], static function (array $data) use ($key): array {
        $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
        unset($entries[$key]);
        return ['version' => 1, 'entries' => $entries];
    });
}

function admin_base32_encode(string $binary): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $out = '';
    foreach (unpack('C*', $binary) ?: [] as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($buffer >> $bits) & 31];
        }
    }
    if ($bits > 0) $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $out;
}

function admin_base32_decode(string $encoded): string {
    $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
    $encoded = strtoupper((string)preg_replace('/[\s=-]+/', '', $encoded));
    $buffer = 0;
    $bits = 0;
    $out = '';
    foreach (str_split($encoded) as $char) {
        if (!isset($alphabet[$char])) return '';
        $buffer = ($buffer << 5) | $alphabet[$char];
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buffer >> $bits) & 255);
        }
    }
    return $out;
}

function admin_totp_generate_secret(): string {
    return admin_base32_encode(random_bytes(20));
}

function admin_totp_code(string $secret, ?int $timestamp = null): string {
    $key = admin_base32_decode($secret);
    if ($key === '') return '';
    $counter = intdiv($timestamp ?? time(), 30);
    $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function admin_totp_verify(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $timestamp ??= time();
    for ($offset = -$window; $offset <= $window; $offset++) {
        $expected = admin_totp_code($secret, $timestamp + ($offset * 30));
        if ($expected !== '' && hash_equals($expected, $code)) return true;
    }
    return false;
}

function admin_totp_uri(string $secret, string $account, string $issuer = 'SubSieve'): string {
    $label = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
