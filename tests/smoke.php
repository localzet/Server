<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if ($class === 'localzet\\Server') {
        require_once $root . '/Server.php';
        return;
    }
    if ($class === 'localzet\\Timer') {
        require_once $root . '/Timer.php';
        return;
    }
    if ($class === 'localzet\\ServerAbstract') {
        require_once $root . '/ServerAbstract.php';
        return;
    }
    $prefix = 'localzet\\Server\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require_once $root . '/Helpers.php';

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Connection\AsyncUdpConnection;
use localzet\Server\Events\Select;
use localzet\Server\Events\EventLoopFactory;
use localzet\Server\Protocols\Frame;
use localzet\Server\Protocols\Http;
use localzet\Server\Protocols\Http\Response;
use localzet\Server\Protocols\Http\Chunk;
use localzet\Server\Protocols\Http\ServerSentEvents;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Session;
use localzet\Server\Protocols\Http\Session\FileSessionHandler;
use localzet\Server\Protocols\Websocket;

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

function connectionPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) throw new RuntimeException('stream_socket_pair failed');
    return $pair;
}

$loop = new Select();

// HTTP: обычный request.
[$serverSock, $peerSock] = connectionPair();
$conn = new TcpConnection($loop, $serverSock, '127.0.0.1:12345');
$conn->protocol = Http::class;
$raw = "GET /hello?a=1 HTTP/1.1\r\nHost: example.test\r\nAccept: application/json\r\n\r\n";
$length = Http::input($raw, $conn);
ok($length === strlen($raw), 'HTTP frame length');
$request = Http::decode($raw, $conn);
ok($request->method() === 'GET' && $request->path() === '/hello' && $request->get('a') === '1', 'HTTP Request parsing');
ok($request->host() === 'example.test' && $request->acceptsJson(), 'HTTP headers');

// HTTP methods are RFC tokens, not a closed list of framework verbs.
$customMethod = "PROPFIND /dav HTTP/1.1\r\nHost: example.test\r\n\r\n";
ok(Http::input($customMethod, $conn) === strlen($customMethod), 'HTTP extension method token');
$customRequest = Http::decode($customMethod, $conn);
ok($customRequest->method() === 'PROPFIND', 'HTTP extension method decode');

// HTTP: chunked body + trailers.
$chunked = "POST /form HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\n"
    . "4\r\na=1&\r\n3\r\nb=2\r\n0\r\nX-Trace: done\r\n\r\n";
$length = Http::input($chunked, $conn);
ok($length === strlen($chunked), 'HTTP chunked frame length');
$request = Http::decode($chunked, $conn);
ok($request->post('a') === '1' && $request->post('b') === '2', 'HTTP chunked body decode');
ok($request->trailer('x-trace') === 'done', 'HTTP chunked trailers');

// Multipart parser keeps ordinary fields and upload metadata separate and owns
// the temporary file lifecycle through Request::destroy().
$boundary = '----localzet-smoke-boundary';
$multipartBody = "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"title\"\r\n\r\n"
    . "demo\r\n"
    . "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"upload\"; filename=\"note.txt\"\r\n"
    . "Content-Type: text/plain\r\n\r\n"
    . "hello upload\r\n"
    . "--{$boundary}--\r\n";
$multipartRaw = "POST /upload HTTP/1.1\r\nHost: example.test\r\n"
    . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
    . 'Content-Length: ' . strlen($multipartBody) . "\r\n\r\n"
    . $multipartBody;
$multipartRequest = new Request($multipartRaw);
$upload = $multipartRequest->file('upload');
ok($multipartRequest->post('title') === 'demo'
    && is_array($upload)
    && ($upload['name'] ?? '') === 'note.txt'
    && is_file((string)($upload['tmp_name'] ?? ''))
    && file_get_contents((string)$upload['tmp_name']) === 'hello upload',
    'HTTP multipart field and upload parsing');
$uploadTmp = (string)$upload['tmp_name'];
$multipartRequest->destroy();
ok(!is_file($uploadTmp), 'HTTP multipart temp file cleanup');

