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
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;

$port = (int)(getenv('LOCALZET_TEST_GATEWAY_BACKEND_PORT') ?: 19110);
$server = new Server('http://127.0.0.1:' . $port);
$server->name = 'gateway-http-backend';
$server->count = 1;
$server->onMessage = static function (TcpConnection $connection, Request $request): void {
    $body = $request->rawBody();
    $payload = json_encode([
        'method' => $request->method(),
        'path' => $request->path(),
        'host' => $request->host(),
        'xff' => $request->header('x-forwarded-for'),
        'xfp' => $request->header('x-forwarded-proto'),
        'forwarded' => $request->header('forwarded'),
        'body_len' => strlen($body),
        'body_sha256' => hash('sha256', $body),
        'backend_pid' => getmypid(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $connection->send(new Response(200, ['Content-Type' => 'application/json'], $payload));
};
Server::runAll();
