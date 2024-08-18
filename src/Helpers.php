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
 * @param string $name Название для серверных процессов
 * @param int $count Количество серверных процессов
 * @param string|null $listen Имя сокета
 * @param array $context Контекст сокета
 * @param string $user Unix пользователь (нужен root)
 * @param string $group Unix группа (нужен root)
 * @param bool $reloadable Перезагружаемый экземпляр?
 * @param bool $reusePort Повторно использовать порт?
 * @param string|null $protocol Протокол уровня приложения
 * @param string $transport Протокол транспортного уровня
 * @param string|null $handler
 * @param array $constructor
 * @param callable|null $onServerStart Выполняется при запуске серверных процессов
 * @param array $services Массив сервисов (только listen, context, handler, constructor)
 *
 * @return Server
 */
function localzet_start(
    string    $name = 'none',
    int       $count = 1,
    ?string   $listen = null,
    array     $context = [],
    string    $user = '',
    string    $group = '',
    bool      $reloadable = true,
    bool      $reusePort = false,
    ?string   $protocol = null,
    string    $transport = 'tcp',
    ?string   $handler = null,
    array     $constructor = [],
    ?callable $onServerStart = null,
    array     $services = [],
): Server
{
    $server = new Server($listen, $context);
    $server->name = $name;
    $server->count = $count;
    $server->user = $user;
    $server->group = $group;
    $server->reloadable = $reloadable;
    $server->reusePort = $reusePort;
    $server->transport = $transport;
    $server->protocol = $protocol;

    $server->onServerStart = function ($server) use ($services, $handler, $constructor, $onServerStart) {
        if ($onServerStart) $onServerStart($server);

        foreach ($services as $service) {
            if (!class_exists($service['handler'])) {
                Server::log("Класс '{$service['handler']}' не найден");
                continue;
            }

            $listen = new Server($service['listen'] ?? null, $service['context'] ?? []);
            if (isset($service['listen'])) {
                Server::log("Прослушиваем: {$service['listen']}\n");
            }

            $instance = new $service['handler'](...array_values($service['constructor']));
            localzet_bind($listen, $instance);
            $listen->listen();
        }

        if ($handler) {
            if (!class_exists($handler)) {
                throw new Exception("Класс '$handler' не найден");
            }
            $instance = new $handler(...array_values($constructor ?? []));
            localzet_bind($server, $instance);
        }
    };

    return $server;
}

/**
 * Привязывает методы класса к серверу.
 *
 * @param Server $server Экземпляр сервера.
 * @param ServerAbstract|mixed $class Класс, методы которого будут привязаны.
 *
 * @throws ReflectionException
 */
function localzet_bind(Server &$server, mixed $class): void
{
    $callbackMap = [
        'onServerStop',
        'onServerReload',
        'onServerExit',
        'onMasterReload',
        'onMasterStop',
        'onConnect',
        'onWebSocketConnect',
        'onMessage',
        'onClose',
        'onError',
        'onBufferFull',
        'onBufferDrain',
    ];

    foreach ($callbackMap as $name) {
        if (method_exists($class, $name)) {
            if ($class instanceof ServerAbstract && is_abstract_method($class::class, $name)) continue;
            $server->$name = [$class, $name];
        }
    }

    if (method_exists($class, 'onServerStart') && !is_abstract_method($class, 'onServerStart')) {
        call_user_func([$class, 'onServerStart'], $server);
    }
}

/**
 * Проверяет, является ли метод абстрактным.
 *
 * @param object|string $class Класс, содержащий метод.
 * @param null|string $method Имя метода.
 *
 * @return bool Возвращает true, если метод абстрактный, иначе false.
 *
 * @throws ReflectionException
 */
function is_abstract_method(object|string $class, ?string $method): bool
{
    $reflection = new ReflectionMethod($class, $method);
    return $reflection->isAbstract();
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
 * @param string|null $body Тело ответа.
 * @param string|null $reason Причина ответа.
 * @param array $headers Заголовки ответа.
 * @param string $version Версия HTTP.
 *
 * @return string Форматированный HTTP-ответ.
 */
function format_http_response(int $code, ?string $body = '', string $reason = null, array $headers = [], string $version = '1.1'): string
{
    $reason ??= Server\Protocols\Http\Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/$version $code $reason\r\n";

    $defaultHeaders = [
        'Connection' => 'keep-alive',
        'Content-Type' => 'text/html;charset=utf-8',
    ];
    $headers = array_merge($defaultHeaders, $headers, ['Server' => 'Localzet-Server']);

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
 * @param string $version Версия HTTP.
 *
 * @return string Форматированный WebSocket-ответ.
 */
function format_websocket_response(int $code, array $headers = [], string $version = '1.1'): string
{
    $reason ??= Server\Protocols\Http\Response::PHRASES[$code] ?? 'Unknown Status';
    $head = "HTTP/$version $code $reason\r\n";

    $defaultHeaders = [
        'Server' => 'Localzet-Server',
        'Upgrade' => 'websocket',
        'Sec-WebSocket-Version' => 13,
        'Connection' => 'Upgrade',
    ];
    $headers = array_merge($defaultHeaders, $headers);

    foreach ($headers as $name => $values) {
        foreach ((array)$values as $value) {
            if ($value) {
                $head .= "$name: $value\r\n";
            }
        }
    }

    return $head;
}
