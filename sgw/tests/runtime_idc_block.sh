#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CONTAINER="${GATEWAY_CONTAINER:-subscribe-gateway}"
TEST_PREFIX="/tmp/subsieve-idc-runtime"
TEST_CONF="$TEST_PREFIX/nginx.conf"

cleanup() {
    if docker exec "$CONTAINER" test -s "$TEST_PREFIX/nginx.pid" 2>/dev/null; then
        docker exec "$CONTAINER" nginx -s quit -p "$TEST_PREFIX/" -c "$TEST_CONF" >/dev/null 2>&1 || true
    fi
    docker exec "$CONTAINER" rm -rf "$TEST_PREFIX" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]]; then
    echo "Gateway container is not running: $CONTAINER" >&2
    exit 1
fi

TEST_IP=$(docker exec "$CONTAINER" awk '
    /# === AWS ===/ { in_aws = 1; next }
    in_aws && $1 ~ /^[0-9]/ { split($1, cidr, "/"); print cidr[1]; exit }
' /etc/nginx/subscribe/cloud_geo.conf)
PROTECTED_PATH=$(docker exec "$CONTAINER" awk '
    $1 == "location" && $2 == "^~" { print $3; exit }
' /etc/nginx/subscribe/protect.conf)

if [[ -z "$TEST_IP" || -z "$PROTECTED_PATH" ]]; then
    echo "Unable to locate an AWS CIDR or protected subscription path" >&2
    exit 1
fi

docker exec "$CONTAINER" mkdir -p "$TEST_PREFIX"
docker cp "$SCRIPT_DIR/nginx/idc_runtime.conf" "$CONTAINER:$TEST_CONF" >/dev/null
docker exec "$CONTAINER" nginx -t -p "$TEST_PREFIX/" -c "$TEST_CONF" >/dev/null
docker exec "$CONTAINER" nginx -p "$TEST_PREFIX/" -c "$TEST_CONF"
sleep 1

RESULT=$(docker exec "$CONTAINER" curl -sS --max-time 3 \
    -A clash \
    -H "X-SubSieve-Test-IP: $TEST_IP" \
    -w $'\n%{http_code}' \
    "http://127.0.0.1:18080${PROTECTED_PATH}?token=runtime-idc-test")
STATUS=${RESULT##*$'\n'}
BODY=${RESULT%$'\n'*}

if [[ "$STATUS" != "403" || "$BODY" != *"Forbidden: Cloud IP"* ]]; then
    echo "IDC runtime test failed: ip=$TEST_IP status=$STATUS body=$BODY" >&2
    exit 1
fi

echo "IDC runtime test passed: ip=$TEST_IP status=403 reason=cloud"
