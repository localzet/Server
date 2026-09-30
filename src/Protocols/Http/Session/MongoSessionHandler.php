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

use DateTimeImmutable;
use localzet\Server;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;

/**
 * MongoDB session storage.
 *
 * Payload хранится как бинарная сериализованная строка, а не раскладывается в
 * BSON-поля. Это сохраняет точное значение PHP session data и не допускает
 * неожиданной десериализации объектов драйвером MongoDB.
 */
class MongoSessionHandler implements SessionHandlerInterface
{
    protected Client $client;
    protected Collection $collection;

    public function __construct(
        array $config = [],
        array $uriOptions = [],
        array $driverOptions = [],
    )
    {
        if (!class_exists(Client::class)) {
            throw new \RuntimeException('mongodb/mongodb is required for MongoSessionHandler.');
        }

        $uri = $config['url'] ?? null;
        if (!is_string($uri) || $uri === '') {
            $hosts = $config['host'] ?? '127.0.0.1';
            $hosts = is_array($hosts) ? $hosts : [$hosts];
            $port = isset($config['port']) ? (int)$config['port'] : 27017;
            $authorities = [];
            foreach ($hosts as $host) {
                $host = (string)$host;
                if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $host = '[' . $host . ']';
                }
                $authorities[] = str_contains($host, ':') && !str_starts_with($host, '[')
                    ? $host
                    : $host . ':' . $port;
            }
            $uri = 'mongodb://' . implode(',', $authorities);
        }

        // Сохраняем историческую config-array форму и дополнительно разрешаем
        // нативные MongoDB Client uri/driver options без необходимости прятать
        // их в нестандартных Localzet ключах.
        $options = array_replace($config['options'] ?? [], $uriOptions);
        if (!isset($options['username']) && !empty($config['username'])) $options['username'] = $config['username'];
        if (!isset($options['password']) && !empty($config['password'])) $options['password'] = $config['password'];

        $driverOptions = array_replace(
            [
                'name' => 'Localzet-Server',
                'version' => Server::getVersion(),
                'platform' => PHP_OS_FAMILY,
            ],
            is_array($config['driver_options'] ?? null) ? $config['driver_options'] : [],
            $driverOptions,
        );

        $this->client = new Client($uri, $options, $driverOptions);
        $database = (string)($config['database'] ?? 'default');
        $collection = (string)($config['collection'] ?? 'sessions');
        $this->collection = $this->client->selectCollection($database, $collection);
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
        $session = $this->collection->findOne(['_id' => $sessionId]);
        if ($session === null || !isset($session['data'])) {
            return false;
        }
        $data = $session['data'];
        if ($data instanceof Binary) {
            return $data->getData();
        }
        return is_string($data) ? $data : false;
    }

    public function write(string $sessionId, string $sessionData): bool
    {
        $result = $this->collection->updateOne(
            ['_id' => $sessionId],
            ['$set' => [
                'data' => new Binary($sessionData, Binary::TYPE_GENERIC),
                'updated_at' => new UTCDateTime(),
            ]],
            ['upsert' => true],
        );
        return $result->isAcknowledged();
    }

    public function updateTimestamp(string $sessionId, string $data = ''): bool
    {
        $result = $this->collection->updateOne(
            ['_id' => $sessionId],
            ['$set' => ['updated_at' => new UTCDateTime()]],
        );
        return $result->isAcknowledged() && $result->getMatchedCount() > 0;
    }

    public function destroy(string $sessionId): bool
    {
        return $this->collection->deleteOne(['_id' => $sessionId])->isAcknowledged();
    }

    public function gc(int $maxLifetime): bool
    {
        $thresholdMs = ((new DateTimeImmutable())->getTimestamp() - $maxLifetime) * 1000;
        return $this->collection
            ->deleteMany(['updated_at' => ['$lt' => new UTCDateTime($thresholdMs)]])
            ->isAcknowledged();
    }
}
