#!/bin/bash
set -euo pipefail

OUTPUT="/etc/nginx/subscribe/cloud_geo.conf"
OUTPUT_TMP="${OUTPUT}.tmp"
OUTPUT_BACKUP="${OUTPUT}.bak.$$"
LOG_FILE="/var/log/subscribe/update_cloud_geo.log"
TEMP_DIR=$(mktemp -d)
TEST_CONF="$TEMP_DIR/nginx-cloud-candidate.conf"
SKIP_NGINX_RELOAD="${SKIP_NGINX_RELOAD:-0}"
HAD_PREVIOUS=0

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
cleanup() { rm -rf "$TEMP_DIR" "$OUTPUT_TMP" "$OUTPUT_BACKUP" 2>/dev/null || true; }
restore_previous() {
    if [[ "$HAD_PREVIOUS" == "1" && -s "$OUTPUT_BACKUP" ]]; then
        mv -f "$OUTPUT_BACKUP" "$OUTPUT"
    else
        rm -f "$OUTPUT"
    fi
}
trap cleanup EXIT

declare -A SOURCES=(
    ["阿里云"]="https://metowolf.github.io/iplist/data/isp/aliyun.txt"
    ["腾讯云"]="https://metowolf.github.io/iplist/data/isp/tencent.txt"
    ["字节跳动"]="https://metowolf.github.io/iplist/data/isp/bytedance.txt"
    ["华为云"]="https://metowolf.github.io/iplist/data/isp/huawei.txt"
    ["Google Cloud"]="https://metowolf.github.io/iplist/data/isp/googlecloud.txt"
)
declare -A ASN_SOURCES=(
    ["UCloud"]="AS135377"
    ["Azure"]="AS8075"
    ["DigitalOcean"]="AS14061"
    ["Vultr"]="AS20473"
)
AWS_URL="https://ip-ranges.amazonaws.com/ip-ranges.json"

log "开始并行拉取云厂商IP段..."

# ── 并行下载所有数据源 ──────────────────────────────────────────────────────
# 将每个 curl 放入后台，记录 PID，最后统一 wait，避免串行超时叠加
# 最坏情况：原来 5×15s + 4×20s + 20s = 175s，现在降至 max(15s, 20s) = 20s

declare -A PIDS=()

for NAME in "阿里云" "腾讯云" "字节跳动" "华为云" "Google Cloud"; do
    URL="${SOURCES[$NAME]}"
    SAFE_NAME="$(echo "$NAME" | tr ' ' '_')"
    TMPFILE="$TEMP_DIR/${SAFE_NAME}.txt"
    curl -sfL --max-time 15 "$URL" -o "$TMPFILE" &
    PIDS["isp_${SAFE_NAME}"]=$!
done

for NAME in "UCloud" "Azure" "DigitalOcean" "Vultr"; do
    ASN="${ASN_SOURCES[$NAME]}"
    TMPFILE="$TEMP_DIR/${NAME}.json"
    curl -sfL --max-time 20 \
        "https://stat.ripe.net/data/announced-prefixes/data.json?resource=${ASN}" \
        -o "$TMPFILE" &
    PIDS["asn_${NAME}"]=$!
done

AWS_TMP="$TEMP_DIR/aws.json"
curl -sfL --max-time 20 "$AWS_URL" -o "$AWS_TMP" &
PIDS["aws"]=$!

# 等待所有后台任务完成（各自超时独立计时）
declare -A RESULTS=()
for KEY in "${!PIDS[@]}"; do
    if wait "${PIDS[$KEY]}" 2>/dev/null; then
        RESULTS[$KEY]="ok"
    else
        RESULTS[$KEY]="fail"
    fi
done

# ── 拼装输出文件 ────────────────────────────────────────────────────────────

cat > "$OUTPUT_TMP" <<EOF
# 由 update_cloud_geo.sh 自动生成 | $(date '+%Y-%m-%d %H:%M:%S')

limit_req_zone \$binary_remote_addr zone=subscribe_limit:10m rate=20r/m;

