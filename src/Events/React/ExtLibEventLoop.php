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
namespace localzet\Core\Events\React;
use localzet\Core\Events\EventInterface;

/**
 * Class ExtLibEventLoop
 * @package localzet\Core\Events\React
 */
class ExtLibEventLoop extends Base
{
    public function __construct()
    {
        $this->_eventLoop = new \React\EventLoop\ExtLibeventLoop();
    }
}
