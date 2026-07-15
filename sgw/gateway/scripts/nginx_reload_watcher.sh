#!/bin/sh
# 监听共享 volume 中的信号文件，有信号时执行 nginx -s reload
# 由 entrypoint.sh 在后台启动，无需 Docker socket

SIGNAL_FILE="/etc/nginx/subscribe/.reload"
WHITELIST_SIGNAL="/etc/nginx/subscribe/.reload_whitelist"

while true; do
    if [ -f "$WHITELIST_SIGNAL" ]; then
        rm -f "$WHITELIST_SIGNAL"
        /scripts/reload_whitelist.sh 2>/dev/null || true
    elif [ -f "$SIGNAL_FILE" ]; then
        rm -f "$SIGNAL_FILE"
        if nginx -t 2>/dev/null; then
            nginx -s reload 2>/dev/null || true
        else
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] nginx 配置校验失败，已保留当前运行配置" \
                >> /var/log/subscribe/entrypoint.log
        fi
    fi
    sleep 1
done
