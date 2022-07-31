<?php
/**
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
namespace localzet\Core\Protocols\Http;


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
    protected $_buffer = null;

    /**
     * Chunk constructor.
     * @param string $buffer
     */
    public function __construct($buffer)
    {
        $this->_buffer = $buffer;
    }

    /**
     * __toString
     *
     * @return string
     */
    public function __toString()
    {
        return \dechex(\strlen($this->_buffer))."\r\n$this->_buffer\r\n";
    }
}