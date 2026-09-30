<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDOUT, "SKIP: Revolt integration requires composer install\n");
    exit(0);
}

require $autoload;

use localzet\Server\Events\Fiber as RevoltAdapter;
use localzet\Timer;

$loop = new RevoltAdapter();
Timer::init($loop);
$completed = false;
$elapsed = 0.0;
$started = microtime(true);

$loop->delay(0.001, static function () use ($loop, &$completed, &$elapsed, $started): void {
    // This specifically verifies that Localzet callbacks run in the Fiber owned
    // by Revolt, so a suspension created by Timer::sleep() can resume normally.
    Timer::sleep(0.01);
    $elapsed = microtime(true) - $started;
    $completed = true;
    $loop->stop();
});

$loop->run();

if (!$completed || $elapsed < 0.008) {
    fwrite(STDERR, "FAIL: Revolt Fiber suspension/resume integration\n");
    exit(1);
}

fwrite(STDOUT, "OK: Revolt Fiber suspension/resume integration\n");
