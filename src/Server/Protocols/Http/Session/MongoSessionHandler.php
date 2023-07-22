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
        $uri = $config['uri'] ?? 'mongodb://localhost:27017/?directConnection=true';
        $database = $config['database'] ?? 'default';
        $collection = $config['collection'] ?? 'sessions';
        $this->client = new Client($uri);
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
