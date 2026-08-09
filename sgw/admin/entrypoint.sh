#!/bin/sh
set -e

SUBSCRIBE_DIR=/etc/nginx/subscribe

# 确保目录存在且 admin 可写
mkdir -p "$SUBSCRIBE_DIR"
chmod 777 "$SUBSCRIBE_DIR"

# 确保所有可写文件存在
[ -f "$SUBSCRIBE_DIR/blacklist.json" ]    || echo "[]" > "$SUBSCRIBE_DIR/blacklist.json"
[ -f "$SUBSCRIBE_DIR/blacklist.conf" ]    || echo "# blacklist" > "$SUBSCRIBE_DIR/blacklist.conf"
[ -f "$SUBSCRIBE_DIR/ua_blacklist.json" ] || echo "[]" > "$SUBSCRIBE_DIR/ua_blacklist.json"
[ -f "$SUBSCRIBE_DIR/ua_custom.conf" ]    || printf 'map $http_user_agent $is_custom_bad_ua {\n    default 0;\n}\n' > "$SUBSCRIBE_DIR/ua_custom.conf"
[ -f "$SUBSCRIBE_DIR/token_blacklist.json" ] || echo "[]" > "$SUBSCRIBE_DIR/token_blacklist.json"
[ -f "$SUBSCRIBE_DIR/token_blacklist.conf" ] || printf 'map $arg_token $is_token_blacklisted {\n    default 0;\n}\n' > "$SUBSCRIBE_DIR/token_blacklist.conf"
[ -f "$SUBSCRIBE_DIR/whitelist_ips.txt" ] || touch "$SUBSCRIBE_DIR/whitelist_ips.txt"
[ -f "$SUBSCRIBE_DIR/admin_settings.json" ] || echo "{}" > "$SUBSCRIBE_DIR/admin_settings.json"
[ -f "$SUBSCRIBE_DIR/ip_intel_cache.json" ] || echo "{}" > "$SUBSCRIBE_DIR/ip_intel_cache.json"
[ -f "$SUBSCRIBE_DIR/ip_intel_queue.json" ] || echo "{}" > "$SUBSCRIBE_DIR/ip_intel_queue.json"
[ -f "$SUBSCRIBE_DIR/stats_cache.json" ] || echo "{}" > "$SUBSCRIBE_DIR/stats_cache.json"
[ -f "$SUBSCRIBE_DIR/alert_state.json" ] || echo "{}" > "$SUBSCRIBE_DIR/alert_state.json"
[ -f "$SUBSCRIBE_DIR/alert_history.json" ] || echo "{}" > "$SUBSCRIBE_DIR/alert_history.json"
[ -f "$SUBSCRIBE_DIR/guard_cache.json" ] || echo "{}" > "$SUBSCRIBE_DIR/guard_cache.json"
[ -f "$SUBSCRIBE_DIR/guard_reviews.json" ] || echo '{"entries":{}}' > "$SUBSCRIBE_DIR/guard_reviews.json"
[ -f "$SUBSCRIBE_DIR/ai_settings.json" ] || echo '{"version":1,"enabled":0,"auto_analyze":0,"api_key":""}' > "$SUBSCRIBE_DIR/ai_settings.json"
[ -f "$SUBSCRIBE_DIR/ai_analysis.json" ] || echo '{"latest":null,"history":[],"last_attempt_ts":0,"last_error":""}' > "$SUBSCRIBE_DIR/ai_analysis.json"
[ -f "$SUBSCRIBE_DIR/token_limit_state.json" ] || echo '{"entries":{},"history":[]}' > "$SUBSCRIBE_DIR/token_limit_state.json"
[ -f "$SUBSCRIBE_DIR/token_limit.conf" ] || printf 'map $arg_token $is_token_temporarily_suspended {\n    default 0;\n}\n' > "$SUBSCRIBE_DIR/token_limit.conf"
[ -f "$SUBSCRIBE_DIR/token_limit_rate.conf" ] || printf 'map "$whitelist_ip:$arg_token" $token_pull_rate_key {\n    default "";\n}\nlimit_req_zone $token_pull_rate_key zone=token_pull_limit:10m rate=10r/m;\n' > "$SUBSCRIBE_DIR/token_limit_rate.conf"
[ -f "$SUBSCRIBE_DIR/token_limit_apply.conf" ] || printf 'limit_req zone=token_pull_limit burst=9 nodelay;\n' > "$SUBSCRIBE_DIR/token_limit_apply.conf"
[ -f "$SUBSCRIBE_DIR/cloud_provider_settings.json" ] || echo '{"version":1,"enabled":{}}' > "$SUBSCRIBE_DIR/cloud_provider_settings.json"
if [ ! -s "$SUBSCRIBE_DIR/guard_secret" ]; then
    php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > "$SUBSCRIBE_DIR/guard_secret"