// Response: header injection не проходит.
$response = new Response(200, ['X-Test' => "safe\r\nInjected: yes"], 'body');
$encoded = (string)$response;
ok(!str_contains($encoded, "\r\nInjected: yes\r\n"), 'HTTP response header sanitization');

// Opt-in gzip compression is request-aware and preserves HEAD semantics.
if (function_exists('gzencode')) {
    $compressRequest = new Request("GET /gzip HTTP/1.1\r\nHost: example.test\r\nAccept-Encoding: br;q=1, gzip;q=0.8\r\n\r\n");
    $compressible = str_repeat('localzet-protocol-', 512);
    $compressedResponse = (new Response(200, ['Content-Type' => 'text/plain'], $compressible))
        ->withCompressionForRequest($compressRequest, 128);
    $compressedBody = $compressedResponse->rawBody();
    ok($compressedResponse->getHeader('Content-Encoding') === 'gzip'
        && is_string($compressedBody)
        && gzdecode($compressedBody) === $compressible,
        'HTTP opt-in gzip compression');
    ok(stripos((string)$compressedResponse->getHeader('Vary'), 'Accept-Encoding') !== false,
        'HTTP gzip Vary header');

    $gzipRejected = new Request("GET /gzip HTTP/1.1\r\nHost: example.test\r\nAccept-Encoding: gzip;q=0, *;q=1\r\n\r\n");
    $notCompressed = (new Response(200, [], $compressible))->withCompressionForRequest($gzipRejected, 128);
    ok($notCompressed->getHeader('Content-Encoding') === null, 'HTTP gzip q=0 respected');

    $headRequest = new Request("HEAD /gzip HTTP/1.1\r\nHost: example.test\r\nAccept-Encoding: gzip\r\n\r\n");
    $headResponse = (new Response(200, [], $compressible))->withCompressionForRequest($headRequest, 128);
    $headEncoded = (string)$headResponse;
    ok($headResponse->getHeader('Content-Encoding') === 'gzip'
        && str_ends_with($headEncoded, "\r\n\r\n"),
        'HTTP gzip HEAD suppresses body');
}

ok((string)new Chunk('abc') === "3\r\nabc\r\n", 'HTTP chunk serialization');
$sse = (string)new ServerSentEvents("line1\nline2", 'update', '42', 1500);
ok(str_contains($sse, "event: update\n") && str_contains($sse, "data: line2\n") && str_ends_with($sse, "\n\n"),
    'Server-Sent Events serialization');


// HTTP framing: ambiguous / malformed requests must be rejected before application decode.
foreach ([
             "GET / HTTP/1.1\r\nUser-Agent: test\r\n\r\n" => 'HTTP/1.1 Host required',
             "POST / HTTP/1.1\r\nHost: example.test\r\nContent-Length: 1\r\nContent-Length: 2\r\n\r\na" => 'duplicate Content-Length rejected',
             "POST / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\nContent-Length: 4\r\n\r\n0\r\n\r\n" => 'TE + Content-Length rejected',
             "GET / HTTP/1.1\r\nHost: one.test\r\nHost: two.test\r\n\r\n" => 'duplicate Host rejected',
             "GET / HTTP/1.1\r\nHost: example.test:99999\r\n\r\n" => 'invalid Host port rejected',
             "POST / HTTP/1.0\r\nExpect: 100-continue\r\nContent-Length: 1\r\n\r\na" => 'HTTP/1.0 Expect rejected',
             "POST / HTTP/1.1\r\nHost: example.test\r\nTransfer-Encoding: chunked\r\nTrailer: Content-Length\r\n\r\n0\r\nContent-Length: 10\r\n\r\n" => 'forbidden framing trailer rejected',
         ] as $invalid => $label) {
    [$badServer, $badPeer] = connectionPair();
    $bad = new TcpConnection(new Select(), $badServer, '127.0.0.1:12345');
    $bad->protocol = Http::class;
    ok(Http::input($invalid, $bad) === 0, $label);
    $bad->destroy();
    fclose($badPeer);
}

