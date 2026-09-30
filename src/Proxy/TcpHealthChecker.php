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

use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Events\EventInterface;

/**
 * Active TCP health checker для upstream pool.
 *
 * Проверка считается успешной после TCP/TLS establishment. Прикладной probe
 * специально не навязывается: L7 health endpoints должны жить выше этого слоя.
 */
final class TcpHealthChecker
{
    private int $timerId = 0;

    public function __construct(
        private readonly UpstreamPool   $pool,
        private readonly EventInterface $eventLoop,
        private readonly float          $interval = 5.0,
        private readonly float          $connectTimeout = 1.0,
    )
    {
    }

    public function start(): void
    {
        if ($this->timerId !== 0) {
            return;
        }
        $this->timerId = $this->eventLoop->repeat(max(0.1, $this->interval), $this->check(...));
        $this->check();
    }

    public function stop(): void
    {
        if ($this->timerId === 0) {
            return;
        }
        $this->eventLoop->offRepeat($this->timerId);
        $this->timerId = 0;
    }

    public function check(): void
    {
        foreach ($this->pool->all() as $upstream) {
            $connection = new AsyncTcpConnection($upstream->address, [], $this->eventLoop);
            $connection->setConnectTimeout($this->connectTimeout);
            $connection->onConnect = function (AsyncTcpConnection $connection) use ($upstream): void {
                $this->pool->markSuccess($upstream);
                $connection->close();
            };
            $connection->onError = function () use ($upstream): void {
                $this->pool->markFailure($upstream);
            };
            $connection->connect();
        }
    }
}
