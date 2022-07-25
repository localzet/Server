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
 * Text Protocol.
 */
class Text
{
    /**
     * Check the integrity of the package.
     *
     * @param string        $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // Judge whether the package length exceeds the limit.
        if (isset($connection->maxPackageSize) && \strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  Find the position of  "\n".
        $pos = \strpos($buffer, "\n");
        // No "\n", packet length is unknown, continue to wait for the data so return 0.
        if ($pos === false) {
            return 0;
        }
        // Return the current package length.
        return $pos + 1;
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        // Add "\n"
        return $buffer . "\n";
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        // Remove "\n"
        return \rtrim($buffer, "\r\n");
    }
}
