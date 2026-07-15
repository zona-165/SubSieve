#!/bin/bash
set -u

WAIT_SECONDS=30
if [[ "${1:-}" == "--wait" ]]; then
    WAIT_SECONDS="${2:-30}"
fi
if ! [[ "$WAIT_SECONDS" =~ ^[0-9]+$ ]]; then
    echo "等待时间必须是非负整数" >&2
    exit 2
fi

check_container() {
    local name="$1"
    local attempts=$((WAIT_SECONDS / 2 + 1))
    local status="missing"
    local health="missing"

    printf '  %-22s ' "$name"
    for ((i = 0; i < attempts; i++)); do
        status=$(docker inspect --format '{{.State.Status}}' "$name" 2>/dev/null || echo "missing")
        health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null || echo "missing")

        if [[ "$status" == "running" && ( "$health" == "healthy" || "$health" == "none" ) ]]; then
            echo "healthy"
            return 0
        fi
        if [[ "$status" == "missing" || "$status" == "exited" || "$status" == "dead" ]]; then
            break
        fi
        sleep 2
    done

    echo "failed (status=${status}, health=${health})"
    docker logs --tail 30 "$name" 2>&1 | sed 's/^/    /' || true
    return 1
}

ok=true
for container in subscribe-gateway subscribe-admin; do
    if ! check_container "$container"; then
        ok=false
    fi
done

[[ "$ok" == "true" ]]