fi

chmod 666 \
    "$SUBSCRIBE_DIR/blacklist.json" \
    "$SUBSCRIBE_DIR/blacklist.conf" \
    "$SUBSCRIBE_DIR/ua_blacklist.json" \
    "$SUBSCRIBE_DIR/ua_custom.conf" \
    "$SUBSCRIBE_DIR/token_blacklist.json" \
    "$SUBSCRIBE_DIR/token_blacklist.conf" \
    "$SUBSCRIBE_DIR/whitelist_ips.txt" \
    "$SUBSCRIBE_DIR/admin_settings.json" \
    "$SUBSCRIBE_DIR/ip_intel_cache.json" \
    "$SUBSCRIBE_DIR/ip_intel_queue.json" \
    "$SUBSCRIBE_DIR/stats_cache.json" \
    "$SUBSCRIBE_DIR/alert_state.json" \
    "$SUBSCRIBE_DIR/alert_history.json" \
    "$SUBSCRIBE_DIR/guard_cache.json" \
    "$SUBSCRIBE_DIR/guard_reviews.json" \
    "$SUBSCRIBE_DIR/token_limit_state.json" \
    "$SUBSCRIBE_DIR/token_limit.conf" \
    "$SUBSCRIBE_DIR/token_limit_rate.conf" \
    "$SUBSCRIBE_DIR/token_limit_apply.conf" \
    "$SUBSCRIBE_DIR/cloud_provider_settings.json"
chown www-data:www-data "$SUBSCRIBE_DIR/guard_secret"
chmod 600 "$SUBSCRIBE_DIR/guard_secret"
chown www-data:www-data "$SUBSCRIBE_DIR/ai_settings.json" "$SUBSCRIBE_DIR/ai_analysis.json"
chmod 600 "$SUBSCRIBE_DIR/ai_settings.json" "$SUBSCRIBE_DIR/ai_analysis.json"

# 兼容旧版本：根据已有 JSON 生成 Token 拦截规则，并通知 gateway reload。
php /var/www/html/maintenance.php sync-token-blacklist >/dev/null 2>&1 || true
php /var/www/html/maintenance.php refresh-token-limits >/dev/null 2>&1 || true

# 确保日志卷目录和日志文件对 PHP-FPM(www-data) 可写
mkdir -p /var/log/subscribe
chmod 777 /var/log/subscribe
touch /var/log/subscribe/access.log
chmod 666 /var/log/subscribe/access.log
touch /var/log/subscribe/uniproxy.log
chmod 666 /var/log/subscribe/uniproxy.log
touch /var/log/subscribe/maintenance.log
chmod 666 /var/log/subscribe/maintenance.log

(while true; do
    php /var/www/html/api/stats.php >/dev/null 2>&1 || true
    php /var/www/html/api/security.php >/dev/null 2>&1 || true
    sleep 60
done) &

(while true; do
    php /var/www/html/maintenance.php refresh-token-limits >> /var/log/subscribe/maintenance.log 2>&1 || true
    sleep 30
done) &

(while true; do
    php /var/www/html/maintenance.php prune-logs >> /var/log/subscribe/maintenance.log 2>&1 || true
    sleep 21600
done) &

(while true; do
    php /var/www/html/maintenance.php check-alerts >> /var/log/subscribe/maintenance.log 2>&1 || true
    sleep 60
done) &

(while true; do
    php /var/www/html/maintenance.php ai-analyze >> /var/log/subscribe/maintenance.log 2>&1 || true
    sleep 60
done) &

php-fpm -D
exec nginx -g 'daemon off;'
