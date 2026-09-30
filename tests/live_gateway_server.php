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
    $prefix = 'localzet\\Server\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require_once $root . '/Helpers.php';

use localzet\Server;
use localzet\Server\Gateway\HttpReverseProxy;
use localzet\Server\Gateway\HttpRoute;
use localzet\Server\Proxy\UpstreamPool;

$port = (int)(getenv('LOCALZET_TEST_GATEWAY_PORT') ?: 19112);
$backendPort = (int)(getenv('LOCALZET_TEST_GATEWAY_BACKEND_PORT') ?: 19110);
$wsPort = (int)(getenv('LOCALZET_TEST_GATEWAY_WS_BACKEND_PORT') ?: 19111);
$deadPort = (int)(getenv('LOCALZET_TEST_GATEWAY_DEAD_PORT') ?: 19119);

$httpPool = new UpstreamPool([], UpstreamPool::ROUND_ROBIN, 1, 1.0);
$httpPool->add("tcp://127.0.0.1:{$deadPort}", 1, 'dead-first');
$httpPool->add("tcp://127.0.0.1:{$backendPort}", 1, 'http-backend');
$wsPool = new UpstreamPool(["tcp://127.0.0.1:{$wsPort}"], UpstreamPool::ROUND_ROBIN, 1, 1.0);

$gateway = (new HttpReverseProxy())
    ->setConnectTimeout(0.3)
    ->route(new HttpRoute($httpPool, 'api.test', '/api/', '/v1/'))
    ->route(new HttpRoute($wsPool, 'ws.test', '/socket'));

$server = new Server('tcp://127.0.0.1:' . $port);
$server->name = 'integration-gateway';
$server->count = 1;
$gateway->attach($server);
Server::runAll();
