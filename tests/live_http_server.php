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

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
use localzet\Server\Protocols\Http\Chunk;
use localzet\Server\Protocols\Http\ServerSentEvents;

$port = (int)(getenv('LOCALZET_TEST_HTTP_PORT') ?: 19081);
$scheme = strtolower((string)(getenv('LOCALZET_TEST_HTTP_SCHEME') ?: 'http'));
$largeFixture = sys_get_temp_dir() . '/localzet-server-large-fixture.bin';
$bootValueFile = (string)(getenv('LOCALZET_TEST_BOOT_VALUE_FILE') ?: '');
$bootValue = $bootValueFile !== '' && is_file($bootValueFile)
    ? trim((string)file_get_contents($bootValueFile))
    : 'default';
$largeBody = str_repeat('0123456789abcdef', 196608); // 3 MiB.
if (!is_file($largeFixture) || filesize($largeFixture) !== strlen($largeBody)) {
    file_put_contents($largeFixture, $largeBody);
}
unset($largeBody);

$server = new Server($scheme . '://127.0.0.1:' . $port);
$server->name = 'integration-http';
$server->count = max(1, (int)(getenv('LOCALZET_TEST_WORKERS') ?: 2));
$server->maxRequests = max(0, (int)(getenv('LOCALZET_TEST_MAX_REQUESTS') ?: 0));
$server->maxConnections = max(0, (int)(getenv('LOCALZET_TEST_MAX_CONNECTIONS') ?: 0));
$server->frameTimeout = max(0.0, (float)(getenv('LOCALZET_TEST_FRAME_TIMEOUT') ?: 0.0));
$server->tlsHandshakeTimeout = max(0.0, (float)(getenv('LOCALZET_TEST_TLS_TIMEOUT') ?: 10.0));
$reloadDelayMs = max(0, (int)(getenv('LOCALZET_TEST_RELOAD_DELAY_MS') ?: 0));
if ($reloadDelayMs > 0) {
    $server->onServerReload = static function () use ($reloadDelayMs): void {
        usleep($reloadDelayMs * 1000);
    };
}
$server->onMessage = static function (TcpConnection $connection, Request $request) use ($largeFixture, $bootValue): void {
    if ($request->path() === '/slow-dispatch') {
        // Даёт integration test окно, чтобы POSIX graceful signal пришёл прямо
        // во время user callback, а не между двумя event-loop итерациями.
        usleep(400_000);
    }

    if ($request->path() === '/large') {
        $connection->send((new Response(200, ['Connection' => 'close']))->withFile($largeFixture));
        // Проверяем важный сценарий: close сразу после send(file) не должен оборвать stream.
        $connection->close();
        return;
    }

    if ($request->path() === '/gzip') {
        $body = str_repeat('localzet-protocol-compression-', 512);
        $response = (new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Connection' => 'close',
        ], $body))->withCompressionForRequest($request, 128);
        $connection->send($response);
        $connection->close();
        return;
    }

    if ($request->path() === '/chunked') {
        $connection->send((new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Connection' => 'close',
        ]))->withChunkedTransfer());
        $connection->send(new Chunk('hello '));
        $connection->send(new Chunk('world'));
        $connection->send(new Chunk(''));
        return;
    }

    if ($request->path() === '/sse') {
        $event = new ServerSentEvents("one\ntwo", 'message', '1', 1000);
        $connection->send(new Response(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'close',
        ], (string)$event));
        $connection->close();
        return;
    }

    $payload = json_encode([
        'pid' => getmypid(),
        'path' => $request->path(),
        'worker' => 'localzet',
        'boot_value' => $bootValue,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $headers = ['Content-Type' => 'application/json; charset=utf-8'];
    $keepAliveFixture = in_array($request->path(), ['/keepalive', '/pipeline-1', '/pipeline-2'], true);
    if (!$keepAliveFixture) {
        $headers['Connection'] = 'close';
    }

    $connection->send(new Response(200, $headers, $payload));
    if (!$keepAliveFixture) {
        // Legacy/application-driven close path остаётся покрыт большинством fixtures.
        $connection->close();
    }
};

Server::runAll();
