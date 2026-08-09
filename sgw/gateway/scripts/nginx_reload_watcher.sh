#!/bin/sh
# 监听共享 volume 中的信号文件，有信号时执行 nginx -s reload
# 由 entrypoint.sh 在后台启动，无需 Docker socket

SUBSCRIBE_DIR="${SUBSCRIBE_DIR:-/etc/nginx/subscribe}"
LOG_DIR="${LOG_DIR:-/var/log/subscribe}"
NGINX_BIN="${NGINX_BIN:-nginx}"
RUN_ONCE="${RUN_ONCE:-0}"
SIGNAL_FILE="$SUBSCRIBE_DIR/.reload"
WHITELIST_SIGNAL="$SUBSCRIBE_DIR/.reload_whitelist"
TOKEN_LIMIT_MARKER="$SUBSCRIBE_DIR/.token_limit_reload"
CLOUD_PROVIDER_SIGNAL="$SUBSCRIBE_DIR/.refresh_cloud_providers"
CLOUD_PROVIDER_SETTINGS="$SUBSCRIBE_DIR/cloud_provider_settings.json"

is_runtime_rule_file() {
    case "$1" in
        blacklist.conf|blacklist.json|ua_custom.conf|ua_blacklist.json|ua_whitelist.conf|ua_whitelist.json|token_blacklist.conf|token_blacklist.json|protect.conf)
            return 0
            ;;
    esac
    return 1
}

finish_runtime_reload() {
    [ -f "$SIGNAL_FILE" ] || return 0
    while IFS= read -r name; do
        is_runtime_rule_file "$name" || continue
        rm -f "$SUBSCRIBE_DIR/$name.prev"
    done < "$SIGNAL_FILE"
    rm -f "$SIGNAL_FILE" "$SIGNAL_FILE.prev"
}

rollback_runtime_reload() {
    [ -f "$SIGNAL_FILE" ] || return 0
    while IFS= read -r name; do
        is_runtime_rule_file "$name" || continue
        if [ -f "$SUBSCRIBE_DIR/$name.prev" ]; then
            mv -f "$SUBSCRIBE_DIR/$name.prev" "$SUBSCRIBE_DIR/$name"
        fi
    done < "$SIGNAL_FILE"
    rm -f "$SIGNAL_FILE" "$SIGNAL_FILE.prev"
}

finish_whitelist_reload() {
    rm -f "$SUBSCRIBE_DIR/whitelist_ips.txt.prev" "$WHITELIST_SIGNAL" "$WHITELIST_SIGNAL.prev"
}

rollback_whitelist_reload() {
    if [ -f "$SUBSCRIBE_DIR/whitelist_ips.txt.prev" ]; then
        mv -f "$SUBSCRIBE_DIR/whitelist_ips.txt.prev" "$SUBSCRIBE_DIR/whitelist_ips.txt"
    fi
    rm -f "$WHITELIST_SIGNAL" "$WHITELIST_SIGNAL.prev"
}

finish_token_limit_reload() {
    [ -f "$TOKEN_LIMIT_MARKER" ] || return 0
    while IFS= read -r name; do
        case "$name" in
            token_limit.conf|token_limit_rate.conf|token_limit_apply.conf)
                rm -f "$SUBSCRIBE_DIR/$name.prev"
                ;;
        esac
    done < "$TOKEN_LIMIT_MARKER"
    rm -f "$TOKEN_LIMIT_MARKER" "$TOKEN_LIMIT_MARKER.prev"
}

rollback_token_limit_reload() {
    [ -f "$TOKEN_LIMIT_MARKER" ] || return 0
    while IFS= read -r name; do
        case "$name" in
            token_limit.conf|token_limit_rate.conf|token_limit_apply.conf)
                if [ -f "$SUBSCRIBE_DIR/$name.prev" ]; then
                    mv -f "$SUBSCRIBE_DIR/$name.prev" "$SUBSCRIBE_DIR/$name"
                fi
                ;;
        esac
    done < "$TOKEN_LIMIT_MARKER"
    rm -f "$TOKEN_LIMIT_MARKER" "$TOKEN_LIMIT_MARKER.prev"
}

while true; do
    if [ -f "$CLOUD_PROVIDER_SIGNAL" ]; then
        rm -f "$CLOUD_PROVIDER_SIGNAL"
        if /scripts/update_cloud_geo.sh >/dev/null 2>&1; then
            rm -f "${CLOUD_PROVIDER_SETTINGS}.prev"
        else
            if [ -s "${CLOUD_PROVIDER_SETTINGS}.prev" ]; then
                mv -f "${CLOUD_PROVIDER_SETTINGS}.prev" "$CLOUD_PROVIDER_SETTINGS"
            fi
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] 云厂商策略应用失败，已恢复上一版选择" \
                >> "$LOG_DIR/entrypoint.log"
        fi
    elif [ -f "$WHITELIST_SIGNAL" ]; then
        if /scripts/reload_whitelist.sh 2>/dev/null; then
            finish_whitelist_reload
        else
            rollback_whitelist_reload
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] 白名单配置应用失败，已恢复上一版" \
                >> "$LOG_DIR/entrypoint.log"
        fi
    elif [ -f "$SIGNAL_FILE" ]; then
        if "$NGINX_BIN" -t 2>/dev/null && "$NGINX_BIN" -s reload 2>/dev/null; then
            finish_runtime_reload
            finish_token_limit_reload
        else
            rollback_runtime_reload
            rollback_token_limit_reload
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] nginx 配置应用失败，已恢复上一版规则文件" \
                >> "$LOG_DIR/entrypoint.log"
        fi
    fi
    [ "$RUN_ONCE" = "1" ] && break
    sleep 1
done
