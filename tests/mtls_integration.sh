#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2016-2026 Localzet Group. All rights reserved.
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v openssl >/dev/null 2>&1; then
    echo 'mTLS integration requires the OpenSSL CLI.' >&2
    exit 1
fi
if ! php -r 'exit(extension_loaded("openssl") ? 0 : 1);'; then
    echo 'mTLS integration requires the PHP OpenSSL extension.' >&2
    exit 1
fi

umask 077
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/localzet-mtls.XXXXXX")"
SERVER_PID=''

cleanup() {
    php tests/live_mtls_server.php stop >/dev/null 2>&1 || true
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
    rm -rf "$TEST_DIR"
}
trap cleanup EXIT

export LOCALZET_TEST_MTLS_PID_FILE="$TEST_DIR/server.pid"
export LOCALZET_TEST_MTLS_STATUS_FILE="$TEST_DIR/server.status"
export LOCALZET_TEST_MTLS_LOG_FILE="$TEST_DIR/server.log"
export LOCALZET_TEST_MTLS_CONNECTS_FILE="$TEST_DIR/connections.log"
export LOCALZET_TEST_MTLS_HITS_FILE="$TEST_DIR/application-hits.log"
export LOCALZET_TEST_MTLS_ERRORS_FILE="$TEST_DIR/transport-errors.log"
: > "$LOCALZET_TEST_MTLS_CONNECTS_FILE"
: > "$LOCALZET_TEST_MTLS_ERRORS_FILE"

PORT="$(php -r '$socket=stream_socket_server("tcp://127.0.0.1:0", $errno, $error); if (!$socket) exit(1); $address=stream_socket_get_name($socket, false); echo substr(strrchr($address, ":"), 1); fclose($socket);')"
export LOCALZET_TEST_MTLS_PORT="$PORT"

openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
    -keyout "$TEST_DIR/ca.key" -out "$TEST_DIR/ca.pem" \
    -subj '/CN=Localzet mTLS test CA' \
    -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' >/dev/null 2>&1

openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TEST_DIR/server.key" -out "$TEST_DIR/server.csr" \
    -subj '/CN=localhost' >/dev/null 2>&1
cat > "$TEST_DIR/server.ext" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:localhost,IP:127.0.0.1
EOF
openssl x509 -req -in "$TEST_DIR/server.csr" \
    -CA "$TEST_DIR/ca.pem" -CAkey "$TEST_DIR/ca.key" -CAcreateserial \
    -out "$TEST_DIR/server.pem" -days 1 -sha256 -extfile "$TEST_DIR/server.ext" \
    >/dev/null 2>&1

openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TEST_DIR/client.key" -out "$TEST_DIR/client.csr" \
    -subj '/CN=trusted-client' >/dev/null 2>&1
cat > "$TEST_DIR/client.ext" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=clientAuth
EOF
openssl x509 -req -in "$TEST_DIR/client.csr" \
    -CA "$TEST_DIR/ca.pem" -CAkey "$TEST_DIR/ca.key" -CAcreateserial \
    -out "$TEST_DIR/client.pem" -days 1 -sha256 -extfile "$TEST_DIR/client.ext" \
    >/dev/null 2>&1

openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
    -keyout "$TEST_DIR/untrusted-ca.key" -out "$TEST_DIR/untrusted-ca.pem" \
    -subj '/CN=Untrusted mTLS test CA' \
    -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign' >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TEST_DIR/untrusted-client.key" -out "$TEST_DIR/untrusted-client.csr" \
    -subj '/CN=untrusted-client' >/dev/null 2>&1
cat > "$TEST_DIR/untrusted-client.ext" <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=clientAuth
EOF
openssl x509 -req -in "$TEST_DIR/untrusted-client.csr" \
    -CA "$TEST_DIR/untrusted-ca.pem" -CAkey "$TEST_DIR/untrusted-ca.key" -CAcreateserial \
    -out "$TEST_DIR/untrusted-client.pem" -days 1 -sha256 -extfile "$TEST_DIR/untrusted-client.ext" \
    >/dev/null 2>&1

export LOCALZET_SSL_CERT="$TEST_DIR/server.pem"
export LOCALZET_SSL_CERT_KEY="$TEST_DIR/server.key"
export LOCALZET_SSL_VERIFY_PEER=true
export LOCALZET_SSL_CAFILE="$TEST_DIR/ca.pem"

php tests/live_mtls_server.php start >"$TEST_DIR/server-output.log" 2>&1 &
SERVER_PID=$!
READY=0
for _ in $(seq 1 100); do
    if php tests/mtls_client.php health "$PORT" "$TEST_DIR/ca.pem" "$TEST_DIR/client.pem" "$TEST_DIR/client.key" >/dev/null 2>&1; then
        READY=1
        break
    fi
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        cat "$TEST_DIR/server-output.log"
        echo 'mTLS test server exited before it started.' >&2
        exit 1
    fi
    sleep 0.05
