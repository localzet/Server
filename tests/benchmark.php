<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\Server\Protocols\Http\Request;

$smoke = in_array('--smoke', $argv, true);
$iterations = $smoke ? 100 : max(1, (int) (getenv('LOCALZET_BENCH_ITERATIONS') ?: 100_000));
$warmup = $smoke ? 10 : max(0, (int) (getenv('LOCALZET_BENCH_WARMUP') ?: 5_000));
$wire = "GET /benchmark?q=1 HTTP/1.1\r\nHost: localhost\r\nAccept: */*\r\n\r\n";
$parse = static fn (): mixed => (new Request($wire))->get('q');

for ($i = 0; $i < $warmup; ++$i) {
    $parse();
}

$start = hrtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    $request = $parse();
}
$elapsedNs = hrtime(true) - $start;

$result = [
    'benchmark' => 'http_request_parse',
    'iterations' => $iterations,
    'warmup_iterations' => $warmup,
    'elapsed_ns' => $elapsedNs,
    'operations_per_second' => round($iterations / ($elapsedNs / 1_000_000_000), 2),
    'ns_per_operation' => round($elapsedNs / $iterations, 2),
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'os' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'),
    'timestamp_utc' => gmdate(DATE_ATOM),
    'smoke' => $smoke,
];

if (!isset($request)) {
    throw new RuntimeException('Benchmark did not execute any operations.');
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
