<?php

/**
 * @version     1.0.0-dev
 * @package     localzet V3 WebEngine
 * @link        https://v3.localzet.ru
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

// JIT-компилятор PCRE не стабилен, временно отключим
ini_set('pcre.jit', 0);

// Для onError
const V3_CONNECT_FAIL = 1;
const V3_SEND_FAIL = 2;

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
