<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 * 
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *              
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *              
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Protocols\Http\Session;

use Throwable;
use RuntimeException;

use Redis;
use RedisCluster;
use RedisException;

use localzet\Server\Protocols\Http\Session;

use localzet\Server\Timer;

/**
 * Class RedisSessionHandler
 * @package localzet\Server\Protocols\Http\Session
 */
class RedisSessionHandler implements SessionHandlerInterface
{

    /**
     * @var Redis|RedisCluster
     */
    protected Redis|RedisCluster $redis;

    /**
     * @var array
     */
    protected array $config;

    /**
     * RedisSessionHandler constructor.
     * @param array $config = [
     *  'host'     => '127.0.0.1',
     *  'port'     => 6379,
     *  'timeout'  => 2,
     *  'auth'     => '******',
     *  'database' => 2,
     *  'prefix'   => 'redis_session_',
     *  'ping'     => 55,
     * ]
     * @throws RedisException
     */
    public function __construct(array $config)
    {
        if (false === extension_loaded('redis')) {
            throw new RuntimeException('Please install redis extension.');
        }

        if (!isset($config['timeout'])) {
            $config['timeout'] = 2;
        }

        $this->config = $config;

        $this->connect();

        Timer::add($config['ping'] ?? 55, function () {
            $this->redis->get('ping');
        });
    }

    /**
     * @throws RedisException
     */
    public function connect()
    {
        $config = $this->config;

        $this->redis = new Redis();
        if (false === $this->redis->connect($config['host'], $config['port'], $config['timeout'])) {
            throw new RuntimeException("Redis connect {$config['host']}:{$config['port']} fail.");
        }
        if (!empty($config['auth'])) {
            $this->redis->auth($config['auth']);
        }
        if (!empty($config['database'])) {
            $this->redis->select($config['database']);
        }
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $this->redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $savePath, string $name): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     * @param string $sessionId
     * @return string
     * @throws RedisException
     * @throws Throwable
     */
    public function read(string $sessionId): string
    {
        try {
            return $this->redis->get($sessionId);
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if ($msg === 'connection lost' || strpos($msg, 'went away')) {
                $this->connect();
                return $this->redis->get($sessionId);
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        return true === $this->redis->setex($sessionId, Session::$lifetime, $sessionData);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        return true === $this->redis->expire($sessionId, Session::$lifetime);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function destroy(string $sessionId): bool
    {
        $this->redis->del($sessionId);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): bool
    {
        return true;
    }
}
