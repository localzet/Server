<?php

/**
 * @package     Triangle Server (WebCore)
 * @link        https://github.com/localzet/WebCore
 * @link        https://github.com/Triangle-org/Server
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Core\Protocols;

use localzet\Core\Connection\ConnectionInterface;

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
