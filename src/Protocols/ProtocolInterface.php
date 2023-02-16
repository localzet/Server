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

/**
 * Protocol interface
 */
interface ProtocolInterface
{
    /**
     * Check the integrity of the package.
     * Please return the length of package.
     * If length is unknown please return 0 that means waiting for more data.
     * If the package has something wrong please return false the connection will be closed.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int|false
     */
    public static function input(string $buffer, ConnectionInterface $connection): bool|int;

    /**
     * Decode package and emit onMessage($message) callback, $message is the result that decode returned.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return mixed
     */
    public static function decode(string $buffer, ConnectionInterface $connection): mixed;

    /**
     * Encode package before sending to client.
     * 
     * @param mixed $data
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode(mixed $data, ConnectionInterface $connection): string;
}
