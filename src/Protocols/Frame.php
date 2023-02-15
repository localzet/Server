<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Server\Protocols;

use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Frame Protocol.
 */
class Frame
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @return int
     */
    public static function input(string $buffer): int
    {
        if (strlen($buffer) < 4) {
            return 0;
        }
        $unpackData = unpack('Ntotal_length', $buffer);
        return $unpackData['total_length'];
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode(string $buffer): string
    {
        return substr($buffer, 4);
    }

    /**
     * Encode.
     *
     * @param string $data
     * @return string
     */
    public static function encode(string $data): string
    {
        $totalLength = 4 + strlen($data);
        return pack('N', $totalLength) . $data;
    }
}
