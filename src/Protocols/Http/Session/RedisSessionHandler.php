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

namespace localzet\Server\Protocols\Http\Session;

/** Redis session storage через ext-redis. */
class RedisSessionHandler implements SessionHandlerInterface
{
    protected \Redis $redis;
    protected string $prefix;
    protected int $lifetime;

    public function __construct(array $config = [])
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('ext-redis is required for RedisSessionHandler.');
        }

        $this->redis = new \Redis();
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 6379);
        $timeout = (float)($config['timeout'] ?? 2.0);
        $persistent = (bool)($config['persistent'] ?? false);
        $connected = $persistent
            ? $this->redis->pconnect($host, $port, $timeout)
            : $this->redis->connect($host, $port, $timeout);
        if (!$connected) {
            throw new \RuntimeException("Unable to connect to Redis {$host}:{$port}");
        }

        if (array_key_exists('auth', $config) && $config['auth'] !== '' && $config['auth'] !== null) {
            if (!$this->redis->auth($config['auth'])) {
                throw new \RuntimeException('Redis authentication failed.');
            }
        }
        if (isset($config['database']) && !$this->redis->select((int)$config['database'])) {
            throw new \RuntimeException('Unable to select Redis database.');
        }

        $this->prefix = (string)($config['prefix'] ?? 'localzet:session:');
        $this->lifetime = max(1, (int)($config['lifetime'] ?? 1440));
    }

    public function open(string $savePath, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string|false
    {
        $value = $this->redis->get($this->key($sessionId));
        return $value === false ? false : (string)$value;
    }

    public function write(string $sessionId, string $sessionData): bool
    {
        return (bool)$this->redis->setex($this->key($sessionId), $this->lifetime, $sessionData);
    }

    public function updateTimestamp(string $sessionId, string $data = ''): bool
    {
        return (bool)$this->redis->expire($this->key($sessionId), $this->lifetime);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->redis->del($this->key($sessionId)) >= 0;
    }

    public function gc(int $maxLifetime): bool
    {
        // Redis TTL сам удаляет устаревшие ключи.
        return true;
    }

    protected function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}
