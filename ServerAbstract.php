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

namespace localzet;

use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;

/** Удобная объектная оболочка над callback API Server. */
abstract class ServerAbstract
{
    public function onServerStart(Server &$server): void
    {
    }

    public function onServerStop(Server &$server): void
    {
    }

    public function onServerReload(Server &$server): void
    {
    }

    public function onServerExit(Server $server, int $signal, int $pid): void
    {
    }

    public function onMasterReload(): void
    {
    }

    public function onMasterStop(): void
    {
    }

    public function onConnect(ConnectionInterface &$connection): void
    {
    }

    public function onWebSocketConnect(TcpConnection &$connection, Request $request): ?Response
    {
        return null;
    }

    public function onWebSocketConnected(TcpConnection &$connection, mixed $request = null): void
    {
    }

    public function onWebSocketClose(TcpConnection &$connection, int $code, string $reason): void
    {
    }

    public function onWebSocketPing(TcpConnection &$connection, string $payload): void
    {
    }

    public function onWebSocketPong(TcpConnection &$connection, string $payload): void
    {
    }

    abstract public function onMessage(ConnectionInterface &$connection, mixed $request): void;

    public function onClose(ConnectionInterface &$connection): void
    {
    }

    public function onError(ConnectionInterface &$connection, int $code, string $reason): void
    {
    }

    public function onBufferFull(ConnectionInterface &$connection): void
    {
    }

    public function onBufferDrain(ConnectionInterface &$connection): void
    {
    }
}
