<?php

/**
 * @version     1.0.0-dev
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
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
     * If length is unknow please return 0 that mean wating more data.
     * If the package has something wrong please return false the connection will be closed.
     *
     * @param string              $recv_buffer
     * @param ConnectionInterface $connection
     * @return int|false
     */
    public static function input($recv_buffer, ConnectionInterface $connection);

    /**
     * Decode package and emit onMessage($message) callback, $message is the result that decode returned.
     *
     * @param string              $recv_buffer
     * @param ConnectionInterface $connection
     * @return mixed
     */
    public static function decode($recv_buffer, ConnectionInterface $connection);

    /**
     * Encode package brefore sending to client.
     * 
     * @param string|\localzet\Core\Protocols\Http\Response $data
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($data, ConnectionInterface $connection);
}
