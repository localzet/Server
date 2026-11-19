<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Runtime/HotUpgradeBroker.php';

use localzet\Server\Runtime\HotUpgradeBroker;

$pair = [];
$closeOnExecPairAvailable = HotUpgradeBroker::createCloseOnExecPair($pair);
$hotUpgradeAvailable = HotUpgradeBroker::isSupported();

if (PHP_VERSION_ID < 80400 && ($closeOnExecPairAvailable || $hotUpgradeAvailable || $pair !== [])) {
    throw new RuntimeException('PHP versions before 8.4 must not advertise close-on-exec socket pairs.');
}

foreach ($pair as $socket) {
    if ($socket instanceof \Socket) {
        socket_close($socket);
    }
}

if (($argv[1] ?? null) === '--print-hot-upgrade') {
    echo $hotUpgradeAvailable ? '1' : '0';
    exit(0);
}

echo 'OK: runtime capabilities match PHP socket flag support.' . PHP_EOL;