// Forwarded client IP is trusted only when the socket peer is an explicitly trusted proxy.
[$proxyServer, $proxyPeer] = connectionPair();
$proxyConnection = new TcpConnection(new Select(), $proxyServer, '127.0.0.1:12345');
$proxyRequest = new Request("GET / HTTP/1.1\r\nHost: example.test\r\nX-Forwarded-For: 203.0.113.7\r\n\r\n");
$proxyRequest->connection = $proxyConnection;
Request::$trustedProxies = [];
ok($proxyRequest->getRequestIp() === '127.0.0.1', 'untrusted forwarded IP ignored');
Request::$trustedProxies = ['127.0.0.1'];
ok($proxyRequest->getRequestIp() === '203.0.113.7', 'trusted proxy forwarded IP accepted');
Request::$trustedProxies = [];
$proxyRequest->destroy();
$proxyConnection->destroy();
fclose($proxyPeer);

// File session backend roundtrip + explicit flush.
$sessionDir = sys_get_temp_dir() . '/localzet-session-smoke-' . bin2hex(random_bytes(4));
Session::setHandler(new FileSessionHandler($sessionDir));
$sessionId = bin2hex(random_bytes(32));
$session = new Session($sessionId);
$session->put(['user' => 42, 'nullable' => null]);
$session->save();
$reloaded = new Session($sessionId);
ok($reloaded->get('user') === 42 && $reloaded->exists('nullable') && !$reloaded->has('nullable'), 'HTTP session roundtrip');
$reloaded->flush();
$reloaded->save();
$empty = new Session($sessionId);
ok($empty->all() === [], 'HTTP session flush');
@rmdir($sessionDir);

// HTTP/1.1 chunked response must never emit conflicting Content-Length.
$chunkedHead = (string)(new Response(200))->withChunkedTransfer();
ok(str_contains($chunkedHead, "Transfer-Encoding: chunked\r\n")
    && !str_contains($chunkedHead, "Content-Length:"), 'HTTP chunked response has no Content-Length');

// HTTP conditional/range file serving.
$fileFixture = sys_get_temp_dir() . '/localzet-http-range-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($fileFixture, 'abcdefghij');

$rangeRequest = new Request("GET /asset HTTP/1.1\r\nHost: example.test\r\nRange: bytes=2-5\r\n\r\n");
$rangeResponse = (new Response())->withFileForRequest($rangeRequest, $fileFixture);
$rangeEncoded = Http::encode($rangeResponse, $conn);
ok(str_starts_with($rangeEncoded, 'HTTP/1.1 206') && str_ends_with($rangeEncoded, 'cdef'), 'HTTP single byte range');
ok(str_contains($rangeEncoded, 'Content-Range: bytes 2-5/10'), 'HTTP Content-Range header');

$headRequest = new Request("HEAD /asset HTTP/1.1\r\nHost: example.test\r\nRange: bytes=2-5\r\n\r\n");
$headEncoded = Http::encode((new Response())->withFileForRequest($headRequest, $fileFixture), $conn);
[$headHeaders, $headBody] = explode("\r\n\r\n", $headEncoded, 2);
ok(str_starts_with($headHeaders, 'HTTP/1.1 206') && str_contains($headHeaders, 'Content-Length: 4') && $headBody === '', 'HTTP HEAD range without file read body');

$etagProbe = (new Response())->withFile($fileFixture);
$etag = (string)$etagProbe->getHeader('ETag');
$conditionalRequest = new Request("GET /asset HTTP/1.1\r\nHost: example.test\r\nIf-None-Match: {$etag}\r\n\r\n");
$conditionalEncoded = Http::encode((new Response())->withFileForRequest($conditionalRequest, $fileFixture), $conn);
ok(str_starts_with($conditionalEncoded, 'HTTP/1.1 304') && !str_contains($conditionalEncoded, 'abcdefghij'), 'HTTP ETag conditional request');

