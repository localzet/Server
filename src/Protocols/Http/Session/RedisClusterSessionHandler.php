<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Server\Protocols\Http\Session;

use Redis;
use RedisCluster;
use RedisClusterException;

class RedisClusterSessionHandler extends RedisSessionHandler
{
    /**
     * @param $config
     * @throws RedisClusterException
     */
    public function __construct($config)
    {
        $timeout = $config['timeout'] ?? 2;
        $readTimeout = $config['read_timeout'] ?? $timeout;
        $persistent = $config['persistent'] ?? false;
        $auth = $config['auth'] ?? '';
        $args = [null, $config['host'], $timeout, $readTimeout, $persistent];
        if ($auth) {
            $args[] = $auth;
        }
        $this->redis = new RedisCluster(...$args);
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $this->redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $sessionId): string
    {
        return $this->redis->get($sessionId);
    }
}
