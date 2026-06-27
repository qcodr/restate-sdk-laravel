#!/usr/bin/env bash
#
# End-to-end test for the Restate Laravel package.
#
# Brings up a real Restate runtime + a minimal Laravel app (which hosts the package's services
# over bidirectional HTTP/2 via `php artisan restate:serve`), registers the deployment, and
# drives invocations through the Restate ingress to prove the whole Laravel integration works
# against a live runtime. Everything is containerized — the only host requirement is Docker.
#
# Usage:
#   tests/e2e/run.sh
#   KEEP_UP=1 tests/e2e/run.sh                       # leave the stack up afterwards
#   RESTATE_IMAGE=restatedev/restate:latest tests/e2e/run.sh
#
# Env:
#   RESTATE_IMAGE  runtime image (bidi needs >= 1.7)   (compose default 1.7.0)
#   INGRESS        ingress base URL                     (default http://localhost:8080)
#   ADMIN          admin base URL                       (default http://localhost:9070)
#   KEEP_UP        1 = leave the stack running          (default 0)

set -euo pipefail

INGRESS="${INGRESS:-http://localhost:8080}"
ADMIN="${ADMIN:-http://localhost:9070}"
DEPLOYMENT_URI="${DEPLOYMENT_URI:-http://laravel-e2e:9080}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="$ROOT/docker-compose.e2e.yml"
COMPOSE=(docker compose -f "$COMPOSE_FILE")
cd "$ROOT"

FAILURES=0

log()  { printf '\n=== %s ===\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

assert_contains() { # label haystack needle
    if [[ "$2" == *"$3"* ]]; then pass "$1 -> '$3'"; else fail "$1 (expected to contain '$3', got: $2)"; fi
}

assert_equals() { # label actual expected
    local actual; actual="$(printf '%s' "$2" | tr -d '[:space:]')"
    if [[ "$actual" == "$3" ]]; then pass "$1 -> '$3'"; else fail "$1 (expected '$3', got '$actual')"; fi
}

cleanup() {
    local status=$?
    if [ "$status" -ne 0 ] || [ "$FAILURES" -ne 0 ]; then
        log "laravel-e2e logs (tail)"
        "${COMPOSE[@]}" logs --tail=60 laravel-e2e 2>/dev/null || true
    fi
    if [ "${KEEP_UP:-0}" != "1" ]; then
        log "Tearing down"
        "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
    else
        echo "KEEP_UP=1 — stack left running ('docker compose -f docker-compose.e2e.yml down -v' to stop)."
    fi
}
trap cleanup EXIT

log "Building + starting Restate + the Laravel app"
"${COMPOSE[@]}" up -d --build

log "Waiting for runtime health (admin + ingress)"
healthy=0
for _ in $(seq 1 90); do
    if curl -fsS "$ADMIN/health" >/dev/null 2>&1 && curl -fsS "$INGRESS/restate/health" >/dev/null 2>&1; then
        healthy=1; echo "runtime healthy"; break
    fi
    sleep 2
done
[ "$healthy" -eq 1 ] || { echo "Restate runtime did not become healthy" >&2; exit 1; }

log "Registering deployment over bidi (HTTP/2, no use_http_11): $DEPLOYMENT_URI"
# The amphp host may still be booting; retry until discovery succeeds. NO use_http_11 — the
# amphp server speaks HTTP/2 cleartext (h2c), which the runtime uses for discovery + bidi.
registered=0
for _ in $(seq 1 45); do
    if curl -fsS -X POST "$ADMIN/deployments" \
        -H 'content-type: application/json' \
        -d "{\"uri\":\"$DEPLOYMENT_URI\",\"force\":true}" >/dev/null 2>&1; then
        registered=1; echo "deployment registered"; break
    fi
    sleep 2
done
[ "$registered" -eq 1 ] || { echo "Failed to register the deployment (discovery never succeeded)" >&2; exit 1; }

# --- Drive invocations through the ingress and assert -----------------------------------------

log "Service: GreeterService/greet"
out="$(curl -s -X POST "$INGRESS/GreeterService/greet" -H 'content-type: application/json' -d '{"name":"world"}')"
assert_contains "service greet" "$out" "Hello world"

log "Virtual Object: CounterObject/acme/add (state increments per key)"
out1="$(curl -s -X POST "$INGRESS/CounterObject/acme/add" -H 'content-type: application/json' -d '{"by":1}')"
assert_equals "object add #1" "$out1" "1"
out2="$(curl -s -X POST "$INGRESS/CounterObject/acme/add" -H 'content-type: application/json' -d '{"by":1}')"
assert_equals "object add #2" "$out2" "2"
outget="$(curl -s -X POST "$INGRESS/CounterObject/acme/get" -H 'content-type: application/json')"
assert_equals "object get (shared)" "$outget" "2"

# A second key must be independent (single-writer state is per key).
outother="$(curl -s -X POST "$INGRESS/CounterObject/globex/add" -H 'content-type: application/json' -d '{"by":5}')"
assert_equals "object add other key" "$outother" "5"

log "Workflow: EchoWorkflow/wf-1/run (durable ctx->run step)"
outwf="$(curl -s -X POST "$INGRESS/EchoWorkflow/wf-1/run" -H 'content-type: application/json' -d '{"value":"hi"}')"
assert_contains "workflow run" "$outwf" "echo:hi"

log "Caller side: resolve RestateClient from the Laravel container and call() the ingress"
if "${COMPOSE[@]}" exec -T laravel-e2e php dispatch-check.php; then
    pass "RestateClient->call('GreeterService','greet',['name'=>'ada']) returned 'Hello ada'"
else
    fail "caller-side dispatch (RestateClient->call) did not return the expected result"
fi

# --- Summary ----------------------------------------------------------------------------------

log "Summary"
if [ "$FAILURES" -eq 0 ]; then
    printf '  \033[32mALL E2E CHECKS PASSED\033[0m\n'
    exit 0
fi
printf '  \033[31m%d E2E CHECK(S) FAILED\033[0m\n' "$FAILURES"
exit 1
