#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BASE=$((20000 + ($$ % 10000)))
export LOCALZET_TEST_HTTP_PORT="${LOCALZET_TEST_HTTP_PORT:-$BASE}"
export LOCALZET_TEST_WS_PORT="${LOCALZET_TEST_WS_PORT:-$((BASE + 1))}"
export LOCALZET_TEST_CRASH_PORT="${LOCALZET_TEST_CRASH_PORT:-$((BASE + 2))}"
export LOCALZET_TEST_RELOAD_DELAY_MS=600
HTTP_LOG="${TMPDIR:-/tmp}/localzet-http-integration-$$.log"
WS_LOG="${TMPDIR:-/tmp}/localzet-ws-integration-$$.log"
UPGRADE_BOOT_FILE="${TMPDIR:-/tmp}/localzet-upgrade-boot-$$.txt"
HTTP_LAUNCH_PID=''
WS_LAUNCH_PID=''
CRASH_LAUNCH_PID=''

wait_port() {
    local port="$1"
    for _ in $(seq 1 80); do
        if php -r '$s=@fsockopen("127.0.0.1",(int)$argv[1],$e,$m,0.05); if($s){fclose($s); exit(0);} exit(1);' "$port"; then
            return 0
        fi
        sleep 0.05
    done
    return 1
}

stop_servers() {
    php tests/live_http_server.php stop >/dev/null 2>&1 || true
    php tests/live_websocket_server.php stop >/dev/null 2>&1 || true
    php tests/live_crash_server.php stop >/dev/null 2>&1 || true
    [[ -z "$HTTP_LAUNCH_PID" ]] || kill "$HTTP_LAUNCH_PID" >/dev/null 2>&1 || true
    [[ -z "$WS_LAUNCH_PID" ]] || kill "$WS_LAUNCH_PID" >/dev/null 2>&1 || true
    [[ -z "$CRASH_LAUNCH_PID" ]] || kill "$CRASH_LAUNCH_PID" >/dev/null 2>&1 || true
}
trap stop_servers EXIT

# 1. Multi-worker HTTP + large streamed body + worker reload/hot-upgrade.
printf 'generation-1\n' > "$UPGRADE_BOOT_FILE"
export LOCALZET_TEST_BOOT_VALUE_FILE="$UPGRADE_BOOT_FILE"
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'HTTP server did not start' >&2
    exit 1
fi

