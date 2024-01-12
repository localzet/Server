<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
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
use function rtrim;
use function strlen;
use function strpos;

/**
 * Текстовый протокол.
 */
class Text
{
    /**
     * Проверим целостность пакета.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        // Проверяем, превышает ли длина пакета установленный предел.
        if (isset($connection->maxPackageSize) && strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        // Ищем позицию символа "\n".
        $pos = strpos($buffer, "\n");

        // Если "\n" не найден, длина пакета неизвестна, продолжаем ожидать данные, поэтому возвращаем 0.
        if ($pos === false) {
            return 0;
        }

        // Возвращаем текущую длину пакета.
        return $pos + 1;
    }

    /**
     * Кодируем данные перед отправкой.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode(string $buffer): string
    {
        // Добавляем символ "\n" к данным перед отправкой.
        return $buffer . "\n";
    }

    /**
     * Декодируем полученные данные.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode(string $buffer): string
    {
        // Удаляем символы "\r" и "\n" из полученных данных.
        return rtrim($buffer, "\r\n");
    }
}
