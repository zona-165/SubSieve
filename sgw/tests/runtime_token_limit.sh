#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CONTAINER="${GATEWAY_CONTAINER:-subscribe-gateway}"
TEST_PREFIX="/tmp/subsieve-token-limit-runtime"
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

docker exec "$CONTAINER" mkdir -p "$TEST_PREFIX"
docker cp "$SCRIPT_DIR/nginx/token_rate_runtime.conf" "$CONTAINER:$TEST_CONF" >/dev/null
printf 'limit_req zone=token_pull_limit burst=9 nodelay;\n' \
    | docker exec -i "$CONTAINER" sh -c "cat > '$TEST_PREFIX/token_limit_apply.conf'"
docker exec "$CONTAINER" nginx -t -p "$TEST_PREFIX/" -c "$TEST_CONF" >/dev/null
docker exec "$CONTAINER" nginx -p "$TEST_PREFIX/" -c "$TEST_CONF"

statuses=()
for _ in $(seq 1 11); do
    statuses+=("$(docker exec "$CONTAINER" curl -sS -o /dev/null -w '%{http_code}' \
        'http://127.0.0.1:18081/subscribe?token=subsieve-runtime-rate-test')")
done

for i in $(seq 0 9); do
    if [[ "${statuses[$i]}" != "204" ]]; then
        echo "Token rate runtime test failed: request=$((i + 1)) status=${statuses[$i]}" >&2
        exit 1
    fi
done
if [[ "${statuses[10]}" != "429" ]]; then
    echo "Token rate runtime test failed: request=11 status=${statuses[10]}" >&2
    exit 1
fi

echo "Token rate runtime test passed: first 10 allowed, request 11 returned 429"
