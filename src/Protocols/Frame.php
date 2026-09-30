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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;

/**
 * Бинарный length-prefixed framing: uint32 network-order + payload.
 */
final class Frame implements ProtocolInterface
{
    private const HEADER_LENGTH = 4;

    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        if (strlen($buffer) < self::HEADER_LENGTH) {
            return 0;
        }
        $length = unpack('Nlength', substr($buffer, 0, 4))['length'];
        if ($length < 0 || $length > 64 * 1024 * 1024) {
            throw new \RuntimeException('Invalid frame length: ' . $length);
        }
        return self::HEADER_LENGTH + $length;
    }

    public static function decode(string $buffer, ConnectionInterface $connection): string
    {
        return substr($buffer, self::HEADER_LENGTH);
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        $payload = (string)$data;
        return pack('N', strlen($payload)) . $payload;
    }
}
