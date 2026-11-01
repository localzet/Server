# Localzet Server

[Русская документация](README.ru.md)

High-performance event-driven server runtime for PHP with TCP, UDP, HTTP/1.1, WebSocket, timers, long-lived connections
and multi-process workers.

This development branch targets the 7.0 runtime line, including process supervision, HTTP/1.x/WebSocket protocols and a
bounded L4/L7 gateway. The released 4.x packages used by Cluster and other libraries are a separate compatibility line;
do not treat installation of the published package as installation of this branch.

## Requirements

- PHP 8.1+
- Composer is required for package installation, but the runtime core has no mandatory third-party PHP package
  dependency
- Unix: `pcntl` and `posix` are recommended for multi-process mode
- Windows works in single-process mode
- The core CI matrix covers PHP 8.1 through 8.5; optional extension combinations are not all covered by that matrix

Optional integrations such as `revolt/event-loop`, `localzet/events`, `sockets`, `event`, `ev`, `swoole`, `swow`, Redis
and MongoDB add event-loop, lifecycle, hot-upgrade and session capabilities. Portable `Select` remains available without
Revolt.

## Install

```bash
composer require localzet/server
```

For development of this branch:

```bash
composer install
composer check
composer test:integration
composer release:audit
composer analyze
composer benchmark -- --smoke
```

`composer analyze` performs basic PHPStan level-0 analysis of the core. Optional Swow and MongoDB adapters are excluded
from this check because their extension/package declarations are not installed in the core environment. Higher-level
type analysis and separate optional-adapter validation remain follow-up work.

The core keeps `composer.lock` out of version control because this is a library: consuming applications resolve and lock
their own dependency graph. CI resolves one PHP 8.1-compatible toolchain for the PHP matrix and separately resolves and
tests the latest allowed dev dependencies in its weekly update job. The project uses PHPStan for static analysis and
script-based PHP smoke/integration tests; PHPUnit is not installed because the current test suite does not use it. Run
`composer audit` when reviewing dependency updates.

## Support and operations

- The declared runtime floor is PHP 8.1. CI exercises PHP 8.1–8.5 on Linux; Windows is documented for single-process
  use, but is not part of this CI matrix. Optional extension/backend combinations require separate validation.
- On Unix, multi-process supervision depends on `pcntl` and `posix`. Hot upgrade additionally depends on socket
  descriptor passing support; check `php server.php capabilities --json` on the target host before relying on it.
- Keep HTTP header/frame/connection limits and timeouts appropriate to the deployment. Configure trusted proxies
  explicitly before consuming forwarded client-IP headers. Terminate TLS at a trusted edge or configure the TLS
  listener; use graceful `SIGTERM` shutdown in orchestrators.
- The gateway is an HTTP/1.x and raw TCP proxy, not a general-purpose API gateway. Route only to trusted upstreams,
  review forwarded-header policy, and size connection/time limits for expected traffic.
- Public API compatibility follows the project release line. The 7.0 development branch is distinct from published
  4.x packages; pin and test the exact branch/package version used by applications.

## Development setup

See [contribution guidelines](.github/CONTRIBUTING.md) before submitting changes. Repository settings are provided for
PhpStorm and VS Code; VS Code recommends Intelephense, EditorConfig and Xdebug. Install Composer dev dependencies, then
use the Composer commands listed above or the VS Code task palette. The test suite consists of PHP scripts rather than
PHPUnit test cases.

For security reports, follow the [security policy](.github/SECURITY.md). Do not put vulnerability details in public issues.

## Performance measurement

Run the repeatable parser microbenchmark with `composer benchmark`. It prints JSON including PHP version, OS,
architecture, warm-up, iteration count, elapsed time, throughput and peak memory. For a quick harness check use
`composer benchmark -- --smoke`; smoke numbers are not performance results. Use the same host, PHP build, extensions,
and configuration when comparing revisions. This microbenchmark measures HTTP request parsing only; it does not
represent end-to-end throughput, latency under load, or multi-worker capacity.

## HTTP example

