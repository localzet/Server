<?php

/**
 * @package     Triangle Server (WebCore)
 * @link        https://github.com/localzet/WebCore
 * @link        https://github.com/Triangle-org/Server
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Core\Protocols\Http\Session;

use Redis;
use RedisException;
use RuntimeException;
use Throwable;
use localzet\Core\Protocols\Http\Session;
use localzet\Core\Timer;

/**
 * Class RedisSessionHandler
 * @package localzet\Core\Protocols\Http\Session
 */
class RedisSessionHandler implements SessionHandlerInterface
{

    /**
     * @var Redis
     */
    protected Redis $redis;

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
     * @throws RedisException
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
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        return true === $this->redis->setex($sessionId, Session::$lifetime, $sessionData);
    }

    /**
     * {@inheritdoc}
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        return true === $this->redis->expire($sessionId, Session::$lifetime);
    }

    /**
     * {@inheritdoc}
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
