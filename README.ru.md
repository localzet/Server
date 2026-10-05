# Localzet Server

[English documentation](README.md)

Событийный серверный runtime на PHP: TCP, UDP, HTTP/1.1, WebSocket, таймеры, долгоживущие соединения и многопроцессные воркеры.

Эта ветка разработки относится к линии 7.0: управление процессами, HTTP/1.x/WebSocket и ограниченный шлюз L4/L7. Опубликованные пакеты 4.x, используемые Cluster и другими библиотеками, — отдельная линия совместимости. Установка опубликованного пакета не означает установку этой ветки.

## Требования

- PHP 8.1 и новее.
- Composer нужен для установки; ядру runtime не нужны обязательные сторонние PHP-пакеты.
- На Unix для многопроцессного режима рекомендуются `pcntl` и `posix`.
- На Windows поддерживается однопроцессный режим.

Дополнительные интеграции: `revolt/event-loop`, `localzet/events`, `sockets`, `event`, `ev`, `swoole`, `swow`, Redis и MongoDB. Они добавляют backend событийного цикла, события жизненного цикла, горячую замену и хранилища сессий. Переносимый `Select` работает без Revolt.

## Установка

```sh
composer require localzet/server
```

Для разработки этой ветки в её Git-копии:

```sh
composer install
composer check
composer test:integration
composer release:audit
composer analyze
```

`composer analyze` выполняет базовую проверку ядра PHPStan уровня 0. Адаптеры Swow и MongoDB исключены из неё: их расширение/пакет не установлены в окружении ядра. Более строгая проверка типов и отдельная проверка этих адаптеров остаются следующими задачами.

## Пример HTTP

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

HTTP keep-alive и `Connection: close` обрабатываются протоколом и транспортом. Приложению не нужно закрывать каждое обычное HTTP-соединение вручную.

## Управление процессами

Используйте тот же входной файл для управления master-процессом:

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

`reload` последовательно заменяет воркеры: следующий заменяется после восстановления предыдущего. `reload -g` ожидает завершения соединений. Воркеры наследуют уже загруженные master-процессом определения, поэтому reload не перечитывает весь код master.

`upgrade` обновляет код на поддерживаемых Unix-системах: master выполняет `pcntl_exec()` с сохранением PID, восстанавливает lock и слушающие сокеты через аутентифицированную передачу дескрипторов `SCM_RIGHTS`, перечитывает bootstrap приложения и последовательно заменяет старые воркеры. При изменении набора слушающих сокетов используйте `restart -g`.

`capabilities [--json]` показывает возможность горячей замены и доступные backend событийного цикла. Явно выбранный недоступный backend вызывает ошибку.

Параллельные `start` защищены стартовым `flock`. Внешний `SIGTERM`, используемый Docker/systemd/Kubernetes, включает корректное завершение соединений; `SIGINT` — быструю остановку.

## Отдача файлов по HTTP

`Response::withFileForRequest()` поддерживает HTTP-кеширование и диапазоны:

```php
$connection->send(
    (new Response())->withFileForRequest($request, __DIR__ . '/public/archive.zip')
);
```

Поддерживаются `HEAD`, `ETag` / `If-None-Match`, `Last-Modified` / `If-Modified-Since`, `If-Match`, `If-Unmodified-Since`, один байтовый диапазон и `If-Range`. Большие файлы передаются потоком с backpressure. Multipart-диапазоны пока не реализованы.

Глобальные ограничения HTTP-запросов:

```php
use localzet\Server\Protocols\Http;

Http::maxHeaderLength(16 * 1024);
Http::maxHeaderCount(100);
```

Парсер обрабатывает `Expect: 100-continue`, проверяет HTTP/1.1 `Host`, отклоняет неоднозначный framing и принимает корректные дополнительные методы, включая WebDAV.

## Gateway

Начиная с линии 6.4 отдельный программируемый шлюз предоставляет:

- `Upstream` / `UpstreamPool`: взвешенный round-robin, least-connections и случайный выбор.
- Пассивный карантин и активные TCP-проверки состояния.
- TCP-проксирование с backpressure и переключением при ошибке подключения.
- Потоковое HTTP/1.x reverse proxy, маршрутизацию по host и префиксу пути, переписывание пути, `Forwarded`/`X-Forwarded-*`, upstream keep-alive и WebSocket Upgrade.
- Повторные попытки до установления upstream-соединения; данные, которые уже мог принять backend, автоматически не повторяются.

Интеграционные тесты проверяют переключение с недоступного первого backend, побайтовую передачу L4, потоковые тела, chunked-запросы, keep-alive и туннелирование WebSocket.

## Автор, лицензия и документы

GNU AGPL v3 или новее, см. [LICENSE](LICENSE). Документация: https://server.localzet.com.

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com. Copyright © 2026 Localzet Group. Сохраняются исходные уведомления авторов и лицензии сторонних компонентов; см. [.github/AUTHORS.md](.github/AUTHORS.md). Уязвимости сообщайте согласно [.github/SECURITY_ru.md](.github/SECURITY_ru.md).