```php
<?php

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;

require __DIR__ . '/vendor/autoload.php';

$server = new Server('http://0.0.0.0:8080');
$server->name = 'api';
$server->count = 4;

// Production guardrails are opt-in and can be tuned per endpoint.
$server->maxRequests = 10_000;
$server->maxLifetime = 3600;
$server->maxMemory = 256 * 1024 * 1024;
$server->maxConnections = 10_000;
$server->idleTimeout = 60;
$server->frameTimeout = 15;
$server->tlsHandshakeTimeout = 10;

$server->onMessage = static function (TcpConnection $connection, Request $request): void {
    $connection->send(new Response(200, [
        'Content-Type' => 'application/json; charset=utf-8',
    ], json_encode([
        'ok' => true,
        'path' => $request->path(),
    ], JSON_THROW_ON_ERROR)));
};

Server::runAll();
```

HTTP keep-alive and `Connection: close` are handled by the HTTP protocol/transport layer. Application code does not need
to close every ordinary HTTP connection manually.

## Process control

Use the same entry file to control the master process:

```bash
php server.php start
php server.php start -d
php server.php status
php server.php status --json
php server.php connections
php server.php connections --json
php server.php reload
php server.php reload -g
php server.php upgrade
php server.php capabilities
php server.php capabilities --json
php server.php restart
php server.php restart -g
php server.php stop
php server.php stop -g
php server.php version
php server.php help
```

`reload` performs a rolling worker replacement: one worker is replaced and restored before the next one is touched.
`reload -g` drains connections through the graceful path. Because reload forks from the already loaded master image,
definitions already resident in master are inherited unchanged.

`upgrade` is the code-deployment operation on supported Unix runtimes. The master performs `pcntl_exec()` without
changing its PID, restores the startup lock/listening sockets through authenticated `SCM_RIGHTS` descriptor passing,
rereads the application bootstrap and then rolling-replaces old worker images. If listener topology changes, use
`restart -g`.

`capabilities [--json]` reports whether the host can hot-upgrade and which event-loop backends are actually available.
Explicitly selecting an unavailable backend fails fast instead of silently degrading.

Concurrent `start` commands are protected by a startup `flock`, preventing a second master from racing the PID file or
listener bind. External `SIGTERM` (the normal Docker/systemd/Kubernetes stop signal) uses graceful draining; `SIGINT`
remains the fast-stop signal.

## HTTP file serving

`Response::withFileForRequest()` understands common HTTP cache and range semantics:

```php
$connection->send(
    (new Response())->withFileForRequest($request, __DIR__ . '/public/archive.zip')
);
```

It supports `HEAD`, `ETag` / `If-None-Match`, `Last-Modified` / `If-Modified-Since`, `If-Match`, `If-Unmodified-Since`,
single byte ranges and `If-Range`. Large files are streamed with backpressure instead of being loaded wholly into
memory. Multipart byte ranges are deliberately not implemented yet.

HTTP request limits are configurable globally when an application needs stricter bounds:

```php
use localzet\Server\Protocols\Http;

Http::maxHeaderLength(16 * 1024);
Http::maxHeaderCount(100);
```

The parser also handles `Expect: 100-continue`, validates HTTP/1.1 `Host`, rejects ambiguous request framing and accepts
standards-compliant extension methods such as WebDAV verbs rather than using a fixed method whitelist.

## Gateway

The 6.4 line adds a deliberately small programmable gateway layer without coupling it to `Server.php`:

- `Upstream` / `UpstreamPool` with weighted round-robin, least-connections and random selection;
- passive quarantine and active TCP health checks;
- raw TCP proxying with backpressure and safe connect-time failover;
- streaming HTTP/1.x reverse proxying with host/path-prefix routing, optional path rewrite, `Forwarded`/`X-Forwarded-*`,
  upstream keep-alive and WebSocket Upgrade tunneling;
- retries only before an upstream connection is established, avoiding unsafe replay after bytes may have been consumed.

Gateway acceptance tests cover dead-first upstream failover, byte-exact L4 payloads, streaming request bodies, chunked
requests, keep-alive and WebSocket tunneling.

## License and provenance

Localzet Server is distributed under GNU AGPL-3.0-or-later.

Documentation: https://server.localzet.com

## Attribution

Copyright © 2016-2026 [Localzet Group](https://www.localzet.com/). All rights reserved. Original authorship and third-party licenses remain applicable. See [AUTHORS](.github/AUTHORS.md).
