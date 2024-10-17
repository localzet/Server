<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols\Http\Session;

use localzet\Server;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;

/**
 * Class MongoSessionHandler
 * @package localzet\Server\Protocols\Http\Session
 */
class MongoSessionHandler implements SessionHandlerInterface
{
    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var Collection
     */
    protected Collection $collection;

    /**
     * Конструктор MongoSessionHandler.
     *
     * @param array $config Конфигурация Redis-сервера и сессий.
     */
    public function __construct(array $config)
    {
        $uri = $config['url'] ?? null;
        $database = $config['database'] ?? 'default';
        $collection = $config['collection'] ?? 'sessions';

        if (!isset($config['url'])) {
            $hosts = is_array($config['host']) ? $config['host'] : [$config['host']];

            foreach ($hosts as &$host) {
                // ipv6
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $host = '[' . $host . ']';
                    if (!empty($config['port'])) {
                        $host .= ':' . $config['port'];
                    }
                } else {
                    // Check if we need to add a port to the host
                    if (!str_contains((string) $host, ':') && !empty($config['port'])) {
                        $host .= ':' . $config['port'];
                    }
                }
            }

            $uri = 'mongodb://' . implode(',', $hosts);
        }

        $options = $config['options'] ?? [];
        if (!isset($options['username']) && !empty($config['username'])) {
            $options['username'] = $config['username'];
        }
        if (!isset($options['password']) && !empty($config['password'])) {
            $options['password'] = $config['password'];
        }

        $this->client = new Client($uri, $options, ['name' => 'Localzet-Server', 'version' => Server::getVersion(), 'platform' => PHP_OS_FAMILY]);
        $this->collection = $this->client->$database->$collection;
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
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $sessionId): string
    {
        $session = $this->collection->findOne(['_id' => $sessionId]);
        if ($session !== null) {
            return serialize((array)$session);
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        $session = ['_id' => $sessionId] + unserialize($sessionData);
        $options = ['upsert' => true];
        $this->collection->replaceOne(['_id' => $sessionId], $session, $options);
        $this->updateTimestamp($sessionId);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        $this->collection->updateOne(['_id' => $sessionId], ['$set' => ['updated_at' => new UTCDateTime()]]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $sessionId): bool
    {
        $this->collection->deleteOne(['_id' => $sessionId]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): bool
    {
        $expirationDate = new UTCDateTime(time() - $maxLifetime * 1000);
        $this->collection->deleteMany(['updated_at' => ['$lt' => $expirationDate]]);
        return true;
    }
}
