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
        if (is_file($file)) {
            require_once $file;
        }
    }
});
require_once $root . '/Helpers.php';

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
use localzet\Timer;

$runtimeDirectory = (string)(getenv('LOCALZET_TEST_RUNTIME_HOOK_DIR') ?: '');
$traceFile = $runtimeDirectory . '/trace.log';
$upgradeMode = (string)getenv('LOCALZET_TEST_RUNTIME_HOOK_UPGRADE') === '1';
$exit86Mode = (string)getenv('LOCALZET_TEST_RUNTIME_HOOK_EXIT_86') === '1';
if ($runtimeDirectory === '' || !is_dir($runtimeDirectory)) {
    throw new RuntimeException('Runtime hook fixture directory is required.');
}

Server::$pidFile = $runtimeDirectory . '/server.pid';
Server::$statusFile = $runtimeDirectory . '/server.status';
Server::$logFile = $runtimeDirectory . '/server.log';

Server::onBeforeRun(static function () use ($runtimeDirectory, $traceFile): void {
    file_put_contents($traceFile, 'before:' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
    if ((string)getenv('LOCALZET_TEST_RUNTIME_HOOK_USER') !== '') {
        chmod($runtimeDirectory, 0777);
        chmod($traceFile, 0666);
    }
});

Server::onWorkerStart(static function (Server $server) use ($traceFile, $upgradeMode, $exit86Mode): void {
    file_put_contents(
        $traceFile,
        sprintf("worker:%d:%d\n", getmypid(), $server->id),
        FILE_APPEND | LOCK_EX,
    );

    if ((string)getenv('LOCALZET_TEST_RUNTIME_HOOK_THROW') === '1') {
        throw new RuntimeException('Expected runtime hook failure.');
    }

    if ($exit86Mode && $server->id === 0) {
        $marker = $traceFile . '.exit86';
        if (!is_file($marker)) {
            file_put_contents($marker, 'recycle');
            exit(86);
        }

        Timer::add(0.5, static function (): void {
            Server::requestShutdown(0, 'runtime hook integration shutdown after exit 86');
        });
        return;
    }

    if (!$upgradeMode && $server->id === 0) {
        Timer::add(0.8, static function (): void {
            Server::requestShutdown(0, 'runtime hook integration shutdown');
        });
    }
});

$port = (int)(getenv('LOCALZET_TEST_RUNTIME_HOOK_PORT') ?: 0);
$server = new Server('http://127.0.0.1:' . $port);
$server->name = 'runtime-hooks';
$server->count = max(1, (int)(getenv('LOCALZET_TEST_RUNTIME_HOOK_WORKERS') ?: 2));
$server->user = (string)(getenv('LOCALZET_TEST_RUNTIME_HOOK_USER') ?: '');
$server->onServerStart = static function () use ($traceFile): void {
    file_put_contents($traceFile, 'server-start:' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
};
$server->onServerReload = static function () use ($traceFile, $upgradeMode): void {
    file_put_contents(
        $traceFile,
        sprintf("reload:%d:%d\n", getmypid(), Server::$generation),
        FILE_APPEND | LOCK_EX,
    );

    if ($upgradeMode && Server::$generation === 1) {
        Server::requestShutdown(0, 'old worker requested shutdown during hot upgrade');
    }
};
$server->onMessage = static function (TcpConnection $connection, Request $request): void {
    $connection->send(new Response(200, ['Content-Type' => 'text/plain'], 'ok'));
    $connection->close();
};

Server::runAll();
