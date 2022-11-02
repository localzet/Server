<?php

/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io/webcore
 * 
 * @author      localzet <creator@localzet.ru>
 * @copyright   Copyright (c) 2018-2022 RootX Group
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core\Events\React;

/**
 * Class StreamSelectLoop
 * @package localzet\Core\Events\React
 */
class StreamSelectLoop extends Base
{
    public function __construct()
    {
        $this->_eventLoop = new \React\EventLoop\StreamSelectLoop();
    }
}
