<?php
/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io/webcore
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core\Protocols;

use localzet\Core\Connection\TcpConnection;

/**
 * Frame Protocol.
 */
class Frame
{
    /**
     * Check the integrity of the package.
     *
     * @param string        $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        if (\strlen($buffer) < 4) {
            return 0;
        }
        $unpack_data = \unpack('Ntotal_length', $buffer);
        return $unpack_data['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return \substr($buffer, 4);
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        $total_length = 4 + \strlen($buffer);
        return \pack('N', $total_length) . $buffer;
    }
}
