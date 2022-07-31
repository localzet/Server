<?php
/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core\Protocols\Http\Session;

use localzet\Core\Protocols\Http\Session;

class RedisClusterSessionHandler extends RedisSessionHandler
{
    public function __construct($config)
    {
        $timeout = isset($config['timeout']) ? $config['timeout'] : 2;
        $read_timeout = isset($config['read_timeout']) ? $config['read_timeout'] : $timeout;
        $persistent = isset($config['persistent']) ? $config['persistent'] : false;
        $auth = isset($config['auth']) ? $config['auth'] : '';
        if ($auth) {
            $this->_redis = new \RedisCluster(null, $config['host'], $timeout, $read_timeout, $persistent, $auth);
        } else {
            $this->_redis = new \RedisCluster(null, $config['host'], $timeout, $read_timeout, $persistent);
        }
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $this->_redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id)
    {
        return $this->_redis->get($session_id);
    }

}
