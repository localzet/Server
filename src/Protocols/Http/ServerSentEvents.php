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
    protected array $data;

    /**
     * ServerSentEvents constructor.
     * $data for example ['event'=>'ping', 'data' => 'some thing', 'id' => 1000, 'retry' => 5000]
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * __toString.
     *
     * @return string
     */
    public function __toString()
    {
        $buffer = '';
        $data = $this->data;
        if (isset($data[''])) {
            $buffer = ": {$data['']}\n";
        }
        if (isset($data['event'])) {
            $buffer .= "event: {$data['event']}\n";
        }
        if (isset($data['data'])) {
            $buffer .= 'data: ' . str_replace("\n", "\ndata: ", $data['data']) . "\n\n";
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
