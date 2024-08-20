<?php

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

use localzet\Server;
use localzet\Server\Events\Linux;
use localzet\Server\Events\Linux\Driver\EvDriver;
use localzet\Server\Events\Linux\Driver\EventDriver;
use localzet\Server\Events\Linux\Driver\UvDriver;
use localzet\Server\Events\Windows;
use localzet\ServerAbstract;

/**
 * Запускает сервер Localzet.
 *
 * @param string|null $name Название для серверных процессов
 * @param int|null $count Количество серверных процессов
 * @param string|null $listen Имя сокета
 * @param array|null $context Контекст сокета
 * @param string|null $user Unix пользователь (нужен root)
 * @param string|null $group Unix группа (нужен root)
 * @param bool|null $reloadable Перезагружаемый экземпляр?
 * @param bool|null $reusePort Повторно использовать порт?
 * @param string|null $protocol Протокол уровня приложения
 * @param string|null $transport Протокол транспортного уровня
 * @param Server|null $server Экземпляр сервера, или его наследника
 * @param string|null $handler [ServerAbstract](\localzet\ServerAbstract)
 * @param array|null $constructor
 * @param array|null $services Массив сервисов (только listen, context, handler, constructor)
 *
 * @return Server
 */
function localzet_start(
    // Свойства главного сервера
    ?string $name = null,
    ?int    $count = null,
    ?string $listen = null,
    ?array  $context = null,
    ?string $user = null,
    ?string $group = null,
    ?bool   $reloadable = null,
    ?bool   $reusePort = null,
    ?string $protocol = null,
    ?string $transport = null,
    ?Server $server = null,
    // Бизнес-исполнитель
    ?string $handler = null,
    ?array  $constructor = null,
    // Дополнительные сервера
    ?array  $services = null,
): Server
{
    $master = $server ?? new Server($listen ?? null, $context ?? []);
    $master->name = $name ?? $server->name;
    $master->count = $count ?? $server->count;
    $master->user = $user ?? $server->user;
    $master->group = $group ?? $server->group;
    $master->reloadable = $reloadable ?? $server->reloadable;
    $master->reusePort = $reusePort ?? $server->reusePort;
    $master->transport = $transport ?? $server->transport;
    $master->protocol = $protocol ?? $server->protocol;

    $onServerStart = null;
    if ($handler && class_exists($handler)) {
        $instance = new $handler(...array_values($constructor ?? []));
        localzet_bind($master, $instance);

        if (method_exists($instance, 'onServerStart')) {
            $onServerStart = [$instance, 'onServerStart'];
        }
    }

    $master->onServerStart = function ($master) use ($services, $onServerStart) {
        if ($onServerStart) $onServerStart($master);

        foreach ($services ?? [] as $service) {
            extract($service);

            $server ??= new Server($listen ?? null, $context ?? []);
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
        if (is_callable('shell_exec')) {
            if (strtolower(PHP_OS) === 'darwin') {
                $count = (int)shell_exec('sysctl -n machdep.cpu.core_count');
            } else {
                $count = (int)shell_exec('nproc');
            }
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
 * @param string $body Тело ответа.
 * @param string|null $reason Причина ответа.
 * @param array $headers Заголовки ответа.
 * @param string $version Версия HTTP.
 *
 * @return string Форматированный HTTP-ответ.
 */
function format_http_response(int $code, string $body = '', array $headers = [], string $reason = null, string $version = '1.1'): string
{
    $reason ??= Server\Protocols\Http\Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/$version $code $reason\r\n";

    $defaultHeaders = [
        'Server' => 'Localzet-Server',
        'Connection' => $headers['Connection'] ?? 'keep-alive',
        'Content-Type' => $headers['Content-Type'] ?? 'text/html;charset=utf-8',
    ];
    $headers = array_merge($headers, $defaultHeaders);

    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            if ($value) {
                $head .= "$name: $value\r\n";
            }
        }
    }

    if ($headers['Content-Type'] === 'text/event-stream') {
        return $head . $body;
    }

    $bodyLen = $body ? strlen($body) : null;

    if (empty($headers['Transfer-Encoding']) && $bodyLen) {
        $head .= "Content-Length: $bodyLen\r\n";
    }

    $head .= "\r\n";

    if ($version === '1.1'
        && !empty($headers['Transfer-Encoding'])
        && $headers['Transfer-Encoding'] === 'chunked') {
        return $bodyLen ? $head . dechex($bodyLen) . "\r\n$body\r\n" : $head;
    }

    return $head . $body;
}

/**
 * Форматирует WebSocket-ответ.
 *
 * @param int $code Код ответа.
 * @param array $headers Заголовки ответа.
 *
 * @return string Форматированный WebSocket-ответ.
 */
function format_websocket_response(int $code, array $headers = []): string
{
    $reason = Server\Protocols\Http\Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/1.1 $code $reason\r\n";

    $defaultHeaders = [
        'Server' => 'Localzet-Server',
        'Connection' => $headers['Connection'] ?? 'Upgrade',
        'Upgrade' => $headers['Upgrade'] ?? 'websocket',
        'Sec-WebSocket-Version' => $headers['Sec-WebSocket-Version'] ?? 13,
    ];
    $headers = array_merge($headers, $defaultHeaders);

    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            if ($value) {
                $head .= "$name: $value\r\n";
            }
        }
    }

    return $head;
}