$invalidRangeRequest = new Request("GET /asset HTTP/1.1\r\nHost: example.test\r\nRange: bytes=99-100\r\n\r\n");
$invalidRangeEncoded = Http::encode((new Response())->withFileForRequest($invalidRangeRequest, $fileFixture), $conn);
ok(str_starts_with($invalidRangeEncoded, 'HTTP/1.1 416') && str_contains($invalidRangeEncoded, 'Content-Range: bytes */10'), 'HTTP invalid range response');

$failedMatchRequest = new Request("GET /asset HTTP/1.1\r\nHost: example.test\r\nIf-Match: \"not-current\"\r\n\r\n");
$failedMatchEncoded = Http::encode((new Response())->withFileForRequest($failedMatchRequest, $fileFixture), $conn);
ok(str_starts_with($failedMatchEncoded, 'HTTP/1.1 412'), 'HTTP If-Match precondition');

$oldDate = gmdate('D, d M Y H:i:s', max(0, filemtime($fileFixture) - 60)) . ' GMT';
$unmodifiedRequest = new Request("GET /asset HTTP/1.1\r\nHost: example.test\r\nIf-Unmodified-Since: {$oldDate}\r\n\r\n");
$unmodifiedEncoded = Http::encode((new Response())->withFileForRequest($unmodifiedRequest, $fileFixture), $conn);
ok(str_starts_with($unmodifiedEncoded, 'HTTP/1.1 412'), 'HTTP If-Unmodified-Since precondition');
@unlink($fileFixture);

// Expect: 100-continue отправляется один раз до получения body.
[$continueServer, $continuePeer] = connectionPair();
$continueConnection = new TcpConnection(new Select(), $continueServer, '127.0.0.1:12345');
$continueConnection->protocol = Http::class;
$expectHead = "POST /upload HTTP/1.1\r\nHost: example.test\r\nContent-Length: 4\r\nExpect: 100-continue\r\n\r\n";
$expectedLength = Http::input($expectHead, $continueConnection);
ok($expectedLength === strlen($expectHead) + 4, 'HTTP Expect frame predicts body length');
Http::input($expectHead, $continueConnection);
stream_set_blocking($continuePeer, false);
usleep(20_000);
$continueRaw = fread($continuePeer, 8192);
ok(substr_count((string)$continueRaw, '100 Continue') === 1, 'HTTP 100 Continue sent once');
$continueConnection->destroy();
fclose($continuePeer);

// Idle timeout opt-in: default remains unlimited, configured socket is reaped.
[$idleServer, $idlePeer] = connectionPair();
$idleLoop = new Select();
$idleConnection = new TcpConnection($idleLoop, $idleServer, '127.0.0.1:12345');
$idleConnection->setIdleTimeout(0.01);
$idleLoop->delay(0.04, static fn() => $idleLoop->stop());
$idleLoop->run();
ok($idleConnection->getStatus() === TcpConnection::STATUS_CLOSED, 'TCP idle timeout');
fclose($idlePeer);

// Frame protocol roundtrip.
$packet = Frame::encode('payload', $conn);
ok(Frame::input($packet, $conn) === strlen($packet), 'Frame protocol length');
ok(Frame::decode($packet, $conn) === 'payload', 'Frame protocol decode');

// Async UDP: application scheme должен кодироваться поверх UDP, а не становиться transport scheme.
$udpServer = stream_socket_server('udp://127.0.0.1:0', $udpErrno, $udpError, STREAM_SERVER_BIND);
if ($udpServer === false) throw new RuntimeException("Unable to create UDP fixture: $udpError");
$udpAddress = (string)stream_socket_get_name($udpServer, false);
[, $udpPort] = explode(':', $udpAddress, 2);
$udpClient = new AsyncUdpConnection('text://127.0.0.1:' . $udpPort, [], new Select());
ok($udpClient->send('udp-ping') === true, 'Async UDP send');
stream_set_timeout($udpServer, 1);
$udpPayload = stream_socket_recvfrom($udpServer, 65535);
ok($udpPayload === "udp-ping\n", 'Async UDP application protocol scheme');
$udpClient->close();
fclose($udpServer);

