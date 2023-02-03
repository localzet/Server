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

namespace localzet\Core\Protocols\Http;

use function dechex;
use function strlen;

/**
 * Class Chunk
 * @package localzet\Core\Protocols\Http
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
