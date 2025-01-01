<?php declare(strict_types=1);

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

use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Протокол Frame.
 */
class Frame
{
    /**
     * Проверка целостности пакета.
     */
    public static function input(string $buffer): int
    {
        // Если длина буфера меньше 4, возвращаем 0.
        if (strlen($buffer) < 4) {
            return 0;
        }

        // Распаковываем данные из буфера.
        $unpackData = unpack('Ntotal_length', $buffer);
        // Возвращаем общую длину.
        return $unpackData['total_length'];
    }

    /**
     * Декодирование.
     */
    public static function decode(string $buffer): string
    {
        // Возвращаем подстроку буфера, начиная с 4-го символа.
        return substr($buffer, 4);
    }

    /**
     * Кодирование.
     */
    public static function encode(string $data): string
    {
        // Общая длина равна 4 плюс длина данных.
        $totalLength = 4 + strlen($data);
        // Возвращаем упакованные данные.
        return pack('N', $totalLength) . $data;
    }
}
