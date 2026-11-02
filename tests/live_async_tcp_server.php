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

$port = (int)($argv[2] ?? 0);
$server = new localzet\Server('frame://127.0.0.1:' . $port);
$server->count = 1;
$server->onMessage = static function ($connection, string $message): void {
    $connection->send('echo:' . $message);
};
localzet\Server::runAll();
