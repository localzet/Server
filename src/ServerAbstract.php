<?php
/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
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

namespace localzet;

use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;

/**
 * Абстрактный класс ServerAbstract, определяющий основные методы сервера.
 */
abstract class ServerAbstract
{
    /**
     * Метод, вызываемый при старте сервера.
     *
     * @param Server $server Экземпляр сервера.
     */
    public function onServerStart(Server &$server): void {}

    /**
     * Метод, вызываемый при остановке сервера.
     *
     * @param Server $server Экземпляр сервера.
     */
    public function onServerStop(Server &$server): void {}

    /**
     * Метод, вызываемый при перезагрузке сервера.
     *
     * @param Server $server Экземпляр сервера.
     */
    public function onServerReload(Server &$server): void {}

    /**
     * Метод, вызываемый при выходе сервера.
     *
     * @param Server $server Экземпляр сервера.
     * @param int $signal Сигнал выхода.
     * @param int $pid PID процесса.
     */
    public function onServerExit(Server $server, int $signal, int $pid): void {}

    /**
     * Метод, вызываемый при перезагрузке мастера.
     */
    public function onMasterReload(): void {}

    /**
     * Метод, вызываемый при остановке мастера.
     */
    public function onMasterStop(): void {}

    /**
     * Метод, вызываемый при подключении.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     */
    public function onConnect(ConnectionInterface &$connection): void {}

    /**
     * Метод, вызываемый при подключении WebSocket.
     *
     * @param TcpConnection $connection TCP соединение.
     * @param Request $request HTTP запрос.
     */
    public function onWebSocketConnect(TcpConnection &$connection, Request $request): void {}

    /**
     * Метод, вызываемый при получении сообщения.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     * @param mixed $request Запрос.
     */
    public abstract function onMessage(ConnectionInterface &$connection, mixed $request): void;

    /**
     * Метод, вызываемый при закрытии соединения.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     */
    public function onClose(ConnectionInterface &$connection): void {}

    /**
     * Метод, вызываемый при ошибке.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     * @param int $code Код ошибки.
     * @param string $reason Причина ошибки.
     */
    public function onError(ConnectionInterface &$connection, int $code, string $reason): void {}

    /**
     * Метод, вызываемый при заполнении буфера.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     */
    public function onBufferFull(ConnectionInterface &$connection): void {}

    /**
     * Метод, вызываемый при освобождении буфера.
     *
     * @param ConnectionInterface $connection Интерфейс соединения.
     */
    public function onBufferDrain(ConnectionInterface &$connection): void {}
}
