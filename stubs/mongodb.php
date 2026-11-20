<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
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

// Static analysis declarations for the optional mongodb/mongodb dependency.

namespace MongoDB {
    class Client
    {
        public function __construct(string $uri, array $uriOptions = [], array $driverOptions = [])
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }

        public function selectCollection(string $databaseName, string $collectionName, array $options = []): Collection
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }
    }

    class Collection
    {
        public function findOne(array $filter, array $options = []): mixed
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }

        public function updateOne(array $filter, array $update, array $options = []): mixed
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }

        public function deleteOne(array $filter, array $options = []): mixed
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }

        public function deleteMany(array $filter, array $options = []): mixed
        {
            throw new \LogicException('Static analysis stub; mongodb/mongodb supplies this method at runtime.');
        }
    }
}

namespace MongoDB\BSON {
    class Binary
    {
        public const TYPE_GENERIC = 0;

        public function __construct(string $data, int $type)
        {
            throw new \LogicException('Static analysis stub; ext-mongodb supplies this method at runtime.');
        }

        public function getData(): string
        {
            throw new \LogicException('Static analysis stub; ext-mongodb supplies this method at runtime.');
        }
    }

    class UTCDateTime
    {
        public function __construct(?int $milliseconds = null)
        {
            throw new \LogicException('Static analysis stub; ext-mongodb supplies this method at runtime.');
        }
    }
}
