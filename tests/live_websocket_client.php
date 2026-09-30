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

use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Events\Select;

$port = (int)(getenv('LOCALZET_TEST_WS_PORT') ?: 19082);
$loop = new Select();
$client = new AsyncTcpConnection("ws://127.0.0.1:{$port}/integration", [], $loop);
$success = false;
$error = null;

$client->onWebSocketConnected = static function (AsyncTcpConnection $connection): void {
    $connection->send('client-roundtrip');
};
$client->onMessage = static function (AsyncTcpConnection $connection, mixed $message) use (&$success, $loop): void {
    $success = $message === 'echo:client-roundtrip';
    $connection->close();
    $loop->stop();
};
$client->onError = static function (AsyncTcpConnection $connection, int $code, string $message) use (&$error, $loop): void {
    $error = "[$code] $message";
    $loop->stop();
};
$loop->delay(3.0, static function () use (&$error, $client, $loop): void {
    $error ??= 'WebSocket client integration timed out.';
    $client->close();
    $loop->stop();
});

$client->connect();
$loop->run();

if (!$success) {
    fwrite(STDERR, ($error ?? 'Unexpected WebSocket response.') . PHP_EOL);
    exit(1);
}

echo "WS_CLIENT=ok\n";
