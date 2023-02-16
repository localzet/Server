<?php

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
        // Превышает ли длина пакета предел?
        if (isset($connection->maxPackageSize) && strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  Найти положение  "\n".
        $pos = strpos($buffer, "\n");

        // Нет "\n", длина пакета неизвестна, продолжаем ждать данных, поэтому вернём 0.
        if ($pos === false) {
            return 0;
        }

        // Вернём текущую длину пакета.
        return $pos + 1;
    }

    /**
     * Шифруем данные
     *
     * @param string $buffer
     * @return string
     */
    public static function encode(string $buffer): string
    {
        // Добавим "\n"
        return $buffer . "\n";
    }

    /**
     * Дешифруем данные
     *
     * @param string $buffer
     * @return string
     */
    public static function decode(string $buffer): string
    {
        // Удалим "\n"
        return rtrim($buffer, "\r\n");
    }
}
