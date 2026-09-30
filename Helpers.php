<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2026 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

use localzet\Server;
use localzet\ServerAbstract;
use localzet\Server\Events\Fiber as FiberEventLoop;
use localzet\Server\Events\Windows;
use localzet\Server\Protocols\Http\Response;

/**
 * Создаёт основной Localzet Server и, при необходимости, дополнительные
 * endpoints из конфигурации.
 *
 * В отличие от старой реализации дополнительные services регистрируются до
 * runAll(). Поэтому master знает о них заранее и корректно форкает заданное
 * число процессов, вместо создания нового listening socket из onServerStart().
 *
 * @param null|string|array $name Имя процесса либо полный массив конфигурации.
 * @param null|int $count Количество процессов.
 * @param null|string $listen URI сокета, например http://0.0.0.0:8080.
 * @param null|array $context stream context.
 * @param null|string $user Unix user после fork.
 * @param null|string $group Unix group после fork.
 * @param null|bool $reloadable Разрешить reload.
 * @param null|bool $reusePort Использовать SO_REUSEPORT, если поддерживается.
 * @param null|string $protocol Класс application protocol.
 * @param null|string $transport tcp/udp/unix/ssl.
 * @param null|class-string<Server> $server Класс Server или наследника.
 * @param null|class-string $handler Объектный обработчик callback'ов.
 * @param null|array $constructor Аргументы конструктора handler.
 * @param null|array $services Дополнительные endpoints.
 */
function localzet_start(
    // Свойства главного сервера
    null|string|array $name = null,
    ?int              $count = null,
    ?string           $listen = null,
    ?array            $context = null,
    ?string           $user = null,
    ?string           $group = null,
    ?bool             $reloadable = null,
    ?bool             $reusePort = null,
    ?string           $protocol = null,
    ?string           $transport = null,
    ?string           $server = null,
    ?string           $handler = null,
    ?array            $constructor = null,
    ?array            $services = null,
): Server
{
    if (is_array($name)) {
        // Безопасное извлечение параметров из массива
        $config = $name;
        $name = $config['name'] ?? null;
        $count = $config['count'] ?? $count;
        $listen = $config['listen'] ?? $listen;
        $context = $config['context'] ?? $context;
        $user = $config['user'] ?? $user;
        $group = $config['group'] ?? $group;
        $reloadable = $config['reloadable'] ?? $reloadable;
        $reusePort = $config['reusePort'] ?? $reusePort;
        $protocol = $config['protocol'] ?? $protocol;
        $transport = $config['transport'] ?? $transport;
        $server = $config['server'] ?? $server;
        $handler = $config['handler'] ?? $handler;
        $constructor = $config['constructor'] ?? $constructor;
        $services = $config['services'] ?? $services;
    }

    $build = static function (array $config): Server {
        $serverClass = $config['server'] ?? Server::class;
        if (!is_a($serverClass, Server::class, true)) {
            throw new InvalidArgumentException("Server class {$serverClass} must extend " . Server::class);
        }

        /** @var Server $instance */
        $instance = new $serverClass($config['listen'] ?? null, $config['context'] ?? []);
        $instance->name = (string)($config['name'] ?? $instance->name);
        $instance->count = max(1, (int)($config['count'] ?? $instance->count));
        $instance->user = (string)($config['user'] ?? $instance->user);
        $instance->group = (string)($config['group'] ?? $instance->group);
        $instance->reloadable = (bool)($config['reloadable'] ?? $instance->reloadable);
        $instance->reusePort = (bool)($config['reusePort'] ?? $instance->reusePort);

        if (array_key_exists('transport', $config) && $config['transport'] !== null) {
            $instance->transport = (string)$config['transport'];
        }
        if (array_key_exists('protocol', $config) && $config['protocol'] !== null) {
            $instance->protocol = (string)$config['protocol'];
        }
        if (array_key_exists('eventLoop', $config) && $config['eventLoop'] !== null) {
            $instance->eventLoop = (string)$config['eventLoop'];
        }

        $handlerClass = $config['handler'] ?? null;
        if (is_string($handlerClass) && $handlerClass !== '') {
            if (!class_exists($handlerClass)) {
                throw new InvalidArgumentException("Handler class {$handlerClass} does not exist.");
            }
            $handlerInstance = new $handlerClass(...array_values($config['constructor'] ?? []));
            localzet_bind($instance, $handlerInstance);
        }

        return $instance;
    };

    $master = $build([
        'name' => $name,
        'count' => $count,
        'listen' => $listen,
        'context' => $context ?? [],
        'user' => $user,
        'group' => $group,
        'reloadable' => $reloadable,
        'reusePort' => $reusePort,
        'protocol' => $protocol,
        'transport' => $transport,
        'server' => $server ?? Server::class,
        'handler' => $handler,
        'constructor' => $constructor ?? [],
    ]);

    foreach ($services ?? [] as $service) {
        if (!is_array($service)) {
            throw new InvalidArgumentException('Each Localzet service must be an array.');
        }
        $build($service);
    }

    return $master;
}

