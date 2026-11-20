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

// Static analysis stubs for ext-swow 1.6.2; this file is never loaded at runtime.

namespace {
    const STREAM_POLLNONE = 0;
    const STREAM_POLLIN = 1;
    const STREAM_POLLOUT = 4;
    const STREAM_POLLHUP = 16;
}

namespace Swow {
    class Coroutine
    {
        public static function run(callable $callable): static
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }

        public static function getCurrent(): self
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }

        public function getId(): int
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }

        public function isAvailable(): bool
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }

        public function kill(): void
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }
    }

    class Signal
    {
        public static function wait(int $signal, int $timeout = -1): void
        {
            throw new \LogicException('Static analysis stub; ext-swow supplies this method at runtime.');
        }
    }

    function stream_poll_one(mixed $stream, int $events, int $timeout = -1): int
    {
        throw new \LogicException('Static analysis stub; ext-swow supplies this function at runtime.');
    }
}

namespace Swow\Sync {
    function waitAll(int $timeout = -1): void
    {
        throw new \LogicException('Static analysis stub; ext-swow supplies this function at runtime.');
    }
}
