#!/usr/bin/env bash
set -euo pipefail

unset \
    LOCALZET_TEST_RUNTIME_HOOK_THROW \
    LOCALZET_TEST_RUNTIME_HOOK_USER \
    LOCALZET_TEST_RUNTIME_HOOK_UPGRADE \
    LOCALZET_TEST_RUNTIME_HOOK_EXIT_86 \
    LOCALZET_TEST_RUNTIME_HOOK_WORKERS

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RUNTIME_DIR="$(mktemp -d "${TMPDIR:-/tmp}/localzet-runtime-hooks.XXXXXX")"
PORT=$((39000 + ($$ % 10000)))
export LOCALZET_TEST_RUNTIME_HOOK_DIR="$RUNTIME_DIR"
export LOCALZET_TEST_RUNTIME_HOOK_PORT="$PORT"
FIXTURE='tests/live_runtime_hooks.php'
SERVER_PID=''

cleanup() {
    php "$FIXTURE" stop >/dev/null 2>&1 || true
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
    rm -rf "$RUNTIME_DIR"
}
trap cleanup EXIT

php "$FIXTURE" start >"$RUNTIME_DIR/start.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 100); do
    worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" 2>/dev/null || true)"
    if [[ "$worker_count" -ge 2 ]]; then
        break
    fi
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        cat "$RUNTIME_DIR/start.log"
        echo 'Runtime hook fixture exited before workers started.' >&2
        exit 1
    fi
    sleep 0.05
done

before_count="$(grep -c '^before:' "$RUNTIME_DIR/trace.log" || true)"
worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$before_count" -ne 1 || "$worker_count" -ne 2 ]]; then
    cat "$RUNTIME_DIR/start.log"
    echo "Unexpected lifecycle callback counts: before=$before_count workers=$worker_count" >&2
    exit 1
fi

php "$FIXTURE" status --json >/dev/null
after_status_count="$(grep -c '^before:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$after_status_count" -ne "$before_count" ]]; then
    echo 'A control command invoked the start callback.' >&2
    exit 1
fi

for _ in $(seq 1 100); do
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        break
    fi
    sleep 0.05
done
if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    cat "$RUNTIME_DIR/start.log"
    echo 'Worker shutdown did not stop the master process group.' >&2
    exit 1
fi
wait "$SERVER_PID"
SERVER_PID=''

server_start_count="$(grep -c '^server-start:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$server_start_count" -ne 2 ]]; then
    echo "Expected two server start callbacks, got $server_start_count." >&2
    exit 1
fi

rm -rf "$RUNTIME_DIR"
RUNTIME_DIR="$(mktemp -d "${TMPDIR:-/tmp}/localzet-runtime-hooks-fail.XXXXXX")"
export LOCALZET_TEST_RUNTIME_HOOK_DIR="$RUNTIME_DIR"
export LOCALZET_TEST_RUNTIME_HOOK_PORT="$((PORT + 1))"
export LOCALZET_TEST_RUNTIME_HOOK_WORKERS=1
export LOCALZET_TEST_RUNTIME_HOOK_THROW=1
if [[ "$(id -u)" -eq 0 ]] && getent passwd nobody >/dev/null 2>&1; then
    export LOCALZET_TEST_RUNTIME_HOOK_USER=nobody
fi

php "$FIXTURE" start >"$RUNTIME_DIR/start.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 100); do
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        break
    fi
    sleep 0.05
done
if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    cat "$RUNTIME_DIR/start.log"
    echo 'A failing worker-start callback did not stop the master process group.' >&2
    exit 1
fi
wait "$SERVER_PID"
SERVER_PID=''

before_count="$(grep -c '^before:' "$RUNTIME_DIR/trace.log" || true)"
worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" || true)"
server_start_count="$(grep -c '^server-start:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$before_count" -ne 1 || "$worker_count" -ne 1 || "$server_start_count" -ne 0 ]]; then
    cat "$RUNTIME_DIR/start.log"
    echo "Failing callback did not fail closed: before=$before_count workers=$worker_count server_start=$server_start_count" >&2
    exit 1
fi