/**
 * Привязывает методы объекта-обработчика к callback API Server.
 * Не требует наследования от ServerAbstract, поэтому остаётся удобным для DI.
 */
function localzet_bind(Server &$server, mixed $handler): void
{
    foreach ([
                 'onServerStart', 'onServerStop', 'onServerReload',
                 'onConnect',
                 'onWebSocketConnect', 'onWebSocketConnected', 'onWebSocketClose',
                 'onWebSocketPing', 'onWebSocketPong',
                 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain',
             ] as $name) {
        if (is_object($handler) && method_exists($handler, $name)) {
            $server->{$name} = [$handler, $name];
        }
    }

    foreach (['onServerExit', 'onMasterReload', 'onMasterStop'] as $name) {
        if (is_object($handler) && method_exists($handler, $name)) {
            Server::${$name} = [$handler, $name];
        }
    }
}

/**
 * Возвращает количество процессоров.
 *
 * @return int Количество процессоров.
 */
if (!function_exists('cpu_count')) {
    function cpu_count(): int
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = getenv('NUMBER_OF_PROCESSORS');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = trim((string)@shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
        } else {
            $candidates[] = trim((string)@shell_exec('nproc 2>/dev/null'));
            $candidates[] = trim((string)@shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null'));
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int)$candidate > 0) {
                return (int)$candidate;
            }
        }
        return 1;
    }
}

/** Unix-like ОС. */
if (!function_exists('is_unix')) {
    function is_unix(): bool
    {
        return DIRECTORY_SEPARATOR === '/';
    }
}

/** Человекочитаемое имя выбранного event-loop backend. */
if (!function_exists('get_event_loop_name')) {
    function get_event_loop_name(): string
    {
        if (Server::$globalEvent !== null) {
            return Server::$globalEvent::class;
        }
        if (Server::$eventLoopClass !== null) {
            return Server::$eventLoopClass;
        }
        if (!is_unix()) {
            return Windows::class;
        }
        return class_exists(\Revolt\EventLoop::class)
            ? FiberEventLoop::class . ' (Revolt)'
            : localzet\Server\Events\Select::class;
    }
}

/** Создаёт Response. */
if (!function_exists('response')) {
    function response(string $body = '', int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, $body);
    }
}

/** Создаёт JSON Response. */
if (!function_exists('json')) {
    function json(
        mixed $data,
        int   $status = 200,
        array $headers = [],
        int   $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ): Response
    {
        $headers['Content-Type'] ??= 'application/json; charset=utf-8';
        return new Response($status, $headers, json_encode($data, $options | JSON_THROW_ON_ERROR));
    }
}

/** Создаёт redirect Response. */
if (!function_exists('redirect')) {
    function redirect(string $location, int $status = 302, array $headers = []): Response
    {
        $headers['Location'] = str_replace(["\r", "\n"], '', $location);
        return new Response($status, $headers);
    }
}

/**
 * Форматирует raw HTTP response для legacy-кода.
 * Для chunked response добавляется обязательный terminating zero chunk.
 *
 * @param int $code Код ответа.
 * @param string|null $body Тело ответа.
 * @param array<string, string|string[]> $headers Заголовки ответа.
 * @param string|null $reason Причина ответа.
 * @param string $version Версия HTTP.
 *
 * @return string Форматированный HTTP-ответ.
 */
if (!function_exists('format_http_response')) {
    function format_http_response(
        int     $code,
        ?string $body = '',
        array   $headers = [],
        ?string $reason = null,
        string  $version = '1.1',
    ): string
    {
        $body ??= '';
        $headers = array_change_key_case($headers, CASE_LOWER);
        $isChunked = strtolower((string)($headers['transfer-encoding'] ?? '')) === 'chunked';

        if (!$isChunked) {
            return (string)(new Response($code, $headers, $body))
                ->withProtocolVersion($version)
                ->withStatus($code, $reason);
        }

        $reason = str_replace(["\r", "\n"], '', $reason ?? (Response::PHRASES[$code] ?? 'Unknown Status'));
        $version = preg_replace('/[^0-9.]/', '', $version) ?: '1.1';
        $head = "HTTP/{$version} {$code} {$reason}\r\n";
        $headers['server'] ??= 'Localzet-Server';
        $headers['connection'] ??= 'keep-alive';
        $headers['content-type'] ??= 'text/html; charset=utf-8';
        unset($headers['content-length']);

        foreach ($headers as $name => $values) {
            $safeName = preg_replace('/[^!#$%&\'*+.^_`|~0-9A-Za-z-]/', '', (string)$name);
            if ($safeName === '') {
                continue;
            }
            foreach ((array)$values as $value) {
                $safeValue = str_replace(["\r", "\n"], '', (string)$value);
                $head .= $safeName . ': ' . $safeValue . "\r\n";
            }
        }

        return $head . "\r\n"
            . dechex(strlen($body)) . "\r\n" . $body . "\r\n"
            . "0\r\n\r\n";
    }
}
