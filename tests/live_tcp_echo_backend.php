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
use localzet\Server\Connection\TcpConnection;

$port = (int)(getenv('LOCALZET_TEST_TCP_BACKEND_PORT') ?: 19113);
$server = new Server('tcp://127.0.0.1:' . $port);
$server->name = 'tcp-echo-backend';
$server->count = 1;
$server->onMessage = static fn(TcpConnection $connection, string $message) => $connection->send($message, true);
Server::runAll();