geo \$is_cloud_ip {
    default 0;
EOF

TOTAL=0
IPV4_CIDR_RE='^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'

for NAME in "阿里云" "腾讯云" "字节跳动" "华为云" "Google Cloud"; do
    SAFE_NAME="$(echo "$NAME" | tr ' ' '_')"
    TMPFILE="$TEMP_DIR/${SAFE_NAME}.txt"
    CIDRFILE="$TEMP_DIR/${SAFE_NAME}.cidrs"
    KEY="isp_${SAFE_NAME}"
    if [[ "${RESULTS[$KEY]:-fail}" == "ok" ]] && [[ -s "$TMPFILE" ]]; then
        grep -E "$IPV4_CIDR_RE" "$TMPFILE" | sort -u > "$CIDRFILE" || true
        COUNT=$(wc -l < "$CIDRFILE" | tr -d ' ')
        if [[ "$COUNT" -gt 0 ]]; then
            TOTAL=$((TOTAL + COUNT))
            echo "    # === $NAME ===" >> "$OUTPUT_TMP"
            while read -r cidr; do
                echo "    $cidr 1;" >> "$OUTPUT_TMP"
            done < "$CIDRFILE"
            echo "" >> "$OUTPUT_TMP"
            log "  $NAME: ${COUNT} 条"
        else
            log "  [警告] $NAME 未解析到有效 IPv4 CIDR"
        fi
    else
        log "  [警告] $NAME 拉取失败"
    fi
done

for NAME in "UCloud" "Azure" "DigitalOcean" "Vultr"; do
    TMPFILE="$TEMP_DIR/${NAME}.json"
    CIDRFILE="$TEMP_DIR/${NAME}.cidrs"
    KEY="asn_${NAME}"
    if [[ "${RESULTS[$KEY]:-fail}" == "ok" ]] && [[ -s "$TMPFILE" ]]; then
        jq -r '.data.prefixes[]?.prefix // empty' "$TMPFILE" \
            | grep -E "$IPV4_CIDR_RE" | sort -u > "$CIDRFILE" || true
        COUNT=$(wc -l < "$CIDRFILE" | tr -d ' ')
        if [[ "$COUNT" -gt 0 ]]; then
            TOTAL=$((TOTAL + COUNT))
            echo "    # === $NAME ===" >> "$OUTPUT_TMP"
            while read -r cidr; do
                echo "    $cidr 1;" >> "$OUTPUT_TMP"
            done < "$CIDRFILE"
            echo "" >> "$OUTPUT_TMP"
            log "  $NAME: ${COUNT} 条"
        else
            log "  [警告] $NAME 未解析到有效 IPv4 CIDR"
        fi
    else
        log "  [警告] $NAME 拉取失败"
    fi
done

if [[ "${RESULTS[aws]:-fail}" == "ok" ]] && [[ -s "$AWS_TMP" ]]; then
    AWS_CIDRS="$TEMP_DIR/aws.cidrs"
    jq -r '.prefixes[]?.ip_prefix // empty' "$AWS_TMP" \
        | grep -E "$IPV4_CIDR_RE" | sort -u > "$AWS_CIDRS" || true
    AWS_COUNT=$(wc -l < "$AWS_CIDRS" | tr -d ' ')
    if [[ "$AWS_COUNT" -gt 0 ]]; then
        TOTAL=$((TOTAL + AWS_COUNT))
        echo "    # === AWS ===" >> "$OUTPUT_TMP"
        while read -r cidr; do
            echo "    $cidr 1;" >> "$OUTPUT_TMP"
        done < "$AWS_CIDRS"
        log "  AWS: ${AWS_COUNT} 条"
        echo "" >> "$OUTPUT_TMP"
    else
        log "  [警告] AWS 未解析到有效 IPv4 CIDR"
    fi
else
    log "  [警告] AWS 拉取失败"
fi

cat >> "$OUTPUT_TMP" <<'EOF'
}

map $http_user_agent $bad_subscribe_ua {
    default                    0;
    ""                         1;
    "clash"                    1;
    "~^curl/"                  1;
    "~^python"                 1;
    "~^wget"                   1;
    "~^Go-http-client"         1;
    "~^Java/"                  1;
    "~^libcurl"                1;
    "~^axios"                  1;
    "~^node-fetch"             1;
    "~^okhttp/3\.(12|13|14)\." 1;
}
EOF

log "共 $TOTAL 条CIDR"

if [[ "$TOTAL" -eq 0 && -s "$OUTPUT" ]]; then
    log "❌ 所有云 IP 数据源均无有效结果，已保留上一版规则"
    exit 1
fi
if [[ "$TOTAL" -eq 0 ]]; then
    log "[警告] 首次启动未取得云 IP 数据，将使用最小规则并在下次周期重试"
fi

# 先在隔离的 http 上下文中检查候选文件，避免无效规则替换当前配置。
cat > "$TEST_CONF" <<EOF
pid $TEMP_DIR/nginx.pid;
error_log stderr emerg;
events {}
http {
    include $OUTPUT_TMP;
}
EOF

if ! nginx -t -c "$TEST_CONF" -p /tmp >/dev/null 2>&1; then
    log "❌ 候选云 IP 配置语法无效，已保留上一版规则"
    exit 1
fi

if [[ -s "$OUTPUT" ]]; then
    cp -p "$OUTPUT" "$OUTPUT_BACKUP"
    HAD_PREVIOUS=1
fi

# 原子替换后再检查完整配置；任何失败都会恢复上一版。
mv "$OUTPUT_TMP" "$OUTPUT"

if ! nginx -t >/dev/null 2>&1; then
    restore_previous
    log "❌ Nginx 完整配置测试失败，已恢复上一版云 IP 规则"
    exit 1
fi

if [[ "$SKIP_NGINX_RELOAD" != "1" ]]; then
    if nginx -s reload >/dev/null 2>&1; then
        log "✅ Nginx 重载成功，云 IP 规则已生效"
    else
        restore_previous
        nginx -t >/dev/null 2>&1 && nginx -s reload >/dev/null 2>&1 || true
        log "❌ Nginx 重载失败，已恢复并尝试重载上一版云 IP 规则"
        exit 1
    fi
else
    log "✅ 云 IP 规则已通过启动前配置检查"
fi

rm -f "$OUTPUT_BACKUP"
log "完成。"
