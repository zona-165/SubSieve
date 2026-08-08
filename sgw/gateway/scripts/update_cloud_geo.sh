#!/bin/bash
set -euo pipefail

SUBSCRIBE_DIR="${SUBSCRIBE_DIR:-/etc/nginx/subscribe}"
OUTPUT="$SUBSCRIBE_DIR/cloud_geo.conf"
OUTPUT_TMP="${OUTPUT}.tmp"
OUTPUT_BACKUP="${OUTPUT}.bak.$$"
CATALOG="$SUBSCRIBE_DIR/cloud_provider_catalog.json"
SETTINGS="$SUBSCRIBE_DIR/cloud_provider_settings.json"
STATE="$SUBSCRIBE_DIR/cloud_provider_state.json"
STATUS="$SUBSCRIBE_DIR/cloud_provider_status.json"
CACHE_DIR="$SUBSCRIBE_DIR/cloud_provider_cidrs"
LOG_FILE="${CLOUD_PROVIDER_LOG_FILE:-/var/log/subscribe/update_cloud_geo.log}"
NGINX_BIN="${NGINX_BIN:-nginx}"
TEMP_DIR=$(mktemp -d)
TEST_CONF="$TEMP_DIR/nginx-cloud-candidate.conf"
SKIP_NGINX_RELOAD="${SKIP_NGINX_RELOAD:-0}"
HAD_PREVIOUS=0

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
write_status() {
    local state="$1" message="$2"
    local tmp="${STATUS}.tmp.$$"
    jq -n --arg status "$state" --arg message "$message" --arg updated_at "$(date '+%Y-%m-%d %H:%M:%S')" \
        '{status:$status,message:$message,updated_at:$updated_at}' > "$tmp"
    chmod 666 "$tmp"
    mv -f "$tmp" "$STATUS"
}
cleanup() { rm -rf "$TEMP_DIR" "$OUTPUT_TMP" "$OUTPUT_BACKUP" 2>/dev/null || true; }
restore_previous() {
    if [[ "$HAD_PREVIOUS" == "1" && -s "$OUTPUT_BACKUP" ]]; then
        mv -f "$OUTPUT_BACKUP" "$OUTPUT"
    else
        rm -f "$OUTPUT"
    fi
}
on_error() {
    local code="$1" line="$2"
    write_status "error" "规则更新失败（line ${line}, exit ${code}），继续使用上一版规则" || true
}
trap 'on_error $? $LINENO' ERR
trap cleanup EXIT

mkdir -p "$CACHE_DIR"
chmod 777 "$CACHE_DIR"
write_status "updating" "正在拉取并校验云厂商 CIDR"

