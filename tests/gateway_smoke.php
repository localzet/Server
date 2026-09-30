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
