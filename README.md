# Localzet Server

High-performance event-driven server runtime for PHP with TCP, UDP, HTTP/1.1, WebSocket, timers, long-lived connections
and multi-process workers.

Localzet Server 7.0 freezes the first modernized runtime line: production supervisor, HTTP/1.x/WebSocket protocols and a
bounded L4/L7 gateway are treated as stable foundations for framework/adaptor work.

## Requirements

- PHP 8.1+
- Composer is required for package installation, but the runtime core has no mandatory third-party PHP package
  dependency
- Unix: `pcntl` and `posix` are recommended for multi-process mode
- Windows works in single-process mode

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
```

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
