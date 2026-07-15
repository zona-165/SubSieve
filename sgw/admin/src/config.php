<?php
// =============================================================
// config.php — 从环境变量加载配置
// =============================================================

// 文件路径（共享 volume）— 先定义，以便读取 settings.json
define('LOG_FILE',          '/var/log/subscribe/access.log');
define('WHITELIST_IPS',     '/etc/nginx/subscribe/whitelist_ips.txt');
define('WHITELIST_CONF',    '/etc/nginx/subscribe/whitelist.conf');
define('BLACKLIST_JSON',    '/etc/nginx/subscribe/blacklist.json');
define('BLACKLIST_CONF',    '/etc/nginx/subscribe/blacklist.conf');
define('CLOUD_GEO_LOG',     '/var/log/subscribe/update_cloud_geo.log');
define('CLOUD_GEO_CONF',    '/etc/nginx/subscribe/cloud_geo.conf');
define('UA_BLACKLIST_JSON', '/etc/nginx/subscribe/ua_blacklist.json');
define('UA_CUSTOM_CONF',    '/etc/nginx/subscribe/ua_custom.conf');
define('UA_WHITELIST_JSON', '/etc/nginx/subscribe/ua_whitelist.json');
define('UA_WHITELIST_CONF',    '/etc/nginx/subscribe/ua_whitelist.conf');
define('TOKEN_BLACKLIST_JSON', '/etc/nginx/subscribe/token_blacklist.json');
define('TOKEN_BLACKLIST_CONF', '/etc/nginx/subscribe/token_blacklist.conf');
define('IP_INTEL_CACHE_JSON', '/etc/nginx/subscribe/ip_intel_cache.json');
define('ALERT_STATE_JSON', '/etc/nginx/subscribe/alert_state.json');
define('ALERT_HISTORY_JSON', '/etc/nginx/subscribe/alert_history.json');
define('SETTINGS_JSON',     '/etc/nginx/subscribe/admin_settings.json');
define('PROTECT_CONF',      '/etc/nginx/subscribe/protect.conf');
define('DEPLOY_INFO_FILE',  '/var/log/subscribe/DEPLOY_INFO.txt');

// 读取持久化设置（覆盖环境变量）
$_sg = [];
if (file_exists(SETTINGS_JSON)) {
    $_d = json_decode(file_get_contents(SETTINGS_JSON), true);
    if (is_array($_d)) $_sg = $_d;
}

define('ADMIN_USER',        $_sg['admin_user']      ?? (getenv('ADMIN_USER')        ?: 'admin'));
define('ADMIN_PASS',        $_sg['admin_pass']      ?? (getenv('ADMIN_PASS')        ?: ''));
define('NGINX_RELOAD_SIGNAL',     '/etc/nginx/subscribe/.reload');
define('WHITELIST_RELOAD_SIGNAL', '/etc/nginx/subscribe/.reload_whitelist');
define('GATEWAY_PORT',      (int)(getenv('GATEWAY_PORT') ?: 443));
define('LOG_RETENTION_DAYS', (int)(getenv('LOG_RETENTION_DAYS') !== false ? getenv('LOG_RETENTION_DAYS') : 14));
define('ALERT_HISTORY_MAX', max(50, min(1000, (int)($_sg['alert_history_max'] ?? 200))));
define('SESSION_LIFETIME',  (int)(getenv('SESSION_LIFETIME') ?: 28800)); // 8小时
// 后台访问路径前缀，留空则不校验（例如 ef9d1566 → 必须访问 /ef9d1566 才能进入后台）
define('ADMIN_SECRET_PATH', trim(trim(getenv('ADMIN_SECRET_PATH') ?: ''), '/'));

// 界面显示设置
define('SITE_TITLE', $_sg['site_title'] ?? 'SubSieve');
define('PAGE_TITLE', $_sg['page_title'] ?? 'SubSieve Admin');

