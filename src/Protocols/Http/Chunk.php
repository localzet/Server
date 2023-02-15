<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Server\Protocols\Http;

use function dechex;
use function strlen;

/**
 * Class Chunk
 * @package localzet\Server\Protocols\Http
 */
class Chunk
{
    /**
     * Chunk buffer.
     *
     * @var string
     */
    protected string $buffer;

    /**
     * Chunk constructor.
     * @param string $buffer
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * __toString
     *
     * @return string
     */
    public function __toString()
    {
        return dechex(strlen($this->buffer)) . "\r\n$this->buffer\r\n";
    }
}
