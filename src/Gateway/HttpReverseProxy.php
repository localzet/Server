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

namespace localzet\Server\Gateway;

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Proxy\UpstreamPool;
use RuntimeException;
use WeakMap;

/**
 * Streaming HTTP/1.x reverse proxy для Localzet Server.
 *
 * Gateway работает поверх raw TCP server и самостоятельно разбирает только
 * HTTP framing. Тело запроса/ответа не материализуется целиком в памяти:
 * Content-Length и chunked payload прокачиваются по мере получения.
 *
 * Осознанные границы 6.4:
 * - один in-flight HTTP request на клиентское соединение;
 * - pipeline-байты сохраняются, но следующий запрос отправляется после ответа;
 * - retry разрешён только до успешного connect к upstream;
 * - HTTP/2/3, cache, service discovery и ACME не входят в этот слой.
 */
final class HttpReverseProxy
{
    /** @var list<HttpRoute> */
    private array $routes = [];

    /** @var WeakMap<TcpConnection, HttpProxySession> */
    private WeakMap $sessions;

    private ?UpstreamPool $defaultPool = null;
    private float $connectTimeout = 2.0;
    private int $maxHeaderSize = 32768;

    /** Runtime counters текущего worker. */
    private array $statistics = [
        'requests' => 0,
        'upstreamConnects' => 0,
        'upstreamFailures' => 0,
        'retries' => 0,
        'websocketTunnels' => 0,
        'badGateway' => 0,
        'bytesClientToUpstream' => 0,
        'bytesUpstreamToClient' => 0,
    ];

    public function __construct()
    {
        $this->sessions = new WeakMap();
    }

    public function route(HttpRoute $route): static
    {
        $this->routes[] = $route;
        return $this;
    }

    public function default(UpstreamPool $pool): static
    {
        $this->defaultPool = $pool;
        return $this;
    }

    public function setConnectTimeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Gateway connect timeout must be greater than zero.');
        }
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function setMaxHeaderSize(int $bytes): static
    {
        if ($bytes < 1024) {
            throw new \InvalidArgumentException('Gateway header limit must be at least 1024 bytes.');
        }
        $this->maxHeaderSize = $bytes;
        return $this;
    }

    /**
     * Подключает gateway к raw TCP Server instance.
     *
     * Мы не меняем Server.php и не добавляем в core знания о reverse proxy:
     * gateway остаётся обычным потребителем transport API.
     */
    public function attach(Server $server): void
    {
        if ($server->protocol !== null) {
            throw new \LogicException('HttpReverseProxy requires a raw TCP server without application protocol.');
        }

        $userOnConnect = $server->onConnect;
        $userOnClose = $server->onClose;

        $server->onConnect = function (TcpConnection $client) use ($userOnConnect, $userOnClose): void {
            if ($userOnConnect !== null) {
                $userOnConnect($client);
            }

            $session = new HttpProxySession($this, $client, $this->connectTimeout, $this->maxHeaderSize);
            $this->sessions[$client] = $session;

            // acceptTcpConnection() уже скопировал Server::onClose в connection,
            // поэтому здесь безопасно цепляем cleanup без потери callback пользователя.
            $client->onClose = function (TcpConnection $connection) use ($session, $userOnClose): void {
                $session->close();
                unset($this->sessions[$connection]);
                if ($userOnClose !== null) {
                    $userOnClose($connection);
                }
            };
        };

        $server->onMessage = function (TcpConnection $client, string $data): void {
            if (!isset($this->sessions[$client])) {
                return;
            }
            $this->sessions[$client]->onClientData($data);
        };
    }

    public function resolveRoute(string $host, string $path): ?HttpRoute
    {
        foreach ($this->routes as $route) {
            if ($route->matches($host, $path)) {
                return $route;
            }
        }
        return $this->defaultPool === null ? null : new HttpRoute($this->defaultPool);
    }

    /** @return array<string, int> */
    public function status(): array
    {
        return $this->statistics;
    }

    /** @internal */
    public function increment(string $counter, int $amount = 1): void
    {
        if (isset($this->statistics[$counter])) {
            $this->statistics[$counter] += $amount;
        }
    }
}
