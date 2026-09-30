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

$port = (int)(getenv('LOCALZET_TEST_CRASH_PORT') ?: 19083);
$counterFile = (string)(getenv('LOCALZET_TEST_CRASH_COUNTER') ?: (sys_get_temp_dir() . '/localzet-crash-counter'));
$crashes = max(0, (int)(getenv('LOCALZET_TEST_CRASH_COUNT') ?: 2));

$server = new Server('http://127.0.0.1:' . $port);
$server->name = 'integration-crash';
$server->count = 1;

$server->onServerStart = static function () use ($counterFile, $crashes): void {
    $handle = fopen($counterFile, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open crash counter.');
    }
    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = trim((string)stream_get_contents($handle));
    $attempts = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($attempts)) {
        $attempts = [];
    }
    $attempts[] = microtime(true);
    $attempt = count($attempts);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($attempts, JSON_THROW_ON_ERROR));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($attempt <= $crashes) {
        throw new RuntimeException("Intentional startup crash #{$attempt}");
    }
};

$server->onMessage = static function (TcpConnection $connection, Request $request): void {
    $connection->send(new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'pid' => getmypid(),
        'path' => $request->path(),
    ], JSON_THROW_ON_ERROR)));
    $connection->close();
};

Server::runAll();