# 完整目录同时供后台展示。default_enabled=true 的厂商沿用升级前已有的拦截范围；
# 新增厂商默认关闭，由管理员确认后再启用，避免升级时意外扩大拦截面。
cat > "$TEMP_DIR/catalog.json" <<'JSON'
{
  "version": 1,
  "providers": [
    {"id":"aliyun","name":"阿里云","asns":["AS45102","AS37963","AS134963"],"keywords":["阿里云","阿里云计算","alibabacloud","alicloud","aliyun"],"default_enabled":true,"source":{"type":"text","url":"https://metowolf.github.io/iplist/data/isp/aliyun.txt"}},
    {"id":"tencent_cloud","name":"腾讯云","asns":["AS45090","AS132203"],"keywords":["腾讯云","腾讯云计算","tencent cloud","qcloud"],"default_enabled":true,"source":{"type":"text","url":"https://metowolf.github.io/iplist/data/isp/tencent.txt"}},
    {"id":"volcengine","name":"火山引擎 / 字节跳动","asns":["AS137718","AS150436"],"keywords":["火山引擎","volcengine","byteplus","bytedance cloud"],"default_enabled":true,"source":{"type":"text","url":"https://metowolf.github.io/iplist/data/isp/bytedance.txt"}},
    {"id":"huawei_cloud","name":"华为云","asns":["AS136907","AS55990"],"keywords":["华为云","huawei cloud"],"default_enabled":true,"source":{"type":"text","url":"https://metowolf.github.io/iplist/data/isp/huawei.txt"}},
    {"id":"google_cloud","name":"Google Cloud","asns":["AS15169","AS396982"],"keywords":["google cloud","google llc"],"default_enabled":true,"source":{"type":"text","url":"https://metowolf.github.io/iplist/data/isp/googlecloud.txt"}},
    {"id":"aws","name":"Amazon Web Services","asns":["AS16509","AS14618"],"keywords":["amazon web services","amazon technologies","amazon data services","amazon.com, inc."],"default_enabled":true,"source":{"type":"aws","url":"https://ip-ranges.amazonaws.com/ip-ranges.json"}},
    {"id":"ucloud","name":"UCloud","asns":["AS59077","AS135377"],"keywords":["ucloud","ucloud information technology","优刻得"],"default_enabled":true,"source":{"type":"asn"}},
    {"id":"azure","name":"Microsoft Azure","asns":["AS8075"],"keywords":["microsoft azure","microsoft corporation"],"default_enabled":true,"source":{"type":"asn"}},
    {"id":"digitalocean","name":"DigitalOcean","asns":["AS14061","AS62567","AS393406"],"keywords":["digitalocean"],"default_enabled":true,"source":{"type":"asn"}},
    {"id":"vultr","name":"Vultr","asns":["AS20473"],"keywords":["vultr","choopa","the constant company"],"default_enabled":true,"source":{"type":"asn"}},
    {"id":"akamai_linode","name":"Akamai / Linode","asns":["AS63949"],"keywords":["akamai connected cloud","linode"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"contabo","name":"Contabo","asns":["AS51167","AS40021","AS141995"],"keywords":["contabo"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"hetzner","name":"Hetzner","asns":["AS24940","AS213230"],"keywords":["hetzner online","hetzner"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"ibm_cloud","name":"IBM Cloud / SoftLayer","asns":["AS36351"],"keywords":["ibm cloud","softlayer"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"leaseweb","name":"Leaseweb","asns":["AS60781","AS28753","AS59253","AS7203","AS30633"],"keywords":["leaseweb"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"oracle_cloud","name":"Oracle Cloud","asns":["AS31898","AS7160"],"keywords":["oracle cloud","oracle corporation"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"ovhcloud","name":"OVHcloud","asns":["AS16276"],"keywords":["ovhcloud","ovh sas"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"scaleway","name":"Scaleway","asns":["AS12876"],"keywords":["scaleway","online s.a.s."],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"jd_cloud","name":"京东云","asns":["AS13486"],"keywords":["京东云","jd cloud","jdcloud","jingdong cloud"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"ctyun","name":"天翼云","asns":["AS58519"],"keywords":["天翼云","ctyun","china telecom cloud"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"inspur_cloud","name":"浪潮云","asns":[],"keywords":["浪潮云","inspur cloud"],"default_enabled":false,"source":{"type":"none"}},
    {"id":"baidu_cloud","name":"百度智能云","asns":["AS38365","AS55967"],"keywords":["百度智能云","百度云计算","baidu ai cloud","baidu cloud"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"china_mobile_cloud","name":"移动云","asns":[],"keywords":["移动云","china mobile cloud","cmcc cloud"],"default_enabled":false,"source":{"type":"none"}},
    {"id":"china_unicom_cloud","name":"联通云","asns":["AS10206"],"keywords":["联通云","china unicom cloud","unicom cloud"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"kingsoft_cloud","name":"金山云","asns":["AS59019"],"keywords":["金山云","kingsoft cloud","ksyun"],"default_enabled":false,"source":{"type":"asn"}},
    {"id":"qingcloud","name":"青云 QingCloud","asns":["AS59078","AS134366"],"keywords":["青云科技","qingcloud","yunify"],"default_enabled":false,"source":{"type":"asn"}}
  ]
}
JSON
jq -e '.version == 1 and (.providers | type == "array")' "$TEMP_DIR/catalog.json" >/dev/null
chmod 666 "$TEMP_DIR/catalog.json"
mv -f "$TEMP_DIR/catalog.json" "$CATALOG"

if [[ ! -s "$SETTINGS" ]] || ! jq -e 'type == "object" and ((.enabled // {}) | type == "object")' "$SETTINGS" >/dev/null 2>&1; then
    jq '{version:1,enabled:(.providers | map({key:.id,value:.default_enabled}) | from_entries),updated_at:null}' \
        "$CATALOG" > "$TEMP_DIR/settings.json"
    chmod 666 "$TEMP_DIR/settings.json"
    mv -f "$TEMP_DIR/settings.json" "$SETTINGS"
fi

# 旧版本只有一个合并配置。首次升级先按旧注释拆分成厂商缓存，
# 即使本轮外部数据源短暂失败，也不会让原有厂商规则从生效配置中消失。
seed_legacy_cache() {
    local id="$1" legacy_name="$2"
    local target="$CACHE_DIR/${id}.cidrs"
    [[ -s "$target" || ! -s "$OUTPUT" ]] && return 0
    awk -v id="$id" -v legacy="$legacy_name" '
        {
            line=$0
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
            if (line ~ /^# === /) {
                active=(line == "# === " legacy " ===" || line ~ ("\\[" id "\\] ===$"))
                next
            }
            if (active && $1 ~ /^[0-9]+(\.[0-9]+){3}\/[0-9]+$/) print $1
        }
    ' "$OUTPUT" | sort -u > "${target}.tmp"
    if [[ -s "${target}.tmp" ]]; then
        chmod 666 "${target}.tmp"
        mv -f "${target}.tmp" "$target"
    else
        rm -f "${target}.tmp"
    fi
}

seed_legacy_cache "aliyun" "阿里云"
seed_legacy_cache "tencent_cloud" "腾讯云"
seed_legacy_cache "volcengine" "字节跳动"
seed_legacy_cache "huawei_cloud" "华为云"
seed_legacy_cache "google_cloud" "Google Cloud"
seed_legacy_cache "aws" "AWS"
seed_legacy_cache "ucloud" "UCloud"
seed_legacy_cache "azure" "Azure"
seed_legacy_cache "digitalocean" "DigitalOcean"
seed_legacy_cache "vultr" "Vultr"

log "开始并行拉取云厂商 IP 段..."
mapfile -t PROVIDER_ROWS < <(jq -r '.providers[] | @base64' "$CATALOG")
declare -A PIDS=()

for encoded in "${PROVIDER_ROWS[@]}"; do
    row=$(printf '%s' "$encoded" | base64 -d)
    id=$(jq -r '.id' <<<"$row")
    source_type=$(jq -r '.source.type // "none"' <<<"$row")
    source_url=$(jq -r '.source.url // empty' <<<"$row")
    if [[ -n "$source_url" && "$source_type" != "none" ]]; then
        curl -sfL --max-time 20 "$source_url" -o "$TEMP_DIR/${id}.source" &
        PIDS["source_${id}"]=$!
    fi
    if [[ "$source_type" == "asn" ]]; then
        while IFS= read -r asn; do
            [[ -n "$asn" ]] || continue
            curl -sfL --max-time 20 \
                "https://stat.ripe.net/data/announced-prefixes/data.json?resource=${asn}" \
                -o "$TEMP_DIR/${id}.${asn}.json" &
            PIDS["asn_${id}_${asn}"]=$!
        done < <(jq -r '.asns[]?' <<<"$row")
    fi
done

for key in "${!PIDS[@]}"; do
    wait "${PIDS[$key]}" 2>/dev/null || true
done

cat > "$OUTPUT_TMP" <<EOF
# 由 update_cloud_geo.sh 自动生成 | $(date '+%Y-%m-%d %H:%M:%S')

limit_req_zone \$binary_remote_addr zone=subscribe_limit:10m rate=20r/m;

geo \$cloud_provider_id {
    default "";
EOF

TOTAL=0
ENABLED_COUNT=0
STATE_ROWS="$TEMP_DIR/provider-state.ndjson"
: > "$STATE_ROWS"
IPV4_CIDR_RE='^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'
declare -A SEEN_CIDR=()

for encoded in "${PROVIDER_ROWS[@]}"; do
    row=$(printf '%s' "$encoded" | base64 -d)
    id=$(jq -r '.id' <<<"$row")
    name=$(jq -r '.name' <<<"$row")
    source_type=$(jq -r '.source.type // "none"' <<<"$row")
    default_enabled=$(jq -r '.default_enabled == true' <<<"$row")
    enabled=$(jq -r --arg id "$id" --argjson fallback "$default_enabled" '.enabled[$id] // $fallback' "$SETTINGS")
    cidr_file="$TEMP_DIR/${id}.cidrs"
    : > "$cidr_file"

    if [[ "$source_type" == "text" && -s "$TEMP_DIR/${id}.source" ]]; then
        grep -E "$IPV4_CIDR_RE" "$TEMP_DIR/${id}.source" >> "$cidr_file" || true
    elif [[ "$source_type" == "aws" && -s "$TEMP_DIR/${id}.source" ]] \
        && jq -e '.prefixes | type == "array"' "$TEMP_DIR/${id}.source" >/dev/null 2>&1; then
        jq -r '.prefixes[]?.ip_prefix // empty' "$TEMP_DIR/${id}.source" \
            | grep -E "$IPV4_CIDR_RE" >> "$cidr_file" || true
    elif [[ "$source_type" == "asn" ]]; then
        while IFS= read -r asn; do
            [[ -s "$TEMP_DIR/${id}.${asn}.json" ]] || continue
            jq -e '.data.prefixes | type == "array"' "$TEMP_DIR/${id}.${asn}.json" >/dev/null 2>&1 || continue
            jq -r '.data.prefixes[]?.prefix // empty' "$TEMP_DIR/${id}.${asn}.json" \
                | grep -E "$IPV4_CIDR_RE" >> "$cidr_file" || true
        done < <(jq -r '.asns[]?' <<<"$row")
    fi
    sort -u "$cidr_file" > "${cidr_file}.sorted"
    mv -f "${cidr_file}.sorted" "$cidr_file"

    if [[ -s "$cidr_file" ]]; then
        cp "$cidr_file" "$CACHE_DIR/${id}.cidrs.tmp"
        chmod 666 "$CACHE_DIR/${id}.cidrs.tmp"
        mv -f "$CACHE_DIR/${id}.cidrs.tmp" "$CACHE_DIR/${id}.cidrs"
    elif [[ -s "$CACHE_DIR/${id}.cidrs" ]]; then
        cp "$CACHE_DIR/${id}.cidrs" "$cidr_file"
        log "  [缓存] $name：本次拉取无结果，沿用上一版 CIDR"
    fi

    count=$(wc -l < "$cidr_file" | tr -d ' ')
    active_count=0
    if [[ "$enabled" == "true" && "$count" -gt 0 ]]; then
        ENABLED_COUNT=$((ENABLED_COUNT + 1))
        echo "    # === $name [$id] ===" >> "$OUTPUT_TMP"
        while IFS= read -r cidr; do
            [[ -n "$cidr" ]] || continue
            if [[ -n "${SEEN_CIDR[$cidr]:-}" ]]; then
                continue
            fi
            SEEN_CIDR[$cidr]="$id"
            echo "    $cidr $id;" >> "$OUTPUT_TMP"
            active_count=$((active_count + 1))
        done < "$cidr_file"
        echo "" >> "$OUTPUT_TMP"
        TOTAL=$((TOTAL + active_count))
        log "  [启用] $name：${active_count} 条生效（${count} 条可用）"
    elif [[ "$enabled" == "true" ]]; then
        ENABLED_COUNT=$((ENABLED_COUNT + 1))
        log "  [警告] $name 已启用，但没有可用 IPv4 CIDR"
    else
        log "  [关闭] $name：${count} 条可用"
    fi

    active=false
    if [[ "$enabled" == "true" && "$active_count" -gt 0 ]]; then
        active=true
    fi
    jq -n --arg id "$id" --arg name "$name" --argjson enabled "$enabled" --argjson active "$active" \
        --argjson cidr_count "$count" --argjson active_count "$active_count" \
        '{id:$id,name:$name,enabled:$enabled,active:$active,cidr_count:$cidr_count,active_count:$active_count}' \
        >> "$STATE_ROWS"
done

cat >> "$OUTPUT_TMP" <<'EOF'
}

map $cloud_provider_id $is_cloud_ip {
    default 0;
    "~.+" 1;
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

log "共 $TOTAL 条已启用 CIDR"
if [[ "$TOTAL" -eq 0 && "$ENABLED_COUNT" -gt 0 && -s "$OUTPUT" ]]; then
    log "❌ 所有已启用厂商均无有效结果，已保留上一版规则"
    write_status "error" "已启用厂商没有可用 CIDR，继续使用上一版规则"
    exit 1
fi
if [[ "$TOTAL" -eq 0 ]]; then
    log "[警告] 当前没有启用且可用的云厂商 CIDR，将使用最小规则"
fi

cat > "$TEST_CONF" <<EOF
pid $TEMP_DIR/nginx.pid;
error_log stderr emerg;
events {}
http {
    include $OUTPUT_TMP;
}
EOF

if ! "$NGINX_BIN" -t -c "$TEST_CONF" -p /tmp >/dev/null 2>&1; then
    log "❌ 候选云 IP 配置语法无效，已保留上一版规则"
    write_status "error" "候选云厂商配置校验失败，继续使用上一版规则"
    exit 1
fi

if [[ -s "$OUTPUT" ]]; then
    cp -p "$OUTPUT" "$OUTPUT_BACKUP"
    HAD_PREVIOUS=1
fi
mv "$OUTPUT_TMP" "$OUTPUT"

if ! "$NGINX_BIN" -t >/dev/null 2>&1; then
    restore_previous
    log "❌ Nginx 完整配置测试失败，已恢复上一版云 IP 规则"
    write_status "error" "Nginx 配置校验失败，已恢复上一版规则"
    exit 1
fi

if [[ "$SKIP_NGINX_RELOAD" != "1" ]]; then
    if "$NGINX_BIN" -s reload >/dev/null 2>&1; then
        log "✅ Nginx 重载成功，云 IP 规则已生效"
    else
        restore_previous
        "$NGINX_BIN" -t >/dev/null 2>&1 && "$NGINX_BIN" -s reload >/dev/null 2>&1 || true
        log "❌ Nginx 重载失败，已恢复并尝试重载上一版云 IP 规则"
        write_status "error" "Nginx 重载失败，已恢复上一版规则"
        exit 1
    fi
else
    log "✅ 云 IP 规则已通过启动前配置检查"
fi

jq -s --arg generated_at "$(date '+%Y-%m-%d %H:%M:%S')" --argjson total "$TOTAL" \
    '{status:"ready",generated_at:$generated_at,total_active_cidrs:$total,providers:(map({key:.id,value:.}) | from_entries)}' \
    "$STATE_ROWS" > "$TEMP_DIR/state.json"
chmod 666 "$OUTPUT" "$CATALOG" "$SETTINGS" "$TEMP_DIR/state.json"
mv -f "$TEMP_DIR/state.json" "$STATE"
rm -f "$OUTPUT_BACKUP"
write_status "ready" "云厂商规则已应用，共 ${TOTAL} 条 CIDR"
log "完成。"
