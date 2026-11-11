<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     AGPL-3.0-or-later
 */

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

$address = (string)($argv[1] ?? '');
$loop = new Select();
$client = new AsyncTcpConnection('tcp://example.test:80', [], $loop);
$client->proxyHttp = $address;
$message = null;
$error = null;
$client->onMessage = static function (AsyncTcpConnection $connection, mixed $data) use (&$message, $loop): void {
    $message = $data;
    $connection->close();
    $loop->stop();
};
$client->onError = static function (AsyncTcpConnection $connection, int $code, string $reason) use (&$error, $loop): void {
    $error = $reason;
    $loop->stop();
};
$loop->delay(2.0, static function () use (&$error, $client, $loop): void {
    $error ??= 'HTTP CONNECT tunnel greeting timed out.';
    $client->close();
    $loop->stop();
});
$client->connect();
$loop->run();

if ($message !== 'server-greeting') {
    fwrite(STDERR, ($error ?? 'HTTP CONNECT tunnel greeting was lost.') . PHP_EOL);
    exit(1);
}

echo "HTTP_CONNECT_PRESERVES_GREETING=ok\n";