php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
stream_set_timeout($s,8);
fwrite($s,"GET /large HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s); fclose($s);
[$head,$body]=explode("\r\n\r\n",$raw,2);
$expected=str_repeat("0123456789abcdef",196608);
if($body!==$expected){fwrite(STDERR,"Large streamed response mismatch: ".strlen($body)." bytes\n");exit(1);}
if(!preg_match("/Content-Length:\\s*3145728/i",$head)){fwrite(STDERR,"Large response Content-Length mismatch\n");exit(1);}
echo "HTTP_LARGE_STREAM=ok\n";
'

# HTTP transport itself owns keep-alive/Connection: close semantics.
php -r '
function readResponse($s): array {
    $head="";
    while(!str_contains($head,"\r\n\r\n")){
        $chunk=fread($s,1);
        if($chunk==="" || $chunk===false){fwrite(STDERR,"Connection closed before response headers\n");exit(1);}
        $head.=$chunk;
    }
    [$headerBlock,$rest]=explode("\r\n\r\n",$head,2);
    if(!preg_match("/Content-Length:\s*(\d+)/i",$headerBlock,$m)){fwrite(STDERR,"Missing Content-Length\n");exit(1);}
    $need=(int)$m[1];$body=$rest;
    while(strlen($body)<$need){$chunk=fread($s,$need-strlen($body));if($chunk===""||$chunk===false){break;}$body.=$chunk;}
    return [$headerBlock,substr($body,0,$need)];
}
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
fwrite($s,"GET /keepalive HTTP/1.1\r\nHost: localhost\r\n\r\n");
[$h1,$b1]=readResponse($s);$j1=json_decode($b1,true,512,JSON_THROW_ON_ERROR);
if(($j1["path"]??null)!=="/keepalive" || feof($s)){fwrite(STDERR,"HTTP/1.1 keep-alive failed\n");exit(1);}
fwrite($s,"GET /keepalive HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
[$h2,$b2]=readResponse($s);$j2=json_decode($b2,true,512,JSON_THROW_ON_ERROR);
if(($j2["path"]??null)!=="/keepalive" || stripos($h2,"Connection: close")===false){fwrite(STDERR,"HTTP/1.1 close semantics failed\n");exit(1);}
usleep(100000);@fread($s,1);if(!feof($s)){fwrite(STDERR,"HTTP/1.1 close did not terminate connection\n");exit(1);}fclose($s);

$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
fwrite($s,"GET /keepalive HTTP/1.0\r\n\r\n");
[$h3,$b3]=readResponse($s);
if(!str_starts_with($h3,"HTTP/1.0 200") || stripos($h3,"Connection: close")===false){fwrite(STDERR,"HTTP/1.0 response lifecycle failed\n");exit(1);}
usleep(100000);@fread($s,1);if(!feof($s)){fwrite(STDERR,"HTTP/1.0 default close failed\n");exit(1);}fclose($s);
echo "HTTP_KEEPALIVE_LIFECYCLE=ok\n";
'

# Two requests sent in one TCP write must be decoded and answered in-order.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
$wire="GET /pipeline-1 HTTP/1.1\r\nHost: localhost\r\n\r\n"
    ."GET /pipeline-2 HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
fwrite($s,$wire);$raw=stream_get_contents($s);fclose($s);
if(substr_count($raw,"HTTP/1.1 200")!==2
    || strpos($raw,"\"path\":\"/pipeline-1\"")===false
    || strpos($raw,"\"path\":\"/pipeline-2\"")===false
    || strpos($raw,"/pipeline-1")>strpos($raw,"/pipeline-2")){
    fwrite(STDERR,"HTTP pipelining failed\n");exit(1);
}
echo "HTTP_PIPELINING=ok\n";
'

# Explicit response compression negotiates gzip and keeps wire Content-Length coherent.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
fwrite($s,"GET /gzip HTTP/1.1\r\nHost: localhost\r\nAccept-Encoding: gzip\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);[$head,$body]=explode("\r\n\r\n",$raw,2);
$expected=str_repeat("localzet-protocol-compression-",512);
if(stripos($head,"Content-Encoding: gzip")===false || gzdecode($body)!==$expected){
    fwrite(STDERR,"HTTP gzip integration failed\n");exit(1);
}
echo "HTTP_GZIP=ok compressed=".strlen($body)." original=".strlen($expected)."\n";
'

# Application-managed HTTP/1.1 chunked responses remain valid on the wire.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
fwrite($s,"GET /chunked HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);[$head,$body]=explode("\r\n\r\n",$raw,2);
if(stripos($head,"Transfer-Encoding: chunked")===false || $body!=="6\r\nhello \r\n5\r\nworld\r\n0\r\n\r\n"){
    fwrite(STDERR,"HTTP chunked response integration failed: ".bin2hex($body)."\n");exit(1);
}
echo "HTTP_CHUNKED_RESPONSE=ok\n";
'

# SSE helper serializes the event-stream representation without protocol-specific magic.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
fwrite($s,"GET /sse HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);[$head,$body]=explode("\r\n\r\n",$raw,2);
if(stripos($head,"Content-Type: text/event-stream")===false
    || !str_contains($body,"event: message\n")
    || !str_contains($body,"data: two\n")){
    fwrite(STDERR,"SSE integration failed\n");exit(1);
}
echo "HTTP_SSE=ok\n";
'

STATUS_JSON="$(php tests/live_http_server.php status --json)"
BEFORE_PIDS="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $p=array_map(fn($w)=>(int)$w["pid"],$d["workers"]??[]); sort($p); if(count($p)!==2){fwrite(STDERR,"Expected two workers in status\n");exit(1);} echo implode(",",$p);' <<<"$STATUS_JSON")"
MASTER_PID_BEFORE="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo (int)($d["master"]["master_pid"]??0);' <<<"$STATUS_JSON")"
echo "HTTP_WORKERS=$BEFORE_PIDS"
php -r '
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$read=$written=$accepted=0;
foreach($d["workers"]??[] as $w){
    $read+=(int)($w["statistics"]["bytes_read"]??0);
    $written+=(int)($w["statistics"]["bytes_written"]??0);
    $accepted+=(int)($w["statistics"]["connection_total"]??0);
}
if($written<3145728 || $read<=0 || $accepted<3){
    fwrite(STDERR,"Cumulative worker telemetry is incomplete\n");exit(1);
}
echo "HTTP_CUMULATIVE_TELEMETRY=ok\n";
' <<<"$STATUS_JSON"
echo "HTTP_STATUS=ok"

# A second start for the same entry script must fail without replacing PID/status.
if php tests/live_http_server.php start >"${TMPDIR:-/tmp}/localzet-duplicate-start-$$.log" 2>&1; then
    echo 'Duplicate Localzet start unexpectedly succeeded' >&2
    exit 1
fi
DUP_STATUS_JSON="$(php tests/live_http_server.php status --json)"
MASTER_PID_AFTER_DUP="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo (int)($d["master"]["master_pid"]??0);' <<<"$DUP_STATUS_JSON")"
if [[ "$MASTER_PID_AFTER_DUP" != "$MASTER_PID_BEFORE" ]]; then
    echo 'Duplicate start corrupted master PID/status' >&2
    exit 1
fi
rm -f "${TMPDIR:-/tmp}/localzet-duplicate-start-$$.log"
echo "PROCESS_START_LOCK=ok"

php tests/live_http_server.php reload >/dev/null
sleep 0.1
ROLLING_JSON="$(php tests/live_http_server.php status --json)"
php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $r=$d["master"]["reload"]??[]; if(!($r["active"]??false)){fwrite(STDERR,"Expected rolling reload to be active\n");exit(1);} if(($r["remaining"]??0)<1){fwrite(STDERR,"Expected at least one worker queued during rolling reload\n");exit(1);} echo "HTTP_ROLLING_RELOAD=ok\n";' <<<"$ROLLING_JSON"
sleep 1.4
AFTER_JSON="$(php tests/live_http_server.php status --json)"
AFTER_PIDS="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); $p=array_map(fn($w)=>(int)$w["pid"],$d["workers"]??[]); sort($p); if(count($p)!==2){fwrite(STDERR,"Expected two workers after reload\n");exit(1);} echo implode(",",$p);' <<<"$AFTER_JSON")"
php -r '$before=array_filter(explode(",",$argv[1]));$after=array_filter(explode(",",$argv[2]));if(array_intersect($before,$after)){fwrite(STDERR,"Expected every worker PID to be replaced by rolling reload\n");exit(1);}' "$BEFORE_PIDS" "$AFTER_PIDS"
echo "HTTP_RELOAD_WORKERS=$AFTER_PIDS"

# Zero-downtime master re-exec must preserve the master PID/listener, reload
# bootstrap state from disk and rolling-replace all old worker images.
UPGRADE_STATUS_BEFORE="$(php tests/live_http_server.php status --json)"
UPGRADE_MASTER_BEFORE="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["master_pid"]??0);' <<<"$UPGRADE_STATUS_BEFORE")"
UPGRADE_GENERATION_BEFORE="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["generation"]??0);' <<<"$UPGRADE_STATUS_BEFORE")"
UPGRADE_PIDS_BEFORE="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$p=array_map(fn($w)=>(int)$w["pid"],$d["workers"]??[]);sort($p);echo implode(",",$p);' <<<"$UPGRADE_STATUS_BEFORE")"
printf 'generation-2\n' > "$UPGRADE_BOOT_FILE"

# Keep sending requests while the master execs and workers are replaced. Any
# connection/refusal/parser failure makes the load probe exit non-zero.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
for($i=0;$i<100;$i++){
    $s=@stream_socket_client("tcp://127.0.0.1:$port",$e,$m,0.5);
    if(!$s){fwrite(STDERR,"Hot-upgrade request $i connect failed: $m\n");exit(1);}
    stream_set_timeout($s,1);
    fwrite($s,"GET /boot-value HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw=stream_get_contents($s);fclose($s);
    if(!str_contains($raw,"\r\n\r\n")){fwrite(STDERR,"Hot-upgrade request $i invalid response\n");exit(1);}
    [, $body]=explode("\r\n\r\n",$raw,2);
    $json=json_decode($body,true);
    if(!is_array($json) || !in_array($json["boot_value"]??null,["generation-1","generation-2"],true)){
        fwrite(STDERR,"Hot-upgrade request $i invalid bootstrap value\n");exit(1);
    }
    usleep(20_000);
}
echo "HTTP_HOT_UPGRADE_LOAD=ok\n";
' >"${TMPDIR:-/tmp}/localzet-upgrade-load-$$.log" 2>&1 &
UPGRADE_LOAD_PID=$!

php tests/live_http_server.php upgrade >/dev/null

UPGRADE_DONE=0
for _ in $(seq 1 160); do
    CURRENT_STATUS="$(php tests/live_http_server.php status --json 2>/dev/null || true)"
    if [[ -n "$CURRENT_STATUS" ]]; then
        read -r CURRENT_MASTER CURRENT_GENERATION CURRENT_RELOAD <<<"$(php -r '
        $d=json_decode(stream_get_contents(STDIN),true);
        if(!is_array($d)){exit(1);}
        echo (int)($d["master"]["master_pid"]??0)," ",(int)($d["master"]["generation"]??0)," ",(($d["master"]["reload"]["active"]??false)?1:0);
        ' <<<"$CURRENT_STATUS" 2>/dev/null || true)"
        if [[ "$CURRENT_MASTER" == "$UPGRADE_MASTER_BEFORE" && "$CURRENT_GENERATION" -gt "$UPGRADE_GENERATION_BEFORE" && "$CURRENT_RELOAD" == 0 ]]; then
            UPGRADE_DONE=1
            break
        fi
    fi
    sleep 0.05
done

wait "$UPGRADE_LOAD_PID"
cat "${TMPDIR:-/tmp}/localzet-upgrade-load-$$.log"
rm -f "${TMPDIR:-/tmp}/localzet-upgrade-load-$$.log"
if [[ "$UPGRADE_DONE" != 1 ]]; then
    cat "$HTTP_LOG" >&2 || true
    echo 'Hot upgrade did not finish rolling replacement' >&2
    exit 1
fi

UPGRADE_STATUS_AFTER="$(php tests/live_http_server.php status --json)"
UPGRADE_MASTER_AFTER="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["master_pid"]??0);' <<<"$UPGRADE_STATUS_AFTER")"
UPGRADE_GENERATION_AFTER="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["generation"]??0);' <<<"$UPGRADE_STATUS_AFTER")"
UPGRADE_PIDS_AFTER="$(php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$p=array_map(fn($w)=>(int)$w["pid"],$d["workers"]??[]);sort($p);echo implode(",",$p);' <<<"$UPGRADE_STATUS_AFTER")"
if [[ "$UPGRADE_MASTER_AFTER" != "$UPGRADE_MASTER_BEFORE" || "$UPGRADE_GENERATION_AFTER" -le "$UPGRADE_GENERATION_BEFORE" ]]; then
    echo 'Hot upgrade did not preserve master PID/increment generation' >&2
    exit 1
fi
php -r '$before=array_filter(explode(",",$argv[1]));$after=array_filter(explode(",",$argv[2]));if(array_intersect($before,$after)){fwrite(STDERR,"Hot upgrade left an old worker PID running\n");exit(1);}' "$UPGRADE_PIDS_BEFORE" "$UPGRADE_PIDS_AFTER"
UPGRADE_BOOT_AFTER="$(php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,1);fwrite($s,"GET /boot-value HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");$raw=stream_get_contents($s);fclose($s);[, $body]=explode("\r\n\r\n",$raw,2);$j=json_decode($body,true,512,JSON_THROW_ON_ERROR);echo $j["boot_value"]??"";
')"
[[ "$UPGRADE_BOOT_AFTER" == 'generation-2' ]] || { echo 'Hot upgrade did not reload bootstrap code/state' >&2; exit 1; }
echo "HTTP_HOT_UPGRADE=ok master=$UPGRADE_MASTER_AFTER generation=$UPGRADE_GENERATION_AFTER workers=$UPGRADE_PIDS_AFTER"

php tests/live_http_server.php stop >/dev/null
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''
unset LOCALZET_TEST_BOOT_VALUE_FILE
rm -f "$UPGRADE_BOOT_FILE"

# 2. Graceful restart must boot a newly invoked application image rather than
# inheriting already-loaded definitions/configuration from the old master.
BOOT_VALUE_FILE="${TMPDIR:-/tmp}/localzet-boot-value-$$.txt"
export LOCALZET_TEST_BOOT_VALUE_FILE="$BOOT_VALUE_FILE"
export LOCALZET_TEST_WORKERS=1
printf 'before\n' > "$BOOT_VALUE_FILE"
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'HTTP restart fixture did not start' >&2
    exit 1
fi

read_boot_value() {
    php -r '
    $port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
    $s=@stream_socket_client("tcp://127.0.0.1:$port",$e,$m,0.5);
    if(!$s){exit(2);}
    stream_set_timeout($s,1);
    fwrite($s,"GET /boot-value HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw=stream_get_contents($s);fclose($s);
    if(!str_contains($raw,"\r\n\r\n")){exit(3);}
    [, $body]=explode("\r\n\r\n",$raw,2);
    $json=json_decode($body,true);
    if(!is_array($json)){exit(4);}
    echo ($json["boot_value"]??"")."|".(int)($json["pid"]??0);
    '
}

INITIAL_BOOT="$(read_boot_value)"
[[ "${INITIAL_BOOT%%|*}" == 'before' ]] || { echo 'Initial bootstrap value mismatch' >&2; exit 1; }
OLD_MASTER_PID="$(php tests/live_http_server.php status --json | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["master_pid"]??0);')"
printf 'after\n' > "$BOOT_VALUE_FILE"
OLD_HTTP_LAUNCH_PID="$HTTP_LAUNCH_PID"
php tests/live_http_server.php restart -g >"$HTTP_LOG.restart" 2>&1 &
RESTART_LAUNCH_PID=$!
HTTP_LAUNCH_PID="$RESTART_LAUNCH_PID"

RESTARTED=0
for _ in $(seq 1 100); do
    VALUE="$(read_boot_value 2>/dev/null || true)"
    if [[ "${VALUE%%|*}" == 'after' ]]; then
        RESTARTED=1
        break
    fi
    sleep 0.05
done
if [[ "$RESTARTED" != 1 ]]; then
    cat "$HTTP_LOG.restart" >&2 || true
    echo 'Graceful restart did not expose the new bootstrap value' >&2
    exit 1
fi
NEW_MASTER_PID="$(php tests/live_http_server.php status --json | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["master_pid"]??0);')"
if [[ "$NEW_MASTER_PID" == "$OLD_MASTER_PID" || "$NEW_MASTER_PID" -le 0 ]]; then
    echo 'Graceful restart did not replace the master process' >&2
    exit 1
fi
wait "$OLD_HTTP_LAUNCH_PID" || true
echo "HTTP_GRACEFUL_RESTART=ok master=$OLD_MASTER_PID->$NEW_MASTER_PID"
php tests/live_http_server.php stop -g >/dev/null
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''
rm -f "$BOOT_VALUE_FILE" "$HTTP_LOG.restart"
unset LOCALZET_TEST_BOOT_VALUE_FILE LOCALZET_TEST_WORKERS

# 3. Worker recycling after maxRequests keeps service available and replaces processes.
export LOCALZET_TEST_RELOAD_DELAY_MS=0
export LOCALZET_TEST_MAX_REQUESTS=1
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'HTTP recycle server did not start' >&2
    exit 1
fi
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");$pids=[];
for($i=0;$i<8;$i++){
    $s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
    fwrite($s,"GET /recycle HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $raw=stream_get_contents($s);fclose($s);[, $body]=explode("\r\n\r\n",$raw,2);
    $json=json_decode($body,true,512,JSON_THROW_ON_ERROR);$pids[(int)$json["pid"]]=true;
    usleep(120000);
}
if(count($pids)<=2){fwrite(STDERR,"Expected maxRequests worker recycling to replace PIDs\n");exit(1);}
echo "HTTP_WORKER_RECYCLE=ok pids=".implode(",",array_keys($pids))."\n";
'
php tests/live_http_server.php stop >/dev/null
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''
unset LOCALZET_TEST_MAX_REQUESTS

# 4. HTTPS/TLS end-to-end + stalled handshake deadline.
TLS_DIR="${TMPDIR:-/tmp}/localzet-tls-$$"
TLS_CERT="$TLS_DIR/cert.pem"
TLS_KEY="$TLS_DIR/key.pem"
mkdir -p "$TLS_DIR"
openssl req -x509 -newkey rsa:2048 -sha256 -nodes \
    -keyout "$TLS_KEY" -out "$TLS_CERT" -days 1 \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" >/dev/null 2>&1

export LOCALZET_TEST_HTTP_SCHEME=https
export LOCALZET_SSL_CERT="$TLS_CERT"
export LOCALZET_SSL_CERT_KEY="$TLS_KEY"
export LOCALZET_TEST_TLS_TIMEOUT=0.25
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!

# A plain TCP connect is enough to prove the listener is alive; a TLS client
# below validates the actual cryptographic handshake.
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'HTTPS server did not start' >&2
    exit 1
fi

php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$ctx=stream_context_create(["ssl"=>[
    "verify_peer"=>false,
    "verify_peer_name"=>false,
    "allow_self_signed"=>true,
]]);
$s=@stream_socket_client("tls://127.0.0.1:$port",$e,$m,3,STREAM_CLIENT_CONNECT,$ctx);
if(!$s){fwrite(STDERR,"TLS connect failed: $e $m\n");exit(1);}
stream_set_timeout($s,3);
fwrite($s,"GET /tls HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);
if(!str_starts_with($raw,"HTTP/1.1 200")){fwrite(STDERR,"HTTPS response failed\n$raw\n");exit(1);}
[, $body]=explode("\r\n\r\n",$raw,2);
$j=json_decode($body,true,512,JSON_THROW_ON_ERROR);
if(($j["path"]??null)!=="/tls"){fwrite(STDERR,"HTTPS body mismatch\n");exit(1);}
echo "HTTPS_TLS=ok\n";
'

# Client deliberately never starts TLS. Server must reap it by handshake timeout.
php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
stream_set_blocking($s,false);
usleep(600000);
$data=fread($s,1);
$closed=feof($s);
fclose($s);
if(!$closed && $data!==false && $data!==""){fwrite(STDERR,"Unexpected data on stalled TLS socket\n");exit(1);}
if(!$closed){fwrite(STDERR,"TLS handshake timeout did not close stalled client\n");exit(1);}
echo "HTTPS_TLS_TIMEOUT=ok\n";
'

php tests/live_http_server.php stop >/dev/null
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''
rm -rf "$TLS_DIR"
unset LOCALZET_TEST_HTTP_SCHEME LOCALZET_SSL_CERT LOCALZET_SSL_CERT_KEY LOCALZET_TEST_TLS_TIMEOUT

# 5. Generic overload / slow-frame guards.
export LOCALZET_TEST_WORKERS=1
export LOCALZET_TEST_MAX_CONNECTIONS=1
export LOCALZET_TEST_FRAME_TIMEOUT=0.20
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'Guard test server did not start' >&2
    exit 1
fi

php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");

// Occupy the only connection slot with an intentionally incomplete HTTP frame.
$first=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
stream_set_blocking($first,false);
fwrite($first,"GET /slow HTTP/1.1\r\nHost: localhost\r\n");
usleep(80000);

// Second accepted socket must be rejected because maxConnections=1.
$second=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
stream_set_blocking($second,false);
fwrite($second,"GET /overload HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
usleep(100000);
$secondData=@stream_get_contents($second);
$secondClosed=feof($second);
fclose($second);
if(!$secondClosed || $secondData!==""){
    fwrite(STDERR,"maxConnections did not reject the excess socket\n");
    exit(1);
}

// The first peer keeps trickling/holding an incomplete frame. Absolute frame
// deadline must close it independently of ordinary idle activity semantics.
usleep(250000);
@fread($first,1);
$firstClosed=feof($first);
fclose($first);
if(!$firstClosed){
    fwrite(STDERR,"frameTimeout did not reap incomplete protocol frame\n");
    exit(1);
}
echo "TCP_GUARDS=ok\n";
'

php tests/live_http_server.php stop >/dev/null
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''
unset LOCALZET_TEST_WORKERS LOCALZET_TEST_MAX_CONNECTIONS LOCALZET_TEST_FRAME_TIMEOUT

# 6. Graceful SIGTERM arriving inside onMessage must drain the active dispatch.
export LOCALZET_TEST_WORKERS=1
GRACEFUL_RESULT="${TMPDIR:-/tmp}/localzet-graceful-$$.txt"
php tests/live_http_server.php start >"$HTTP_LOG" 2>&1 &
HTTP_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_HTTP_PORT"; then
    cat "$HTTP_LOG"
    echo 'Graceful drain server did not start' >&2
    exit 1
fi

php -r '
$port=(int)getenv("LOCALZET_TEST_HTTP_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
stream_set_timeout($s,3);
fwrite($s,"GET /slow-dispatch HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);
file_put_contents($argv[1],$raw);
' "$GRACEFUL_RESULT" &
GRACEFUL_CLIENT_PID=$!

sleep 0.10
# Orchestrators normally send SIGTERM; it must use the graceful path too.
TERM_MASTER_PID="$(php tests/live_http_server.php status --json | php -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo (int)($d["master"]["master_pid"]??0);')"
kill -TERM "$TERM_MASTER_PID"
wait "$GRACEFUL_CLIENT_PID"
wait "$HTTP_LAUNCH_PID"
HTTP_LAUNCH_PID=''

php -r '
$raw=file_get_contents($argv[1]);
if(!str_starts_with($raw,"HTTP/1.1 200")){
    fwrite(STDERR,"Graceful stop interrupted active HTTP dispatch\n");
    exit(1);
}
[, $body]=explode("\r\n\r\n",$raw,2);
$d=json_decode($body,true,512,JSON_THROW_ON_ERROR);
if(($d["path"]??null)!=="/slow-dispatch"){
    fwrite(STDERR,"Graceful response body mismatch\n");
    exit(1);
}
echo "SIGTERM_GRACEFUL_DRAIN=ok\n";
' "$GRACEFUL_RESULT"
rm -f "$GRACEFUL_RESULT"
unset LOCALZET_TEST_WORKERS

# 7. Crash-loop backoff: two intentional startup crashes, then recovery.
CRASH_COUNTER="${TMPDIR:-/tmp}/localzet-crash-counter-$$.json"
CRASH_LOG="${TMPDIR:-/tmp}/localzet-crash-integration-$$.log"
printf '[]' >"$CRASH_COUNTER"
export LOCALZET_TEST_CRASH_COUNTER="$CRASH_COUNTER"
export LOCALZET_TEST_CRASH_COUNT=2

php tests/live_crash_server.php start >"$CRASH_LOG" 2>&1 &
CRASH_LAUNCH_PID=$!

# 100ms + 200ms exponential delays (plus monitor granularity) should separate
# the first and third startup attempts by a visible amount.
sleep 0.55

php -r '
$port=(int)getenv("LOCALZET_TEST_CRASH_PORT");
$s=@stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);
if(!$s){fwrite(STDERR,"Recovered crash-test worker is not reachable\n");exit(1);}
stream_set_timeout($s,2);
fwrite($s,"GET /recovered HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
$raw=stream_get_contents($s);fclose($s);
if(!str_starts_with($raw,"HTTP/1.1 200")){fwrite(STDERR,"Crash-test worker did not recover\n");exit(1);}
' 

php -r '
$t=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
if(count($t)!==3){fwrite(STDERR,"Expected exactly 3 startup attempts, got ".count($t)."\n");exit(1);}
if(($t[2]-$t[0])<0.24 || ($t[2]-$t[1])<0.16){
    fwrite(STDERR,"Crash backoff intervals were too short\n");
    exit(1);
}
echo "WORKER_CRASH_BACKOFF=ok intervals=".round($t[1]-$t[0],3).",".round($t[2]-$t[1],3)."\n";
' "$CRASH_COUNTER"

php tests/live_crash_server.php stop >/dev/null
wait "$CRASH_LAUNCH_PID"
CRASH_LAUNCH_PID=''
rm -f "$CRASH_COUNTER" "$CRASH_LOG"
unset LOCALZET_TEST_CRASH_COUNTER LOCALZET_TEST_CRASH_COUNT

# 8. WebSocket server + Localzet AsyncTcpConnection/Ws client roundtrip.
php tests/live_websocket_server.php start >"$WS_LOG" 2>&1 &
WS_LAUNCH_PID=$!
if ! wait_port "$LOCALZET_TEST_WS_PORT"; then
    cat "$WS_LOG"
    echo 'WebSocket server did not start' >&2
    exit 1
fi
php tests/live_websocket_client.php

# Keep one WebSocket open and verify graceful shutdown sends RFC 6455 1001.
WS_READY="${TMPDIR:-/tmp}/localzet-ws-ready-$$"
WS_CLOSE_RESULT="${TMPDIR:-/tmp}/localzet-ws-close-$$"
php -r '
$port=(int)getenv("LOCALZET_TEST_WS_PORT");
$s=stream_socket_client("tcp://127.0.0.1:$port",$e,$m,2);stream_set_timeout($s,3);
$key=base64_encode(random_bytes(16));
$req="GET /graceful HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
fwrite($s,$req);$head="";
while(!str_contains($head,"\r\n\r\n")){ $c=fread($s,1); if($c===""||$c===false){fwrite(STDERR,"WS handshake closed early\n");exit(1);} $head.=$c; }
if(!str_starts_with($head,"HTTP/1.1 101")){fwrite(STDERR,"WS graceful fixture handshake failed\n");exit(1);}
file_put_contents($argv[1],"ready");
$first=fread($s,2);if(strlen($first)!==2){fwrite(STDERR,"Missing WS close frame\n");exit(1);}
$b1=ord($first[0]);$b2=ord($first[1]);$opcode=$b1&0x0f;$len=$b2&0x7f;
if($opcode!==8 || ($b2&0x80)!==0 || $len>125){fwrite(STDERR,"Invalid server WS close frame\n");exit(1);}
$payload=$len>0?fread($s,$len):"";$code=strlen($payload)>=2?unpack("n",substr($payload,0,2))[1]:0;
file_put_contents($argv[2],(string)$code);

// Acknowledge the server close with a masked client Close frame.
$reply=pack("n",$code?:1001);$mask=random_bytes(4);$masked=$reply;
for($i=0;$i<strlen($masked);$i++){$masked[$i]=$masked[$i]^$mask[$i&3];}
fwrite($s,chr(0x88).chr(0x80|strlen($reply)).$mask.$masked);
stream_set_timeout($s,2);while(!feof($s)){ $d=fread($s,1024); if($d===""){ $meta=stream_get_meta_data($s); if($meta["timed_out"]??false) break; } }
fclose($s);
' "$WS_READY" "$WS_CLOSE_RESULT" &
WS_GRACE_CLIENT_PID=$!
for _ in $(seq 1 40); do [[ -f "$WS_READY" ]] && break; sleep 0.05; done
if [[ ! -f "$WS_READY" ]]; then
    echo 'WebSocket graceful fixture did not become ready' >&2
    exit 1
fi
php tests/live_websocket_server.php stop -g >/dev/null
wait "$WS_GRACE_CLIENT_PID"
wait "$WS_LAUNCH_PID"
WS_LAUNCH_PID=''
if [[ "$(cat "$WS_CLOSE_RESULT")" != "1001" ]]; then
    echo 'Graceful WebSocket shutdown did not send close code 1001' >&2
    exit 1
fi
rm -f "$WS_READY" "$WS_CLOSE_RESULT"
echo 'WS_GRACEFUL_CLOSE=ok'

echo 'All integration tests passed.'