// WebSocket server handshake.
$conn->protocol = Websocket::class;
$key = base64_encode(random_bytes(16));
$handshake = "GET /socket HTTP/1.1\r\nHost: example.test\r\nUpgrade: websocket\r\nConnection: keep-alive, Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
$length = Websocket::input($handshake, $conn);
ok($length === strlen($handshake), 'WebSocket handshake framing');
ok(Websocket::decode($handshake, $conn) === null, 'WebSocket handshake not emitted as message');
stream_set_blocking($peerSock, false);
usleep(20_000);
$upgrade = fread($peerSock, 8192);
ok(str_contains($upgrade, '101 Switching Protocols') && str_contains($upgrade, 'Sec-WebSocket-Accept:'), 'WebSocket handshake response');

// WebSocket opening handshake rejects ambiguous duplicate security headers.
[$dupWsServer, $dupWsPeer] = connectionPair();
$dupWs = new TcpConnection(new Select(), $dupWsServer, '127.0.0.1:12345');
$dupWs->protocol = Websocket::class;
$dupKey1 = base64_encode(random_bytes(16));
$dupKey2 = base64_encode(random_bytes(16));
$duplicateHandshake = "GET /socket HTTP/1.1\r\n"
    . "Host: example.test\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: {$dupKey1}\r\n"
    . "Sec-WebSocket-Key: {$dupKey2}\r\n"
    . "Sec-WebSocket-Version: 13\r\n\r\n";
ok(Websocket::input($duplicateHandshake, $dupWs) === 0, 'WebSocket duplicate key rejected');
stream_set_blocking($dupWsPeer, false);
usleep(20_000);
$duplicateReject = fread($dupWsPeer, 8192);
ok(str_starts_with((string)$duplicateReject, 'HTTP/1.1 400'), 'WebSocket ambiguous handshake response');
$dupWs->destroy();
fclose($dupWsPeer);

// WebSocket Host validation follows HTTP rules, including the real port range.
[$badHostWsServer, $badHostWsPeer] = connectionPair();
$badHostWs = new TcpConnection(new Select(), $badHostWsServer, '127.0.0.1:12345');
$badHostWs->protocol = Websocket::class;
$badHostKey = base64_encode(random_bytes(16));
$badHostHandshake = "GET /socket HTTP/1.1\r\nHost: example.test:99999\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$badHostKey}\r\nSec-WebSocket-Version: 13\r\n\r\n";
ok(Websocket::input($badHostHandshake, $badHostWs) === 0, 'WebSocket invalid Host port rejected');
$badHostWs->destroy();
fclose($badHostWsPeer);

[$oldWsServer, $oldWsPeer] = connectionPair();
$oldWs = new TcpConnection(new Select(), $oldWsServer, '127.0.0.1:12345');
$oldWs->protocol = Websocket::class;
$oldKey = base64_encode(random_bytes(16));
$oldHandshake = "GET /socket HTTP/1.0\r\nHost: example.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$oldKey}\r\nSec-WebSocket-Version: 13\r\n\r\n";
ok(Websocket::input($oldHandshake, $oldWs) === 0, 'WebSocket requires HTTP/1.1 opening handshake');
$oldWs->destroy();
fclose($oldWsPeer);

// Masked client text frame.
$payload = 'hello';
$mask = "\x01\x02\x03\x04";
$masked = $payload;
for ($i = 0; $i < strlen($masked); $i++) $masked[$i] = $masked[$i] ^ $mask[$i & 3];
$clientFrame = chr(0x81) . chr(0x80 | strlen($payload)) . $mask . $masked;
ok(Websocket::input($clientFrame, $conn) === strlen($clientFrame), 'WebSocket masked frame length');
ok(Websocket::decode($clientFrame, $conn) === 'hello', 'WebSocket masked frame decode');

