#!/bin/sh
# 监听共享 volume 中的信号文件，有信号时执行 nginx -s reload
# 由 entrypoint.sh 在后台启动，无需 Docker socket

SIGNAL_FILE="/etc/nginx/subscribe/.reload"
WHITELIST_SIGNAL="/etc/nginx/subscribe/.reload_whitelist"
TOKEN_LIMIT_MARKER="/etc/nginx/subscribe/.token_limit_reload"

finish_token_limit_reload() {
    [ -f "$TOKEN_LIMIT_MARKER" ] || return 0
    while IFS= read -r name; do
        case "$name" in
            token_limit.conf|token_limit_rate.conf|token_limit_apply.conf)
                rm -f "/etc/nginx/subscribe/$name.prev"
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
                if [ -f "/etc/nginx/subscribe/$name.prev" ]; then
                    mv -f "/etc/nginx/subscribe/$name.prev" "/etc/nginx/subscribe/$name"
                fi
                ;;
        esac
    done < "$TOKEN_LIMIT_MARKER"
    rm -f "$TOKEN_LIMIT_MARKER" "$TOKEN_LIMIT_MARKER.prev"
}

while true; do
    if [ -f "$WHITELIST_SIGNAL" ]; then
        rm -f "$WHITELIST_SIGNAL"
        /scripts/reload_whitelist.sh 2>/dev/null || true
    elif [ -f "$SIGNAL_FILE" ]; then
        rm -f "$SIGNAL_FILE"
        if nginx -t 2>/dev/null; then
            nginx -s reload 2>/dev/null || true
            finish_token_limit_reload
        else
            rollback_token_limit_reload
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] nginx 配置校验失败，已保留当前运行配置" \
                >> /var/log/subscribe/entrypoint.log
        fi
    fi
    sleep 1
done
