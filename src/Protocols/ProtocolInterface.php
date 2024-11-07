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

namespace localzet\Protocols;

use localzet\Connection\ConnectionInterface;

/**
 * Интерфейс протокола
 */
interface ProtocolInterface
{
    /**
     * Проверьте целостность пакета.
     * Пожалуйста, верните длину пакета.
     * Если длина неизвестна, верните 0, что означает ожидание дополнительных данных.
     * Если в пакете есть какие-то проблемы, верните false, и соединение будет закрыто.
     *
     * @return int|false
     */
    public static function input(string $buffer, ConnectionInterface $connection): bool|int;

    /**
     * Расшифруйте пакет и вызовите обратный вызов onMessage($message), где $message - это результат, возвращенный функцией decode.
     */
    public static function decode(string $buffer, ConnectionInterface $connection): mixed;

    /**
     * Кодируйте пакет перед отправкой клиенту.
     */
    public static function encode(mixed $data, ConnectionInterface $connection): string;
}

