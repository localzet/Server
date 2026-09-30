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
use localzet\Server\Proxy\TcpProxy;
use localzet\Server\Proxy\UpstreamPool;

$port = (int)(getenv('LOCALZET_TEST_TCP_PROXY_PORT') ?: 19114);
$backendPort = (int)(getenv('LOCALZET_TEST_TCP_BACKEND_PORT') ?: 19113);
$deadPort = (int)(getenv('LOCALZET_TEST_GATEWAY_DEAD_PORT') ?: 19119);
$pool = new UpstreamPool([], UpstreamPool::ROUND_ROBIN, 1, 1.0);
$pool->add("tcp://127.0.0.1:{$deadPort}", 1, 'dead-first');
$pool->add("tcp://127.0.0.1:{$backendPort}", 1, 'echo-backend');
$proxy = new TcpProxy($pool, 0.3);
$server = new Server('tcp://127.0.0.1:' . $port);
$server->name = 'integration-tcp-proxy';
$server->count = 1;
$proxy->attach($server);
Server::runAll();
