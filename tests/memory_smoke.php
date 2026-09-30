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

use localzet\Server\Protocols\Http\Request;

$wire = "POST /memory HTTP/1.1\r\nHost: example.test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 7\r\n\r\na=1&b=2";
for ($i = 0; $i < 1000; $i++) {
    $request = new Request($wire);
    $request->destroy();
}
gc_collect_cycles();
$baseline = memory_get_usage(true);
for ($i = 0; $i < 20000; $i++) {
    $request = new Request($wire);
    if ($request->post('b') !== '2') {
        throw new RuntimeException('Request parse regression during memory smoke.');
    }
    $request->destroy();
}
unset($request);
gc_collect_cycles();
$after = memory_get_usage(true);
$growth = max(0, $after - $baseline);
if ($growth > 8 * 1024 * 1024) {
    fwrite(STDERR, "MEMORY_SMOKE=fail growth={$growth}\n");
    exit(1);
}
echo "MEMORY_SMOKE=ok iterations=20000 growth={$growth}\n";
