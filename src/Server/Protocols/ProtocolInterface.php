<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;

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

