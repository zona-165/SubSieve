<?php

$tmp = sys_get_temp_dir() . '/subsieve-admin-security-' . getmypid();
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "cannot create temp directory\n");
    exit(1);
}

define('SETTINGS_JSON', $tmp . '/settings.json');
define('LOGIN_GUARD_JSON', $tmp . '/login_guard.json');
define('GUARD_SECRET_FILE', $tmp . '/guard_secret');
define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_FAILURE_WINDOW', 600);
define('LOGIN_LOCK_SECONDS', 900);
file_put_contents(GUARD_SECRET_FILE, 'test-login-guard-secret');

require dirname(__DIR__) . '/admin/src/lib/admin_security.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$hash = admin_password_hash_value('correct horse battery staple');
$hashed = admin_password_verify_values('correct horse battery staple', $hash, 'legacy');
assert_true($hashed['valid'] && !$hashed['legacy'], 'hashed password should verify without legacy fallback');
assert_true(!admin_password_verify_values('wrong', $hash, 'wrong')['valid'], 'hash should reject wrong password');
$legacy = admin_password_verify_values('legacy-pass', '', 'legacy-pass');
assert_true($legacy['valid'] && $legacy['legacy'] && $legacy['needs_rehash'], 'legacy password should request migration');
file_put_contents(SETTINGS_JSON, json_encode(['admin_pass' => 'legacy-pass', 'site_title' => 'Test']));
assert_true(admin_store_password_hash('legacy-pass'), 'legacy password should migrate to private hash storage');
$migrated = admin_security_read_json(SETTINGS_JSON);
assert_true(!isset($migrated['admin_pass']) && password_verify('legacy-pass', $migrated['admin_pass_hash'] ?? ''), 'migration should remove plaintext password');

$public = admin_public_settings([
    'admin_user' => 'admin',
    'admin_pass' => 'plain',
    'admin_pass_hash' => $hash,
    'admin_totp_enabled' => 1,
    'admin_totp_secret' => 'SECRET',
    'admin_auth_version' => 'session-version',
    'alert_webhook_url' => 'https://secret.example/hook',
    'alert_telegram_bot_token' => '123:secret',
]);
foreach (['admin_pass', 'admin_pass_hash', 'admin_totp_secret', 'admin_auth_version', 'alert_webhook_url', 'alert_telegram_bot_token'] as $secretKey) {
    assert_true(!array_key_exists($secretKey, $public), "public settings should omit {$secretKey}");
}
assert_true($public['admin_password_hashed'] && $public['admin_totp_enabled'], 'public settings should expose security state');

$_SESSION = [];
$csrf = admin_csrf_token();
assert_true(strlen($csrf) === 64 && admin_csrf_is_valid($csrf), 'CSRF token should validate');
assert_true(!admin_csrf_is_valid(str_repeat('0', 64)), 'wrong CSRF token should fail');
assert_true(admin_csrf_rotate() !== $csrf, 'CSRF token should rotate');

$rfcSecret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
assert_true(admin_totp_code($rfcSecret, 59) === '287082', 'TOTP should match RFC 6238 six-digit vector');
assert_true(admin_totp_verify($rfcSecret, '287082', 59, 0), 'TOTP verifier should accept valid code');
assert_true(!admin_totp_verify($rfcSecret, '287083', 59, 0), 'TOTP verifier should reject invalid code');
$generated = admin_totp_generate_secret();
assert_true(strlen($generated) === 32 && admin_base32_decode($generated) !== '', 'generated TOTP secret should be valid base32');

$ip = '203.0.113.8';
for ($i = 0; $i < 4; $i++) {
    $status = admin_login_guard_record_failure($ip, 1000 + $i);
    assert_true(!$status['locked'], 'login should remain available before failure threshold');
}
$locked = admin_login_guard_record_failure($ip, 1004);
assert_true($locked['locked'] && $locked['retry_after'] === 900, 'fifth failure should lock login for 15 minutes');
$guardRaw = (string)file_get_contents(LOGIN_GUARD_JSON);
assert_true(!str_contains($guardRaw, $ip), 'login guard should not store raw IP addresses');
assert_true(admin_login_guard_status($ip, 1905)['locked'] === false, 'login lock should expire');
admin_login_guard_record_failure($ip, 2000);
assert_true(admin_login_guard_record_success($ip), 'successful login should clear failure record');
assert_true(admin_login_guard_status($ip, 2000)['failures'] === 0, 'failure record should be empty after success');

assert_true(admin_security_write_json(SETTINGS_JSON, ['ok' => true]), 'private JSON should be writable');
clearstatcache(true, SETTINGS_JSON);
assert_true((fileperms(SETTINGS_JSON) & 0777) === 0600, 'private JSON should use mode 0600');

foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
@rmdir($tmp);
echo "admin security tests passed\n";
