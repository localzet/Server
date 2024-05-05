<?php

use localzet\Server;

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
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

/**
 * @param string $name Название для серверных процессов
 * @param int $count Количество серверных процессов
 *
 * @param string|null $listen Имя сокета
 * @param array $context Контекст сокета
 *
 * @param string $user Unix пользователь (нужен root)
 * @param string $group Unix группа (нужен root)
 *
 * @param bool $reloadable Перезагружаемый экземпляр?
 * @param bool $reusePort Повторно использовать порт?
 *
 * @param string|null $protocol Протокол уровня приложения
 * @param string $transport Протокол транспортного уровня
 *
 * @param string|null $handler
 * @param array $constructor
 *
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
                echo "process error: class {$service['handler']} not exists\r\n";
                continue;
            }

            $listen = new Server($service['listen'] ?? null, $service['context'] ?? []);
            if (isset($service['listen'])) {
                echo "listen: {$service['listen']}\n";
            }

            if (!class_exists($service['handler'])) {
                throw new Exception("Класс '{$service['handler']}' не найден");
            }
            $instance = new $service['handler'](...array_values($service['constructor']));
            localzet_bind($listen, $instance);
            $listen->listen();
        }

        if ($handler) {
            if (!class_exists($handler)) {
                echo "process error: class $handler not exists\r\n";
                return;
            }

            if (!class_exists($handler)) {
                throw new Exception("Класс '$handler' не найден");
            }
            $instance = new $handler(...array_values($constructor ?? []));
            localzet_bind($server, $instance);
        }
    };

    return $server;
}

function localzet_bind(Server $server, $class): void
{
    $callbackMap = [
        'onConnect',
        'onMessage',
        'onClose',
        'onError',
        'onBufferFull',
        'onBufferDrain',
        'onServerStop',
        'onWebSocketConnect',
        'onServerReload'
    ];
    foreach ($callbackMap as $name) {
        if (method_exists($class, $name)) {
            $server->$name = [$class, $name];
        }
    }
    if (method_exists($class, 'onServerStart')) {
        call_user_func([$class, 'onServerStart'], $server);
    }
}

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

function is_unix(): bool
{
    return DIRECTORY_SEPARATOR === '/';
}