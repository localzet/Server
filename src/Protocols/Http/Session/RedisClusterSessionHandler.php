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

/** Redis Cluster session storage. */
class RedisClusterSessionHandler implements SessionHandlerInterface
{
    protected \RedisCluster $redis;
    protected string $prefix;
    protected int $lifetime;

    public function __construct(array $config = [])
    {
        if (!class_exists(\RedisCluster::class)) {
            throw new \RuntimeException('ext-redis with RedisCluster support is required.');
        }

        $seeds = $config['seeds'] ?? ['127.0.0.1:6379'];
        if (!is_array($seeds) || $seeds === []) {
            throw new \InvalidArgumentException('RedisCluster seeds must be a non-empty array.');
        }

        $this->redis = new \RedisCluster(
            null,
            $seeds,
            (float)($config['timeout'] ?? 2.0),
            (float)($config['read_timeout'] ?? 2.0),
            (bool)($config['persistent'] ?? true),
            $config['auth'] ?? null,
        );
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
        return true;
    }

    protected function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}
