<?php

/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io/webcore
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.ru>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

// JIT-компилятор PCRE не стабилен, временно отключим
ini_set('pcre.jit', 0);

// Для onError
const WEBCORE_CONNECT_FAIL = 1;
const WEBCORE_SEND_FAIL = 2;

// Определения типов ОС
const OS_TYPE_LINUX   = 'linux';
const OS_TYPE_WINDOWS = 'windows';

// Совместим с PHP7 (позже удалю)
if (!class_exists('Error')) {
    class Error extends Exception
    {
    }
}

if (!interface_exists('SessionHandlerInterface')) {
    interface SessionHandlerInterface
    {
        public function close();
        public function destroy($session_id);
        public function gc($maxlifetime);
        public function open($save_path, $session_name);
        public function read($session_id);
        public function write($session_id, $session_data);
    }
}
