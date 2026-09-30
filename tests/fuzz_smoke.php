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

use localzet\Server\Connection\TcpConnection;
use localzet\Server\Events\Select;
use localzet\Server\Protocols\Http;
use localzet\Server\Protocols\Websocket;

mt_srand(7000911);
$loop = new Select();
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    throw new RuntimeException('socket pair failed');
}
$conn = new TcpConnection($loop, $pair[0], '127.0.0.1:1234');

// Deterministic malformed-input fuzz smoke: parser may reject or request more
// bytes, but must not crash the PHP process or allocate unbounded packages.
for ($i = 0; $i < 3000; $i++) {
    $length = mt_rand(0, 512);
    $buffer = '';
    for ($j = 0; $j < $length; $j++) {
        $buffer .= chr(mt_rand(0, 255));
    }
    try {
        $result = Http::input($buffer, $conn);
        if ($result < 0 || $result > $conn->maxPackageSize) {
            throw new RuntimeException('HTTP parser returned invalid frame length.');
        }
    } catch (Throwable) {
        // Invalid wire input is expected.
    }
}

$conn->protocol = Websocket::class;
for ($i = 0; $i < 2000; $i++) {
    $length = mt_rand(0, 256);
    $buffer = '';
    for ($j = 0; $j < $length; $j++) {
        $buffer .= chr(mt_rand(0, 255));
    }
    try {
        Websocket::input($buffer, $conn);
    } catch (Throwable) {
        // Invalid frame is expected; process integrity is the assertion.
    }
}

$conn->destroy();
@fclose($pair[1]);
echo "FUZZ_SMOKE=ok http=3000 websocket=2000\n";
