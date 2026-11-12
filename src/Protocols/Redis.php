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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;

/** Минимальный RESP2 framing/codec для совместимости со старым Localzet API. */
final class Redis implements ProtocolInterface
{
    private const MAX_NESTING_DEPTH = 64;
    private const MAX_VALUES_PER_FRAME = 100_000;

    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        return self::frameLength($buffer);
    }

    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        if (self::frameLength($buffer) === 0) {
            throw new \RuntimeException('Incomplete RESP frame.');
        }
        $offset = 0;
        $remainingValues = self::MAX_VALUES_PER_FRAME;
        return self::decodeValue($buffer, $offset, 0, $remainingValues);
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
        $offset = 0;
        $remainingValues = self::MAX_VALUES_PER_FRAME;
        if (!self::advanceFrame($buffer, $offset, 0, $remainingValues)) {
            return 0;
        }
        return $offset;
    }

    private static function advanceFrame(string $buffer, int &$offset, int $depth, int &$remainingValues): bool
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new \RuntimeException('RESP nesting depth exceeds the supported limit.');
        }
        if (--$remainingValues < 0) {
            throw new \RuntimeException('RESP frame contains too many values.');
        }

        $type = $buffer[$offset] ?? '';
        if ($type === '') {
            return false;
        }
        $lineEnd = strpos($buffer, "\r\n", $offset + 1);
        if ($lineEnd === false) {
            return false;
        }
        $line = substr($buffer, $offset + 1, $lineEnd - $offset - 1);
        $offset = $lineEnd + 2;
        if ($type === '+' || $type === '-' || $type === ':') {
            return true;
        }
        if ($type === '$') {
            $size = (int)$line;
            if ($size < 0) {
                return true;
            }
            $available = strlen($buffer) - $offset;
            if ($available < 2 || $size > $available - 2) {
                return false;
            }
            if (substr($buffer, $offset + $size, 2) !== "\r\n") {
                throw new \RuntimeException('Invalid RESP bulk string terminator.');
            }
            $offset += $size + 2;
            return true;
        }
        if ($type === '*') {
            $count = (int)$line;
            if ($count < 0) {
                return true;
            }
            if ($count > $remainingValues) {
                throw new \RuntimeException('RESP frame contains too many array values.');
            }
            for ($i = 0; $i < $count; $i++) {
                if (!self::advanceFrame($buffer, $offset, $depth + 1, $remainingValues)) {
                    return false;
                }
            }
            return true;
        }
        throw new \RuntimeException('Unsupported RESP frame.');
    }

    private static function decodeValue(string $buffer, int &$offset, int $depth, int &$remainingValues): mixed
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new \RuntimeException('RESP nesting depth exceeds the supported limit.');
        }
        if (--$remainingValues < 0) {
            throw new \RuntimeException('RESP frame contains too many values.');
        }

        $type = $buffer[$offset++] ?? '';
        $lineEnd = strpos($buffer, "\r\n", $offset);
        if ($type === '' || $lineEnd === false) {
            throw new \RuntimeException('Invalid RESP frame.');
        }
        $line = substr($buffer, $offset, $lineEnd - $offset);
        $offset = $lineEnd + 2;
        return match ($type) {
            '+' => $line,
            '-' => new \RuntimeException($line),
            ':' => (int)$line,
            '$' => self::decodeBulk($buffer, $offset, (int)$line),
            '*' => self::decodeArray($buffer, $offset, (int)$line, $depth, $remainingValues),
            default => throw new \RuntimeException('Unsupported RESP frame type.'),
        };
    }

    private static function decodeBulk(string $buffer, int &$offset, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }
        $available = strlen($buffer) - $offset;
        if ($available < 2 || $length > $available - 2) {
            throw new \RuntimeException('Incomplete RESP bulk string.');
        }
        if (substr($buffer, $offset + $length, 2) !== "\r\n") {
            throw new \RuntimeException('Invalid RESP bulk string terminator.');
        }
        $value = substr($buffer, $offset, $length);
        $offset += $length + 2;
        return $value;
    }

    private static function decodeArray(string $buffer, int &$offset, int $count, int $depth, int &$remainingValues): ?array
    {
        if ($count < 0) {
            return null;
        }
        if ($count > $remainingValues) {
            throw new \RuntimeException('RESP frame contains too many array values.');
        }
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = self::decodeValue($buffer, $offset, $depth + 1, $remainingValues);
        }
        return $result;
    }
}