done
if [[ "$READY" -ne 1 ]]; then
    cat "$TEST_DIR/server-output.log" >&2
    echo 'mTLS test server did not complete a trusted TLS handshake.' >&2
    exit 1
fi

php tests/mtls_client.php trusted "$PORT" "$TEST_DIR/ca.pem" "$TEST_DIR/client.pem" "$TEST_DIR/client.key"

assert_server_available() {
    if ! kill -0 "$SERVER_PID" >/dev/null 2>&1; then
        cat "$TEST_DIR/server-output.log" >&2
        echo 'mTLS test server exited during a client-certificate check.' >&2
        exit 1
    fi

    if ! php tests/mtls_client.php health "$PORT" "$TEST_DIR/ca.pem" "$TEST_DIR/client.pem" "$TEST_DIR/client.key" >/dev/null; then
        cat "$TEST_DIR/server-output.log" >&2
        echo 'mTLS listener stopped completing trusted TLS handshakes during a client-certificate check.' >&2
        exit 1
    fi
}

assert_tls_handshake_rejected() {
    local previous_count="$1"
    local client_mode="$2"

    sleep 0.1
    local current_count
    current_count="$(wc -l < "$LOCALZET_TEST_MTLS_ERRORS_FILE")"
    if [[ "$current_count" -le "$previous_count" ]] \
        || ! tail -n "+$((previous_count + 1))" "$LOCALZET_TEST_MTLS_ERRORS_FILE" | grep -Eq 'TLS handshake failed\.'; then
        cat "$LOCALZET_TEST_MTLS_ERRORS_FILE" >&2
        echo "Server did not record the TLS handshake rejection for the $client_mode client." >&2
        exit 1
    fi
}

assert_tls_handshake_timed_out() {
    local previous_count="$1"

    for _ in $(seq 1 20); do
        local current_count
        current_count="$(wc -l < "$LOCALZET_TEST_MTLS_ERRORS_FILE")"
        if [[ "$current_count" -gt "$previous_count" ]] \
            && tail -n "+$((previous_count + 1))" "$LOCALZET_TEST_MTLS_ERRORS_FILE" | grep -Eq 'TLS handshake timed out\.'; then
            return
        fi
        sleep 0.05
    done

    cat "$LOCALZET_TEST_MTLS_ERRORS_FILE" >&2
    echo 'Server did not time out a stalled TLS handshake.' >&2
    exit 1
}

assert_on_connect_not_called() {
    local previous_count="$1"

    sleep 0.1
    local current_count
    current_count="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
    if [[ "$current_count" -ne "$previous_count" ]]; then
        echo 'onConnect ran for a client without a trusted certificate.' >&2
        exit 1
    fi
}

assert_on_connect_called_once() {
    local previous_count="$1"

    for _ in $(seq 1 20); do
        local current_count
        current_count="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
        if [[ "$current_count" -eq "$((previous_count + 1))" ]]; then
            return
        fi
        sleep 0.05
    done

    echo 'onConnect did not run once after a trusted TLS handshake.' >&2
    exit 1
}

assert_server_available
ERROR_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_ERRORS_FILE")"
CONNECT_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
php tests/mtls_client.php missing "$PORT" "$TEST_DIR/ca.pem"
assert_tls_handshake_rejected "$ERROR_COUNT" missing
assert_on_connect_not_called "$CONNECT_COUNT"
assert_server_available
ERROR_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_ERRORS_FILE")"
CONNECT_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
php tests/mtls_client.php untrusted "$PORT" "$TEST_DIR/ca.pem" "$TEST_DIR/untrusted-client.pem" "$TEST_DIR/untrusted-client.key"
assert_tls_handshake_rejected "$ERROR_COUNT" untrusted
assert_on_connect_not_called "$CONNECT_COUNT"
assert_server_available
CONNECT_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
php tests/mtls_client.php trusted "$PORT" "$TEST_DIR/ca.pem" "$TEST_DIR/client.pem" "$TEST_DIR/client.key"
assert_on_connect_called_once "$CONNECT_COUNT"
assert_server_available
ERROR_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_ERRORS_FILE")"
CONNECT_COUNT="$(wc -l < "$LOCALZET_TEST_MTLS_CONNECTS_FILE")"
php tests/mtls_client.php stall "$PORT" "$TEST_DIR/ca.pem"
assert_tls_handshake_timed_out "$ERROR_COUNT"
assert_on_connect_not_called "$CONNECT_COUNT"

sleep 0.1
HITS="$(wc -l < "$LOCALZET_TEST_MTLS_HITS_FILE")"
if [[ "$HITS" -ne 2 ]]; then
    echo "Expected only the two trusted clients to reach the application, got $HITS requests." >&2
    exit 1
fi

php tests/live_mtls_server.php stop >/dev/null
wait "$SERVER_PID"
SERVER_PID=''

echo 'MTLS_CLIENT_CERTIFICATE_VERIFICATION=ok'
