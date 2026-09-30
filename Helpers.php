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

use localzet\Server\Events\Linux;
use localzet\Server\Events\Linux\Driver\EvDriver;
use localzet\Server\Events\Linux\Driver\EventDriver;
use localzet\Server\Events\Linux\Driver\UvDriver;
use localzet\Server\Events\Windows;
use localzet\Server\Protocols\Http\Response;
use localzet\Server;
use localzet\ServerAbstract;

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
 */
function localzet_start(
    // Свойства главного сервера
    null|string|array $name = null,
    null|int          $count = null,
    null|string       $listen = null,
    null|array        $context = null,
    null|string       $user = null,
    null|string       $group = null,
    null|bool         $reloadable = null,
    null|bool         $reusePort = null,
    null|string       $protocol = null,
    null|string       $transport = null,
    null|string       $server = null,
    // Бизнес-исполнитель
    null|string       $handler = null,
    null|array        $constructor = null,
    // Дополнительные сервера
    null|array        $services = null,
): Server {
    if (is_array($name)) {
        // Безопасное извлечение параметров из массива
        $config = $name;
        $name = $config['name'] ?? $name;
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

    $server ??= Server::class;
    $master = new $server($listen ?? null, $context ?? []);
    $master->name = $name ?? $master->name;
    $master->count = $count ?? $master->count;
    $master->user = $user ?? $master->user;
    $master->group = $group ?? $master->group;
    $master->reloadable = $reloadable ?? $master->reloadable;
    $master->reusePort = $reusePort ?? $master->reusePort;
    $master->transport = $transport ?? $master->transport;
    $master->protocol = $protocol ?? $master->protocol;

    $onServerStart = null;
    if ($handler && class_exists($handler)) {
        $instance = new $handler(...array_values($constructor ?? []));
        localzet_bind($master, $instance);

        if (method_exists($instance, 'onServerStart')) {
            $onServerStart = [$instance, 'onServerStart'];
        }
    }

    $master->onServerStart = function ($master) use ($services, $onServerStart): void {
        if ($onServerStart) {
            $onServerStart($master);
        }

        foreach ($services ?? [] as $service) {
            // Безопасное извлечение параметров сервиса
            $serviceName = $service['name'] ?? 'none';
            $serviceCount = $service['count'] ?? 1;
            $serviceListen = $service['listen'] ?? null;
            $serviceContext = $service['context'] ?? [];
            $serviceUser = $service['user'] ?? '';
            $serviceGroup = $service['group'] ?? '';
            $serviceReloadable = $service['reloadable'] ?? true;
            $serviceReusePort = $service['reusePort'] ?? false;
            $serviceTransport = $service['transport'] ?? 'tcp';
            $serviceProtocol = $service['protocol'] ?? null;
            $serviceServer = $service['server'] ?? Server::class;
            $serviceHandler = $service['handler'] ?? null;
            $serviceConstructor = $service['constructor'] ?? null;

            $serviceServerInstance = new $serviceServer($serviceListen, $serviceContext);
            $serviceServerInstance->name = $serviceName;
            $serviceServerInstance->count = $serviceCount;
            $serviceServerInstance->user = $serviceUser;
            $serviceServerInstance->group = $serviceGroup;
            $serviceServerInstance->reloadable = $serviceReloadable;
            $serviceServerInstance->reusePort = $serviceReusePort;
            $serviceServerInstance->transport = $serviceTransport;
            $serviceServerInstance->protocol = $serviceProtocol;

            if ($serviceHandler && class_exists($serviceHandler)) {
                $instance = new $serviceHandler(...array_values($serviceConstructor ?? []));
                localzet_bind($serviceServerInstance, $instance);
            }

            $serviceServerInstance->listen();
        }
    };

    return $master;
}

/**
 * Привязывает методы объекта-обработчика к callback API Server.
 * Не требует наследования от ServerAbstract, поэтому остаётся удобным для DI.
 *
 * @param Server $server Экземпляр сервера.
 * @param ServerAbstract|mixed $class Класс, методы которого будут привязаны.
 */
function localzet_bind(Server &$server, mixed $class): void
{
    foreach (['onServerStart', 'onServerStop', 'onServerReload',
                 'onConnect', 'onWebSocketConnect',
                 'onMessage', 'onClose', 'onError',
                 'onBufferFull', 'onBufferDrain'] as $name) {
        if (method_exists($class, $name)) {
            $server->$name = [$class, $name];
        }
    }

    foreach (['onServerExit', 'onMasterReload', 'onMasterStop'] as $name) {
        if (method_exists($class, $name)) {
            $server::$$name = [$class, $name];
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
        if (!is_unix()) {
            return 1;
        }

        $count = 4;
        if (strtolower(PHP_OS) === 'darwin') {
            $count = (int)shell_exec('sysctl -n machdep.cpu.core_count');
        } else {
            $count = (int)shell_exec('nproc');
        }

        return $count > 0 ? $count : 4;
    }
}

/**
 * Unix-like ОС.
 *
 * @return bool Возвращает true, если операционная система Unix-подобная, иначе false.
 */
function is_unix(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}

/**
 * Человекочитаемое имя выбранного event-loop backend.
 *
 * @return string Имя цикла событий.
 */
function get_event_loop_name(): string
{
    if (!is_unix()) {
        return Windows::class;
    }

    if (UvDriver::isSupported()) {
        return Linux::class . ' (+Uv)';
    }

    if (EvDriver::isSupported()) {
        return Linux::class . ' (+Ev)';
    }

    if (EventDriver::isSupported()) {
        return Linux::class . ' (+Event)';
    }

    return Linux::class;
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
function format_http_response(int $code, ?string $body = '', array $headers = [], ?string $reason = null, string $version = '1.1'): string
{
    $reason ??= Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/$version $code $reason\r\n";

    $headers = array_change_key_case($headers, CASE_LOWER);
    $defaultHeaders = [
        'server' => 'Localzet-Server',
        'connection' => $headers['connection'] ?? 'keep-alive',
        'content-type' => $headers['content-type'] ?? 'text/html;charset=utf-8',
    ];

    $headers = array_merge($defaultHeaders, $headers);
    $bodyLen = ($body !== null && $body !== '') ? strlen($body) : null;

    if (empty($headers['transfer-encoding']) && $bodyLen !== null) {
        $headers['content-length'] = (string)$bodyLen;
    }

    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            if ($value !== null && $value !== '') {
                $head .= "$name: $value\r\n";
            }
        }
    }

    $head .= "\r\n";

    if ($version === '1.1'
        && !empty($headers['transfer-encoding'])
        && $headers['transfer-encoding'] === 'chunked'
        && $bodyLen !== null) {
        return $head . dechex($bodyLen) . "\r\n$body\r\n";
    }

    return $head . ($body ?? '');
}
