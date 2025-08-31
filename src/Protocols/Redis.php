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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;

use function count;
use function is_array;
use function strlen;
use function strpos;
use function substr;

/**
 * Протокол Redis.
 */
class Redis implements ProtocolInterface
{
    /** @inheritdoc */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        $type = $buffer[0];
        $pos = strpos($buffer, "\r\n");
        if (false === $pos) {
            return 0;
        }
        switch ($type) {
            case ':':
            case '+':
            case '-':
                return $pos + 2;
            case '$':
                if (str_starts_with($buffer, '$-1')) {
                    return 5;
                }
                return $pos + 4 + (int)substr($buffer, 1, $pos);
            case '*':
                if (str_starts_with($buffer, '*-1')) {
                    return 5;
                }
                $count = (int)substr($buffer, 1, $pos - 1);
                while ($count--) {
                    $next_pos = strpos($buffer, "\r\n", $pos + 2);
                    if (!$next_pos) {
                        return 0;
                    }
                    $sub_type = $buffer[$pos + 2];
                    switch ($sub_type) {
                        case ':':
                        case '+':
                        case '-':
                            $pos = $next_pos;
                            break;
                        case '$':
                            if ($pos + 2 === strpos($buffer, '$-1', $pos)) {
                                $pos = $next_pos;
                                break;
                            }
                            $length = (int)substr($buffer, $pos + 3, $next_pos - $pos - 3);
                            $pos = $next_pos + $length + 2;
                            if (strlen($buffer) < $pos) {
                                return 0;
                            }
                            break;
                        default:
                            return strlen($buffer);
                    }
                }
                return $pos + 2;
            default:
                return strlen($buffer);
        }
    }

    /** @inheritdoc */
    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        $cmd = '';
        $count = count($data);
        foreach ($data as $item) {
            if (is_array($item)) {
                $count += count($item) - 1;
                foreach ($item as $str) {
                    $cmd .= '$' . strlen($str) . "\r\n$str\r\n";
                }
                continue;
            }
            $cmd .= '$' . strlen($item) . "\r\n$item\r\n";
        }
        return "*$count\r\n$cmd";
    }

    /** @inheritdoc */
    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        $type = $buffer[0];
        switch ($type) {
            case ':':
                return [$type, (int)substr($buffer, 1)];
            case '-':
            case '+':
                return [$type, substr($buffer, 1, strlen($buffer) - 3)];
            case '$':
                if (str_starts_with($buffer, '$-1')) {
                    return [$type, null];
                }
                $pos = strpos($buffer, "\r\n");
                return [$type, substr($buffer, $pos + 2, (int)substr($buffer, 1, $pos))];
            case '*':
                if (str_starts_with($buffer, '*-1')) {
                    return [$type, null];
                }
                $pos = strpos($buffer, "\r\n");
                $value = [];
                $count = (int)substr($buffer, 1, $pos - 1);
                while ($count--) {
                    $next_pos = strpos($buffer, "\r\n", $pos + 2);
                    if (!$next_pos) {
                        return 0;
                    }
                    $sub_type = $buffer[$pos + 2];
                    switch ($sub_type) {
                        case ':':
                            $value[] = (int)substr($buffer, $pos + 3, $next_pos - $pos - 3);
                            $pos = $next_pos;
                            break;
                        case '-':
                        case '+':
                            $value[] = substr($buffer, $pos + 3, $next_pos - $pos - 3);
                            $pos = $next_pos;
                            break;
                        case '$':
                            if ($pos + 2 === strpos($buffer, '$-1', $pos)) {
                                $pos = $next_pos;
                                $value[] = null;
                                break;
                            }
                            $length = (int)substr($buffer, $pos + 3, $next_pos - $pos - 3);
                            $value[] = substr($buffer, $next_pos + 2, $length);
                            $pos = $next_pos + $length + 2;
                            break;
                        default:
                            return ['!', "protocol error, got '$sub_type' as reply type byte. buffer:" . bin2hex($buffer) . " pos:$pos"];
                    }
                }
                return [$type, $value];
            default:
                return ['!', "protocol error, got '$type' as reply type byte. buffer:" . bin2hex($buffer)];
        }
    }
}
