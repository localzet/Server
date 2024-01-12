<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Protocols\Http\Session;

use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;

/**
 * Class RedisClusterSessionHandler
 * @package localzet\Server\Protocols\Http\Session
 */
class RedisClusterSessionHandler extends RedisSessionHandler
{
    /**
     * Конструктор RedisClusterSessionHandler.
     *
     * @param array $config Конфигурация Redis-кластера.
     *
     * @throws RedisClusterException
     * @throws RedisException
     */
    public function __construct(array $config)
    {
        // Извлекаем значения из конфигурации или устанавливаем значения по умолчанию
        $timeout = $config['timeout'] ?? 2;
        $readTimeout = $config['read_timeout'] ?? $timeout;
        $persistent = $config['persistent'] ?? false;
        $auth = $config['auth'] ?? '';

        // Формируем аргументы для создания экземпляра RedisCluster
        $args = [null, $config['host'], $timeout, $readTimeout, $persistent];
        if ($auth) {
            $args[] = $auth;
        }

        // Создаем экземпляр RedisCluster
        $this->redis = new RedisCluster(...$args);

        // Если префикс не указан в конфигурации, устанавливаем значение по умолчанию
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }

        // Устанавливаем префикс для ключей сессий в Redis
        $this->redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $sessionId): string
    {
        // Читаем данные сессии из Redis по ключу
        return $this->redis->get($sessionId);
    }
}
