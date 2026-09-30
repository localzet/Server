#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
BASE=$((30000 + ($$ % 1000)))
export LOCALZET_TEST_GATEWAY_BACKEND_PORT=$BASE
export LOCALZET_TEST_GATEWAY_WS_BACKEND_PORT=$((BASE+1))
export LOCALZET_TEST_GATEWAY_PORT=$((BASE+2))
export LOCALZET_TEST_TCP_BACKEND_PORT=$((BASE+3))
export LOCALZET_TEST_TCP_PROXY_PORT=$((BASE+4))
export LOCALZET_TEST_GATEWAY_DEAD_PORT=$((BASE+9))
PIDS=()
cleanup(){
  php tests/live_gateway_server.php stop >/dev/null 2>&1 || true
  php tests/live_gateway_backend.php stop >/dev/null 2>&1 || true
  php tests/live_gateway_ws_backend.php stop >/dev/null 2>&1 || true
  php tests/live_tcp_proxy_server.php stop >/dev/null 2>&1 || true
  php tests/live_tcp_echo_backend.php stop >/dev/null 2>&1 || true
  for pid in "${PIDS[@]}"; do kill "$pid" >/dev/null 2>&1 || true; done
}
trap cleanup EXIT
wait_port(){ local p="$1"; for _ in $(seq 1 100); do php -r '$s=@fsockopen("127.0.0.1",(int)$argv[1],$e,$m,.05);if($s){fclose($s);exit(0);}exit(1);' "$p" && return 0; sleep .03; done; return 1; }
php tests/live_gateway_backend.php start >/tmp/localzet-gw-back-$$.log 2>&1 & PIDS+=("$!")
php tests/live_gateway_ws_backend.php start >/tmp/localzet-gw-ws-$$.log 2>&1 & PIDS+=("$!")
php tests/live_gateway_server.php start >/tmp/localzet-gw-$$.log 2>&1 & PIDS+=("$!")
php tests/live_tcp_echo_backend.php start >/tmp/localzet-tcp-back-$$.log 2>&1 & PIDS+=("$!")
php tests/live_tcp_proxy_server.php start >/tmp/localzet-tcp-proxy-$$.log 2>&1 & PIDS+=("$!")
for p in "$LOCALZET_TEST_GATEWAY_BACKEND_PORT" "$LOCALZET_TEST_GATEWAY_WS_BACKEND_PORT" "$LOCALZET_TEST_GATEWAY_PORT" "$LOCALZET_TEST_TCP_BACKEND_PORT" "$LOCALZET_TEST_TCP_PROXY_PORT"; do wait_port "$p"; done

# L4 failover + byte-for-byte payload.
php -r '$p=(int)getenv("LOCALZET_TEST_TCP_PROXY_PORT");$x=str_repeat("abcdef",10000);$s=stream_socket_client("tcp://127.0.0.1:$p",$e,$m,2);stream_set_timeout($s,4);fwrite($s,$x);$g="";while(strlen($g)<strlen($x)&&!feof($s)){$c=fread($s,strlen($x)-strlen($g));if($c===""||$c===false)break;$g.=$c;}fclose($s);if($g!==$x)exit(1);echo "TCP_PROXY_FAILOVER=ok\n";'

# Streaming Content-Length request + dead-first upstream failover + prefix rewrite.
php -r '$p=(int)getenv("LOCALZET_TEST_GATEWAY_PORT");$body=str_repeat("stream-body-",20000);$s=stream_socket_client("tcp://127.0.0.1:$p",$e,$m,2);stream_set_timeout($s,5);$h="POST /api/upload HTTP/1.1\r\nHost: api.test\r\nContent-Type: application/octet-stream\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n";fwrite($s,$h);for($o=0;$o<strlen($body);$o+=4096)fwrite($s,substr($body,$o,4096));$raw=stream_get_contents($s);fclose($s);[$h,$b]=explode("\r\n\r\n",$raw,2);$j=json_decode($b,true);if(($j["path"]??"")!=="/v1/upload"||($j["body_len"]??0)!==strlen($body)||($j["body_sha256"]??"")!==hash("sha256",$body)||!str_contains((string)($j["xff"]??""),"127.0.0.1"))exit(1);echo "HTTP_GATEWAY_STREAM=ok\n";'

# Client and upstream keep-alive across sequential requests.
php -r 'function rr($s){$h="";while(!str_contains($h,"\r\n\r\n")){$c=fread($s,1);if($c===""||$c===false)exit(2);$h.=$c;}[$hb,$r]=explode("\r\n\r\n",$h,2);preg_match("/Content-Length:\\s*(\\d+)/i",$hb,$m);$n=(int)($m[1]??0);while(strlen($r)<$n){$c=fread($s,$n-strlen($r));if($c===""||$c===false)break;$r.=$c;}return substr($r,0,$n);} $p=(int)getenv("LOCALZET_TEST_GATEWAY_PORT");$s=stream_socket_client("tcp://127.0.0.1:$p",$e,$m,2);stream_set_timeout($s,4);fwrite($s,"GET /api/one HTTP/1.1\r\nHost: api.test\r\n\r\n");$a=json_decode(rr($s),true);fwrite($s,"GET /api/two HTTP/1.1\r\nHost: api.test\r\nConnection: close\r\n\r\n");$b=json_decode(rr($s),true);fclose($s);if(($a["path"]??"")!=="/v1/one"||($b["path"]??"")!=="/v1/two")exit(1);echo "HTTP_GATEWAY_KEEPALIVE=ok\n";'

# Chunked request remains chunked on wire while backend receives decoded body.
php -r '$p=(int)getenv("LOCALZET_TEST_GATEWAY_PORT");$s=stream_socket_client("tcp://127.0.0.1:$p",$e,$m,2);stream_set_timeout($s,4);fwrite($s,"POST /api/chunk HTTP/1.1\r\nHost: api.test\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n");$raw=stream_get_contents($s);fclose($s);[$h,$b]=explode("\r\n\r\n",$raw,2);$j=json_decode($b,true);if(($j["body_len"]??0)!==9||($j["body_sha256"]??"")!==hash("sha256","Wikipedia"))exit(1);echo "HTTP_GATEWAY_CHUNKED=ok\n";'

# Ambiguous framing and duplicate Host are rejected by the gateway itself.
php -r '$p=(int)getenv("LOCALZET_TEST_GATEWAY_PORT");foreach(["POST /api/x HTTP/1.1\r\nHost: api.test\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\nx","GET /api/x HTTP/1.1\r\nHost: api.test\r\nHost: evil.test\r\n\r\n","POST /api/x HTTP/1.1\r\nHost: api.test\r\nTransfer-Encoding: chunked\r\nContent-Length: 4\r\n\r\n0\r\n\r\n"] as $wire){$s=stream_socket_client("tcp://127.0.0.1:$p",$e,$m,2);stream_set_timeout($s,2);fwrite($s,$wire);$raw=stream_get_contents($s);fclose($s);if(!str_starts_with($raw,"HTTP/1.1 400")){fwrite(STDERR,"gateway accepted ambiguous framing\n$raw\n");exit(1);}}echo "HTTP_GATEWAY_SMUGGLING_GUARDS=ok\n";'

php tests/live_gateway_ws_client.php
