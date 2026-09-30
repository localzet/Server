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

/** Минимальный RESP2 framing/codec для совместимости со старым Localzet API. */
final class Redis implements ProtocolInterface
{
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        return self::frameLength($buffer);
    }

    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        $offset = 0;
        return self::decodeValue($buffer, $offset);
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        if (is_array($data)) {
            $out = '*' . count($data) . "\r\n";
            foreach ($data as $item) {
                $item = (string)$item;
                $out .= '$' . strlen($item) . "\r\n" . $item . "\r\n";
            }
            return $out;
        }
        return (string)$data;
    }

    private static function frameLength(string $buffer): int
    {
        if ($buffer === '') {
            return 0;
        }
        $type = $buffer[0];
        $lineEnd = strpos($buffer, "\r\n");
        if ($lineEnd === false) {
            return 0;
        }
        if ($type === '+' || $type === '-' || $type === ':') {
            return $lineEnd + 2;
        }
        if ($type === '$') {
            $size = (int)substr($buffer, 1, $lineEnd - 1);
            if ($size < 0) {
                return $lineEnd + 2;
            }
            $total = $lineEnd + 2 + $size + 2;
            return strlen($buffer) >= $total ? $total : 0;
        }
        if ($type === '*') {
            $count = (int)substr($buffer, 1, $lineEnd - 1);
            $offset = $lineEnd + 2;
            for ($i = 0; $i < $count; $i++) {
                $len = self::frameLength(substr($buffer, $offset));
                if ($len === 0) {
                    return 0;
                }
                $offset += $len;
            }
            return $offset;
        }
        throw new \RuntimeException('Unsupported RESP frame.');
    }

    private static function decodeValue(string $buffer, int &$offset): mixed
    {
        $type = $buffer[$offset++];
        $lineEnd = strpos($buffer, "\r\n", $offset);
        $line = substr($buffer, $offset, $lineEnd - $offset);
        $offset = $lineEnd + 2;
        return match ($type) {
            '+' => $line,
            '-' => new \RuntimeException($line),
            ':' => (int)$line,
            '$' => self::decodeBulk($buffer, $offset, (int)$line),
            '*' => self::decodeArray($buffer, $offset, (int)$line),
            default => throw new \RuntimeException('Unsupported RESP frame type.'),
        };
    }

    private static function decodeBulk(string $buffer, int &$offset, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        $value = substr($buffer, $offset, $length);
        $offset += $length + 2;
        return $value;
    }

    private static function decodeArray(string $buffer, int &$offset, int $count): ?array
    {
        if ($count < 0) {
            return null;
        }
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = self::decodeValue($buffer, $offset);
        }
        return $result;
    }
}