rm -rf "$RUNTIME_DIR"
unset LOCALZET_TEST_RUNTIME_HOOK_THROW LOCALZET_TEST_RUNTIME_HOOK_USER
HOT_UPGRADE_SUPPORTED="$(php tests/runtime_capabilities.php --print-hot-upgrade)"
if [[ "$HOT_UPGRADE_SUPPORTED" == "1" ]]; then
RUNTIME_DIR="$(mktemp -d "${TMPDIR:-/tmp}/localzet-runtime-hooks-upgrade.XXXXXX")"
export LOCALZET_TEST_RUNTIME_HOOK_DIR="$RUNTIME_DIR"
export LOCALZET_TEST_RUNTIME_HOOK_PORT="$((PORT + 2))"
export LOCALZET_TEST_RUNTIME_HOOK_WORKERS=2
export LOCALZET_TEST_RUNTIME_HOOK_UPGRADE=1

php "$FIXTURE" start >"$RUNTIME_DIR/start.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 100); do
    worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" 2>/dev/null || true)"
    if [[ "$worker_count" -ge 2 ]]; then
        break
    fi
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        cat "$RUNTIME_DIR/start.log"
        echo 'Hot-upgrade fixture exited before workers started.' >&2
        exit 1
    fi
    sleep 0.05
done

worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$worker_count" -ne 2 ]]; then
    cat "$RUNTIME_DIR/start.log"
    echo "Expected two hot-upgrade fixture workers, got $worker_count." >&2
    exit 1
fi

php "$FIXTURE" upgrade >/dev/null
for _ in $(seq 1 160); do
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        break
    fi
    sleep 0.05
done
if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    cat "$RUNTIME_DIR/start.log"
    echo 'An old worker shutdown request did not stop the upgraded master.' >&2
    exit 1
fi
wait "$SERVER_PID"
SERVER_PID=''

before_count="$(grep -c '^before:' "$RUNTIME_DIR/trace.log" || true)"
reload_count="$(grep -c '^reload:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$before_count" -ne 2 || "$reload_count" -lt 1 ]] || ! grep -q '^reload:.*:1$' "$RUNTIME_DIR/trace.log"; then
    cat "$RUNTIME_DIR/start.log"
    echo "Old-generation shutdown callback was not observed: before=$before_count reloads=$reload_count" >&2
    exit 1
fi

rm -rf "$RUNTIME_DIR"
else
    echo 'HOT_UPGRADE_IPC=skipped (PHP socket close-on-exec flags are unavailable)'
fi

RUNTIME_DIR="$(mktemp -d "${TMPDIR:-/tmp}/localzet-runtime-hooks-exit86.XXXXXX")"
export LOCALZET_TEST_RUNTIME_HOOK_DIR="$RUNTIME_DIR"
export LOCALZET_TEST_RUNTIME_HOOK_PORT="$((PORT + 3))"
export LOCALZET_TEST_RUNTIME_HOOK_WORKERS=1
export LOCALZET_TEST_RUNTIME_HOOK_EXIT_86=1
unset LOCALZET_TEST_RUNTIME_HOOK_UPGRADE

php "$FIXTURE" start >"$RUNTIME_DIR/start.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 200); do
    worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" 2>/dev/null || true)"
    if [[ "$worker_count" -ge 2 ]]; then
        break
    fi
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        cat "$RUNTIME_DIR/start.log"
        echo 'Application exit 86 incorrectly stopped the server group.' >&2
        exit 1
    fi
    sleep 0.05
done

worker_count="$(grep -c '^worker:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$worker_count" -lt 2 ]]; then
    cat "$RUNTIME_DIR/start.log"
    echo 'Application exit 86 did not follow the normal worker restart path.' >&2
    exit 1
fi

for _ in $(seq 1 160); do
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        break
    fi
    sleep 0.05
done
if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    cat "$RUNTIME_DIR/start.log"
    echo 'The restarted worker did not stop the server group.' >&2
    exit 1
fi
wait "$SERVER_PID"
SERVER_PID=''

before_count="$(grep -c '^before:' "$RUNTIME_DIR/trace.log" || true)"
server_start_count="$(grep -c '^server-start:' "$RUNTIME_DIR/trace.log" || true)"
if [[ "$before_count" -ne 1 || "$server_start_count" -ne 1 ]]; then
    cat "$RUNTIME_DIR/start.log"
    echo "Exit 86 changed the group lifecycle: before=$before_count server_start=$server_start_count" >&2
    exit 1
fi

if [[ "$HOT_UPGRADE_SUPPORTED" == "1" ]]; then
    echo 'Runtime lifecycle hooks, hot-upgrade IPC, and worker exit handling passed.'
else
    echo 'Runtime lifecycle hooks and worker exit handling passed; hot-upgrade IPC is unavailable.'
fi
