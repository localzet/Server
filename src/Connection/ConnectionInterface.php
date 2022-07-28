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

namespace localzet\Core\Connection;

/**
 * ConnectionInterface.
 */
abstract class  ConnectionInterface
{
    /**
     * Статистика для команды статуса
     *
     * @var array
     */
    public static $statistics = array(
        'connection_count' => 0,
        'total_request'    => 0,
        'throw_exception'  => 0,
        'send_fail'        => 0,
    );

    /**
     * Задаётся при получении данных
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Задаётся, когда другой конец сокета отправляет пакет FIN
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Задаётся, когда возникает ошибка с подключением
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Sends data on the connection.
     *
     * @param mixed $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);

    /**
     * Получение удалённого IP.
     *
     * @return string
     */
    abstract public function getRemoteIp();

    /**
     * Получение удалённого порта.
     *
     * @return int
     */
    abstract public function getRemotePort();

    /**
     * Получение удалённого адреса.
     *
     * @return string
     */
    abstract public function getRemoteAddress();

    /**
     * Получение локального IP.
     *
     * @return string
     */
    abstract public function getLocalIp();

    /**
     * Получение локального порта.
     *
     * @return int
     */
    abstract public function getLocalPort();

    /**
     * Получение локального адреса.
     *
     * @return string
     */
    abstract public function getLocalAddress();

    /**
     * Проверка ipv4.
     *
     * @return bool
     */
    abstract public function isIPv4();

    /**
     * Проверка ipv6.
     *
     * @return bool
     */
    abstract public function isIPv6();

    /**
     * Закрытие соединения.
     *
     * @param string|null $data
     * @return void
     */
    abstract public function close($data = null);
}
