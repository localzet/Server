<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2025 Localzet Group
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

use localzet\Server\Protocols\Http\Session;
use localzet\Timer;
use Redis;
use RedisCluster;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * Class RedisSessionHandler
 * @package localzet\Server\Protocols\Http\Session
 */
class RedisSessionHandler implements SessionHandlerInterface
{
    /**
     * @var Redis|RedisCluster Расширение Redis или RedisCluster для взаимодействия с Redis-сервером.
     */
    protected Redis|RedisCluster $redis;

    /**
     * @var array Конфигурация Redis-сервера и сессий.
     */
    protected array $config;

    /**
     * Конструктор RedisSessionHandler.
     *
     * @param array $config Конфигурация Redis-сервера и сессий.
     *
     * @throws RedisException
     */
    public function __construct(array $config)
    {
        // Проверяем, установлено ли расширение Redis
        if (false === extension_loaded('redis')) {
            throw new RuntimeException('Пожалуйста, установите расширение redis.');
        }

        // Устанавливаем значение по умолчанию, если параметр timeout не указан в конфигурации
        if (!isset($config['timeout'])) {
            $config['timeout'] = 2;
        }

        $this->config = $config;

        // Устанавливаем соединение с Redis-сервером
        $this->connect();

        // Устанавливаем таймер для отправки команды ping на Redis-сервер
        Timer::add($config['ping'] ?? 55, function (): void {
            $this->redis->get('ping');
        });
    }

    /**
     * Устанавливает соединение с Redis-сервером.
     *
     * @throws RedisException
     */
    public function connect(): void
    {
        $config = $this->config;

        $this->redis = new Redis();
        if (false === $this->redis->connect($config['host'], $config['port'], $config['timeout'])) {
            throw new RuntimeException("Не удалось подключиться к Redis-серверу {$config['host']}:{$config['port']}.");
        }

        // Аутентификация, если указан пароль
        if (!empty($config['auth'])) {
            $this->redis->auth($config['auth']);
        }

        // Выбор базы данных, если указан номер базы данных
        if (!empty($config['database'])) {
            $this->redis->select($config['database']);
        }

        // Установка префикса для ключей сессий в Redis
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
     * @param string $sessionId Идентификатор сессии.
     * @throws RedisException
     * @throws Throwable
     */
    public function read(string $sessionId): string
    {
        try {
            // Читаем данные сессии из Redis по ключу
            return $this->redis->get($sessionId);
        } catch (Throwable $throwable) {
            $msg = strtolower($throwable->getMessage());
            // Если соединение с Redis было потеряно, восстанавливаем соединение и повторяем операцию чтения
            if ($msg === 'connection lost' || strpos($msg, 'went away')) {
                $this->connect();
                return $this->redis->get($sessionId);
            }

            throw $throwable;
        }
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        // Записываем данные сессии в Redis с установленным временем жизни
        return true === $this->redis->setex($sessionId, Session::$lifetime, $sessionData);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        // Обновляем время жизни ключа сессии в Redis
        return true === $this->redis->expire($sessionId, Session::$lifetime);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function destroy(string $sessionId): bool
    {
        // Удаляем ключ сессии из Redis
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
