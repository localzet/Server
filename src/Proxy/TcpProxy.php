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

namespace localzet\Server\Proxy;

use localzet\Server;
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\TcpConnection;
use Throwable;

/**
 * Универсальный L4 TCP proxy с backpressure и connect-time failover.
 *
 * Важное ограничение failover: следующий upstream выбирается только пока
 * исходящее соединение ещё не было установлено. После передачи данных retry
 * опасен — backend мог уже применить часть операции.
 */
final class TcpProxy
{
    public function __construct(
        private readonly UpstreamPool $pool,
        private readonly float        $connectTimeout = 2.0,
    )
    {
    }

    /**
     * Подключает proxy к raw TCP Server instance.
     *
     * Server должен работать без application protocol: proxy передаёт байты
     * как есть и не должен дважды кодировать/декодировать payload.
     */
    public function attach(Server $server): void
    {
        if ($server->protocol !== null) {
            throw new \LogicException('TcpProxy requires a raw TCP server without application protocol.');
        }

        $userOnConnect = $server->onConnect;
        $userOnClose = $server->onClose;
        $server->onConnect = function (TcpConnection $client) use ($userOnConnect, $userOnClose): void {
            if ($userOnConnect !== null) {
                $userOnConnect($client);
            }

            // Proxy cleanup цепляется один раз на client-side connection и не
            // должен исчезать после успешного failover или pipe setup.
            $client->onClose = function (TcpConnection $connection) use ($userOnClose): void {
                $backend = $connection->context->tcpProxyBackend ?? null;
                $release = $connection->context->tcpProxyRelease ?? null;
                unset($connection->context->tcpProxyBackend, $connection->context->tcpProxyRelease);
                if (is_callable($release)) {
                    $release();
                }
                if ($backend instanceof AsyncTcpConnection) {
                    $backend->close();
                }
                if ($userOnClose !== null) {
                    $userOnClose($connection);
                }
            };
            $this->connectUpstream($client);
        };
    }

    /** @param array<int, true> $excluded */
    private function connectUpstream(TcpConnection $client, array $excluded = []): void
    {
        if ($client->getStatus() === TcpConnection::STATUS_CLOSED) {
            return;
        }

        try {
            $upstream = $this->pool->select($excluded);
        } catch (Throwable $e) {
            $client->close();
            return;
        }

        $excluded[spl_object_id($upstream)] = true;
        $backend = new AsyncTcpConnection($upstream->address, [], $client->getEventLoop());
        $backend->setConnectTimeout($this->connectTimeout);
        $client->context->tcpProxyBackend = $backend;
        $upstream->activeConnections++;
        $released = false;

        $release = static function () use ($upstream, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $upstream->activeConnections = max(0, $upstream->activeConnections - 1);
        };
        $client->context->tcpProxyRelease = $release;

        // Не читаем client payload до выбора рабочего upstream: благодаря этому
        // failover не требует временно буферизовать произвольный объём данных.
        $client->pauseRecv();

        $backend->onConnect = function (AsyncTcpConnection $backend) use ($client, $upstream): void {
            if (($client->context->tcpProxyBackend ?? null) !== $backend) {
                $backend->close();
                return;
            }

            $this->pool->markSuccess($upstream);

            // Не используем TcpConnection::pipe(): helper намеренно заменяет
            // onClose, а proxy должен сохранить cleanup и пользовательские hooks.
            $client->onMessage = static fn(TcpConnection $source, mixed $data) => $backend->send($data, true);
            $backend->onMessage = static fn(AsyncTcpConnection $source, mixed $data) => $client->send($data, true);
            $backend->onBufferFull = static fn() => $client->pauseRecv();
            $backend->onBufferDrain = static fn() => $client->resumeRecv();
            $client->onBufferFull = static fn() => $backend->pauseRecv();
            $client->onBufferDrain = static fn() => $backend->resumeRecv();
            $client->resumeRecv();
        };
        $backend->onError = function (AsyncTcpConnection $backend) use ($client, $upstream, $excluded, $release): void {
            if (($client->context->tcpProxyBackend ?? null) !== $backend) {
                return;
            }
            $this->pool->markFailure($upstream);
            $release();
            unset($client->context->tcpProxyBackend, $client->context->tcpProxyRelease);
            if ($client->getStatus() !== TcpConnection::STATUS_CLOSED) {
                $this->connectUpstream($client, $excluded);
            }
        };
        $backend->onClose = function () use ($client, $backend, $release): void {
            if (($client->context->tcpProxyBackend ?? null) !== $backend) {
                return;
            }
            $release();
            unset($client->context->tcpProxyBackend, $client->context->tcpProxyRelease);
            if ($client->getStatus() !== TcpConnection::STATUS_CLOSED) {
                $client->close();
            }
        };
        $backend->connect();
    }
}
