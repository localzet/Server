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
 * Class ServerSentEvents
 * @package localzet\Core\Protocols\Http
 */
class ServerSentEvents
{
    /**
     * Data.
     * @var array
     */
    protected $_data = null;

    /**
     * ServerSentEvents constructor.
     * $data for example ['event'=>'ping', 'data' => 'some thing', 'id' => 1000, 'retry' => 5000]
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->_data = $data;
    }

    /**
     * __toString.
     *
     * @return string
     */
    public function __toString()
    {
        $buffer = '';
        $data = $this->_data;
        if (isset($data[''])) {
            $buffer = ": {$data['']}\n";
        }
        if (isset($data['event'])) {
            $buffer .= "event: {$data['event']}\n";
        }
        if (isset($data['data'])) {
            $buffer .= 'data: ' . \str_replace("\n", "\ndata: ", $data['data']) . "\n\n";
        }
        if (isset($data['id'])) {
            $buffer .= "id: {$data['id']}\n";
        }
        if (isset($data['retry'])) {
            $buffer .= "retry: {$data['retry']}\n";
        }
        return $buffer;
    }
}
