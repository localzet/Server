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

$port = (int)($argv[1] ?? 0);
$loop = new Select();
$client = new AsyncTcpConnection('frame://127.0.0.1:' . $port, [], $loop);
$response = null;
$error = null;
$client->onMessage = static function (AsyncTcpConnection $connection, mixed $message) use (&$response, $loop): void {
    $response = $message;
    $connection->close();
    $loop->stop();
};
$client->onError = static function (AsyncTcpConnection $connection, int $code, string $message) use (&$error, $loop): void {
    $error = "[$code] $message";
    $loop->stop();
};
$loop->delay(3.0, static function () use (&$error, $client, $loop): void {
    $error ??= 'Queued AsyncTcpConnection send timed out.';
    $client->close();
    $loop->stop();
});
$client->send('queued-before-connect');
$loop->run();

if ($response !== 'echo:queued-before-connect') {
    fwrite(STDERR, ($error ?? 'Unexpected AsyncTcpConnection response.') . PHP_EOL);
    exit(1);
}

echo "ASYNC_TCP_QUEUED_SEND=ok\n";
