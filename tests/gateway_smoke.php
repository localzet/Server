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
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require_once $root . '/Helpers.php';

use localzet\Server\Gateway\HttpBodyTracker;
use localzet\Server\Gateway\HttpRoute;
use localzet\Server\Events\Select;
use localzet\Server\Proxy\TcpProxy;
use localzet\Server\Proxy\Upstream;
use localzet\Server\Proxy\UpstreamPool;

function gatewayOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$pool = new UpstreamPool([], UpstreamPool::ROUND_ROBIN);
$a = new Upstream('127.0.0.1:9001', 2, 'a');
$b = new Upstream('127.0.0.1:9002', 1, 'b');
$pool->add($a)->add($b);
gatewayOk($pool->select() === $a && $pool->select() === $a && $pool->select() === $b, 'weighted round-robin');

$a->activeConnections = 8;
$b->activeConnections = 1;
$least = new UpstreamPool([$a, $b], UpstreamPool::LEAST_CONNECTIONS);
gatewayOk($least->select() === $b, 'least-connections strategy');

$pool->markFailure($a);
$pool->markFailure($a);
gatewayOk(!$a->isAvailable(), 'passive quarantine');
$pool->markSuccess($a);
gatewayOk($a->isAvailable(), 'successful probe restores upstream');

$proxyLoop = new Select();
$proxyServer = new \localzet\Server('tcp://127.0.0.1:0');
$proxyPool = new UpstreamPool([
    new Upstream('tcp://127.0.0.1:1'),
    new Upstream('tcp://127.0.0.1:2'),
], UpstreamPool::ROUND_ROBIN);
(new TcpProxy($proxyPool))->attach($proxyServer);
$proxySocketPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($proxySocketPair === false) {
    throw new RuntimeException('Unable to create TCP proxy test sockets.');
}
[$proxyClientSocket, $proxyPeerSocket] = $proxySocketPair;
$proxyClient = new \localzet\Server\Connection\TcpConnection($proxyLoop, $proxyClientSocket, '127.0.0.1:12345');
($proxyServer->onConnect)($proxyClient);
$proxyBackend = $proxyClient->context->tcpProxyBackend;
($proxyBackend->onConnect)($proxyBackend);
($proxyBackend->onError)($proxyBackend, 3, 'simulated post-connect send failure');
gatewayOk($proxyClient->getStatus() === \localzet\Server\Connection\TcpConnection::STATUS_CLOSED, 'TCP proxy closes client after post-connect backend failure');
gatewayOk(!isset($proxyClient->context->tcpProxyBackend), 'TCP proxy does not retry another upstream after client data may have been sent');
fclose($proxyPeerSocket);

$route = new HttpRoute($pool, 'api.example.test', '/api/', '/v1/');
gatewayOk($route->matches('api.example.test:8080', '/api/users'), 'HTTP route host/path match');
gatewayOk($route->rewriteTarget('/api/users?q=1') === '/v1/users?q=1', 'HTTP route prefix rewrite');

$length = HttpBodyTracker::forRequest(['content-length' => ['5']]);
gatewayOk($length->consume('he') === 2 && !$length->isComplete(), 'Content-Length incremental body');
gatewayOk($length->consume('lloNEXT') === 3 && $length->isComplete(), 'Content-Length message boundary');

$chunked = HttpBodyTracker::forRequest(['transfer-encoding' => ['chunked']]);
gatewayOk($chunked->consume("4\r\nWi") === 5 && !$chunked->isComplete(), 'chunked body partial frame');
$tail = "ki\r\n0\r\n\r\nNEXT";
$consumed = $chunked->consume($tail);
gatewayOk($consumed === strlen("ki\r\n0\r\n\r\n") && $chunked->isComplete(), 'chunked terminal boundary');

$oversizedChunkLine = HttpBodyTracker::forRequest(['transfer-encoding' => ['chunked']]);
$rejected = false;
try {
    $oversizedChunkLine->consume(str_repeat('a', 4096));
    $oversizedChunkLine->consume(str_repeat('a', 4097));
} catch (RuntimeException) {
    $rejected = true;
}
gatewayOk($rejected, 'oversized fragmented chunk-size line rejected');

$oversizedTrailers = HttpBodyTracker::forRequest(['transfer-encoding' => ['chunked']]);
$rejected = false;
try {
    $oversizedTrailers->consume("0\r\n" . str_repeat('x', 16384));
    $oversizedTrailers->consume(str_repeat('x', 16385));
} catch (RuntimeException) {
    $rejected = true;
}
gatewayOk($rejected, 'oversized incomplete trailers rejected');


// Gateway framing must reject ambiguous message boundaries.
$rejected = false;
try {
    HttpBodyTracker::forRequest(['content-length' => ['5', '6']]);
} catch (RuntimeException) {
    $rejected = true;
}
gatewayOk($rejected, 'conflicting Content-Length rejected');

$rejected = false;
try {
    HttpBodyTracker::forRequest(['content-length' => ['5'], 'transfer-encoding' => ['chunked']]);
} catch (RuntimeException) {
    $rejected = true;
}
gatewayOk($rejected, 'Transfer-Encoding plus Content-Length rejected');

$rejected = false;
try {
    HttpBodyTracker::forRequest(['transfer-encoding' => ['gzip, chunked']]);
} catch (RuntimeException) {
    $rejected = true;
}
gatewayOk($rejected, 'unsupported transfer coding rejected');

echo "Gateway smoke tests passed.\n";