// ── 辅助函数 ──────────────────────────────────────────────────

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_name('SUBSIEVESESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function destroy_admin_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

/**
 * 触发 gateway nginx reload
 * 向共享 volume 写入信号文件，gateway 的 watcher 检测后执行 nginx -s reload
 * 无需挂载 Docker socket，避免宿主机 root 权限暴露
 */
function nginx_reload(): bool {
    return file_put_contents(NGINX_RELOAD_SIGNAL, '1', LOCK_EX) !== false;
}

function whitelist_reload(): bool {
    return file_put_contents(WHITELIST_RELOAD_SIGNAL, '1', LOCK_EX) !== false;
}

// ── 写入 nginx 配置前的安全过滤 ────────────────────────────────
// 后台多处会把用户输入（路径、上游地址、UA、备注等）写入 nginx 配置文件，
// 若不过滤，换行 / 结构字符（{ } ; "）可篡改反代规则或注入指令。

/**
 * 校验将作为「结构性 token」直接拼入 nginx 配置的值（如订阅路径、上游地址、Host）。
 * 这些值未加引号写入配置，含换行或 nginx 结构字符（{ } ;）即可越权注入，故直接拒绝。
 * 校验不通过会输出 JSON 错误并终止请求。
 */
function safe_conf_value(string $s): string {
    $s = trim($s);
    if ($s === '' || preg_match('/[\s{};#"\'\\\\$]/', $s)) {
        json_err('包含非法字符（不允许空白或 nginx 结构字符）');
    }
    return $s;
}

/**
 * 清洗将写入 nginx 配置 / 数据文件的「备注」文本，使其只能作为单行普通注释存在：
 * 移除换行（避免越行注入指令）及 # ; { } 等结构 / 注释字符，多个连续字符压缩为单空格。
 */
function safe_comment(string $s): string {
    return trim(preg_replace('/[\r\n#;{}]+/', ' ', $s));
}

/**
 * 将用户输入的 UA 关键词转为 nginx map 的「字面量」匹配模式（配合 ~* 前缀）。
 * 1) preg_quote 中和全部正则元字符，避免 . * 等生效或被构造成 ReDoS；
 * 2) 再转义 nginx 双引号字符串层的 \ 与 "，避免提前闭合字符串注入配置。
 * 调用方需保证传入的 UA 不含换行（写入前已剔除）。
 */
function nginx_ua_pattern(string $ua): string {
    $p = preg_quote($ua, '~');
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $p);
}

function write_token_blacklist_files(array $entries): bool {
    $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;

    $lines = ['# Token 黑名单 - 由 admin 自动生成 | ' . date('Y-m-d H:i:s')];
    $lines[] = 'map $arg_token $is_token_blacklisted {';
    $lines[] = '    default 0;';
    foreach ($entries as $entry) {
        $token = trim((string)($entry['token'] ?? ''));
        if ($token === '' || strlen($token) > 512 || preg_match('/[\x00-\x1F\x7F]/', $token)) continue;
        $lines[] = '    "~^' . nginx_ua_pattern($token) . '$" 1;';
    }
    $lines[] = '}';
    $conf = implode("\n", $lines) . "\n";

    $suffix = '.tmp.' . getmypid();
    $jsonTmp = TOKEN_BLACKLIST_JSON . $suffix;
    $confTmp = TOKEN_BLACKLIST_CONF . $suffix;
    $jsonWritten = file_put_contents($jsonTmp, $json, LOCK_EX) !== false;
    $confWritten = file_put_contents($confTmp, $conf, LOCK_EX) !== false;
    if (!$jsonWritten || !$confWritten) {
        @unlink($jsonTmp);
        @unlink($confTmp);
        return false;
    }

    @chmod($jsonTmp, 0666);
    @chmod($confTmp, 0666);
    $confReplaced = @rename($confTmp, TOKEN_BLACKLIST_CONF);
    $jsonReplaced = $confReplaced && @rename($jsonTmp, TOKEN_BLACKLIST_JSON);
    @unlink($jsonTmp);
    @unlink($confTmp);
    return $confReplaced && $jsonReplaced;
}

/**
 * 严格校验单个 IP 或 CIDR，并根据地址族检查前缀长度。
 */
function is_valid_ip_or_cidr(string $value, bool $allowIpv6 = true): bool {
    $value = trim($value);
    if ($value === '') return false;

    $parts = explode('/', $value);
    if (count($parts) > 2) return false;
    $ip = $parts[0];
    $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $isV6 = $allowIpv6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    if (!$isV4 && !$isV6) return false;

    if (count($parts) === 1) return true;
    $prefix = $parts[1];
    if ($prefix === '' || !ctype_digit($prefix)) return false;
    $maxPrefix = $isV4 ? 32 : 128;
    return (int)$prefix <= $maxPrefix;
}

// 出于安全考虑，后台默认不直连机场数据库。
// 如需定位 Token 对应用户，建议在机场后台手动查询或使用最小权限的独立审计接口。