// Fragmented messages are delivered only after the final continuation frame and
// the aggregate payload is bounded by maxPackageSize, not only each frame.
$makeMaskedFrame = static function (string $payload, int $opcode, bool $fin): string {
    $mask = "\x05\x06\x07\x08";
    $masked = $payload;
    for ($i = 0; $i < strlen($masked); $i++) {
        $masked[$i] = $masked[$i] ^ $mask[$i & 3];
    }
    return chr(($fin ? 0x80 : 0x00) | $opcode) . chr(0x80 | strlen($payload)) . $mask . $masked;
};
$fragmentA = $makeMaskedFrame('hel', 0x1, false);
$fragmentB = $makeMaskedFrame('lo', 0x0, true);
ok(Websocket::decode($fragmentA, $conn) === null, 'WebSocket first fragment is buffered');
ok(Websocket::decode($fragmentB, $conn) === 'hello', 'WebSocket fragmented message reassembly');

[$limitedWsServer, $limitedWsPeer] = connectionPair();
$limitedWs = new TcpConnection(new Select(), $limitedWsServer, '127.0.0.1:12345');
$limitedWs->protocol = Websocket::class;
$limitedWs->maxPackageSize = 4;
$limitedWs->context->websocketHandshake = true;
$partOne = $makeMaskedFrame('abc', 0x1, false);
$partTwo = $makeMaskedFrame('de', 0x0, true);
ok(Websocket::input($partOne, $limitedWs) === strlen($partOne), 'WebSocket limited first fragment accepted');
Websocket::decode($partOne, $limitedWs);
ok(Websocket::input($partTwo, $limitedWs) === strlen($partTwo), 'WebSocket limited continuation frame accepted');
Websocket::decode($partTwo, $limitedWs);
ok(in_array($limitedWs->getStatus(), [TcpConnection::STATUS_ENDING, TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED], true),
    'WebSocket aggregate fragmented message limit');
$limitedWs->destroy();
fclose($limitedWsPeer);

// Log rotation is serialized and retains the triggering previous log.
$oldLogFile = Server::$logFile;
$oldLogMax = Server::$logFileMaxSize;
$oldDaemonize = Server::$daemonize;
$logBase = sys_get_temp_dir() . '/localzet-log-smoke-' . getmypid() . '.log';
@unlink($logBase);
@unlink($logBase . '.lock');
foreach (glob($logBase . '.*.*') ?: [] as $archive) @unlink($archive);
Server::$logFile = $logBase;
Server::$logFileMaxSize = 32;
Server::$daemonize = true;
Server::log(str_repeat('A', 64));
Server::log('second-line');
$archives = glob($logBase . '.*.*') ?: [];
ok(count($archives) === 1 && str_contains((string)file_get_contents($logBase), 'second-line'), 'multi-process-safe log rotation');
Server::$logFile = $oldLogFile;
Server::$logFileMaxSize = $oldLogMax;
Server::$daemonize = $oldDaemonize;
@unlink($logBase);
@unlink($logBase . '.lock');
foreach ($archives as $archive) @unlink($archive);

// Event loop timer.
$fired = false;
$loop2 = new Select();
$loop2->delay(0.001, static function () use (&$fired, $loop2): void {
    $fired = true;
    $loop2->stop();
});
$loop2->run();
ok($fired, 'Select event-loop timer');

// Event backend resolution is explicit: requested backends never silently
// degrade to another implementation.
$selectedLoop = EventLoopFactory::create('select');
ok($selectedLoop instanceof Select, 'EventLoopFactory explicit Select backend');
$capabilities = EventLoopFactory::capabilities();
ok(($capabilities['select'] ?? false) === true, 'EventLoopFactory capability report');
$unknownRejected = false;
try {
    EventLoopFactory::create('definitely-not-a-loop');
} catch (RuntimeException) {
    $unknownRejected = true;
}
ok($unknownRejected, 'EventLoopFactory rejects unknown backend');

$conn->destroy();
fclose($peerSock);


ok(Server::UI_SAFE_LENGTH === 4, 'legacy UI_SAFE_LENGTH compatibility constant');
ok(isset(Server::ERROR_TYPE[E_ERROR]), 'legacy ERROR_TYPE compatibility table');
ok(property_exists(Server::class, 'outputStream'), 'legacy outputStream compatibility property');

echo "All smoke tests passed.\n";
