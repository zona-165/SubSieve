#!/bin/bash
# 从 whitelist_ips.txt 生成 whitelist.conf，然后热重载 nginx
# 供 entrypoint 和 admin 后台调用

set -euo pipefail

WHITELIST_FILE="/etc/nginx/subscribe/whitelist_ips.txt"
OUTPUT="/etc/nginx/subscribe/whitelist.conf"
OUTPUT_TMP="${OUTPUT}.tmp.$$"
TEST_CONF="/tmp/subsieve-whitelist-test.$$.conf"
SKIP_NGINX_RELOAD="${SKIP_NGINX_RELOAD:-0}"

cleanup() { rm -f "$OUTPUT_TMP" "$TEST_CONF" 2>/dev/null || true; }
trap cleanup EXIT

cat > "$OUTPUT_TMP" <<'EOF'
geo $whitelist_ip {
    default 0;
EOF

if [[ -f "$WHITELIST_FILE" ]]; then
    while IFS= read -r line; do
        [[ -z "$line" || "$line" =~ ^# ]] && continue
        # 提取 IP/CIDR 部分（去除行内注释和多余空白）
        ip=$(echo "$line" | awk '{print $1}')
        [[ -z "$ip" ]] && continue
        echo "    $ip 1;" >> "$OUTPUT_TMP"
    done < "$WHITELIST_FILE"
fi

echo "}" >> "$OUTPUT_TMP"

cat > "$TEST_CONF" <<EOF
pid /tmp/subsieve-whitelist-test.$$.pid;
error_log stderr emerg;
events {}
http {
    include $OUTPUT_TMP;
}
EOF

if ! nginx -t -c "$TEST_CONF" -p /tmp >/dev/null 2>&1; then
    echo "白名单包含无效 IP/CIDR，已保留上一版 nginx 配置" >&2
    exit 1
fi

mv "$OUTPUT_TMP" "$OUTPUT"

if [[ "$SKIP_NGINX_RELOAD" != "1" ]]; then
    nginx -t 2>/dev/null && nginx -s reload
fi
