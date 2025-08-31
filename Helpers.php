<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2025 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
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
 * Запускает сервер Localzet.
 *
 * @param null|string|array $name Название для серверных процессов
 * @param null|int $count Количество серверных процессов
 * @param null|string $listen Имя сокета
 * @param null|array $context Контекст сокета
 * @param null|string $user Unix пользователь (нужен root)
 * @param null|string $group Unix группа (нужен root)
 * @param null|bool $reloadable Перезагружаемый экземпляр?
 * @param null|bool $reusePort Повторно использовать порт?
 * @param null|string $protocol Протокол уровня приложения
 * @param null|string $transport Протокол транспортного уровня
 * @param null|class-string $server Экземпляр сервера, или его наследника
 * @param null|string $handler [ServerAbstract](\localzet\ServerAbstract)
 * @param null|array $services Массив сервисов (только listen, context, handler, constructor)
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
        extract($name);
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
            extract($service);

            $server ??= Server::class;
            $server = new $server($listen ?? null, $context ?? []);
            $server->name = $name ?? 'none';
            $server->count = $count ?? 1;
            $server->user = $user ?? '';
            $server->group = $group ?? '';
            $server->reloadable = $reloadable ?? true;
            $server->reusePort = $reusePort ?? false;
            $server->transport = $transport ?? 'tcp';
            $server->protocol = $protocol ?? null;

            if ($handler && class_exists($handler)) {
                $instance = new $handler(...array_values($constructor ?? []));
                localzet_bind($server, $instance);
            }

            $server->listen();
        }
    };

    return $master;
}

/**
 * Привязывает методы класса к серверу.
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
 * Проверяет, является ли операционная система Unix-подобной.
 *
 * @return bool Возвращает true, если операционная система Unix-подобная, иначе false.
 */
function is_unix(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}

/**
 * Возвращает имя используемого цикла событий.
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
 * Форматирует HTTP-ответ.
 *
 * @param int $code Код ответа.
 * @param string|null $body Тело ответа.
 * @param string|null $reason Причина ответа.
 * @param array $headers Заголовки ответа.
 * @param string $version Версия HTTP.
 *
 * @return string Форматированный HTTP-ответ.
 */
function format_http_response(int $code, ?string $body = '', array $headers = [], string $reason = null, string $version = '1.1'): string
{
    $reason ??= Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/$version $code $reason\r\n";

    $headers = array_change_key_case($headers);
    $defaultHeaders = [
        'server' => 'Localzet-Server',
        'connection' => $headers['connection'] ?? 'keep-alive',
        'content-type' => $headers['content-type'] ?? 'text/html;charset=utf-8',
    ];

    $headers = array_merge($headers, $defaultHeaders);
    $bodyLen = empty($body) ? null : strlen($body);

    if (empty($headers['transfer-encoding']) && $bodyLen) {
        $headers['content-length'] = $bodyLen;
    }

    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            if ($value) {
                $head .= "$name: $value\r\n";
            }
        }
    }

    $head .= "\r\n";

    if ($version === '1.1'
        && !empty($headers['transfer-encoding'])
        && $headers['transfer-encoding'] === 'chunked') {
        return $bodyLen ? $head . dechex($bodyLen) . "\r\n$body\r\n" : $head;
    }

    return $head . $body;
}
