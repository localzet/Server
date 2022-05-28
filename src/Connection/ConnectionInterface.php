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

namespace localzet\Core\Connection;

use \localzet\Core\Protocols\ProtocolInterface;
use \localzet\Core\Protocols\Http\Response;

/**
 * ConnectionInterface.
 */
abstract class ConnectionInterface
{
    /**
     * Статистика для команды статуса
     *
     * @var array
     */
    public static array $statistics = array(
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
     * Задаётся, когда буфер отправки заполняется.
     *
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * Задаётся, когда буфер отправки становится пустым.
     *
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * Протокол прикладного уровня
     *
     * @var ProtocolInterface
     */
    public $protocol = null;

    /**
     * Протокол транспортного уровня (tcp/udp/unix/ssl)
     *
     * @var string
     */
    public $transport = null;

    /**
     * Сокет
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Удалённый адрес
     *
     * @var string
     */
    protected string $_remoteAddress = '';

    /**
     * @param resource $socket
     * @param string   $remote_address
     */
    abstract public function __construct($socket, string $remote_address = '');

    /**
     * Отправляет данные на соединение.
     *
     * @param string|Response $send_buffer
     * @return void|bool
     */
    abstract public function send(string|Response $send_buffer): bool;

    /**
     * Получение удалённого IP.
     *
     * @return string
     */
    abstract public function getRemoteIp(): string;

    /**
     * Получение удалённого порта.
     *
     * @return int
     */
    abstract public function getRemotePort(): int;

    /**
     * Получение удалённого адреса.
     *
     * @return string
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Получение локального IP.
     *
     * @return string
     */
    abstract public function getLocalIp(): string;

    /**
     * Получение локального порта.
     *
     * @return int
     */
    abstract public function getLocalPort(): int;

    /**
     * Получение локального адреса.
     *
     * @return string
     */
    abstract public function getLocalAddress(): string;

    /**
     * Проверка ipv4.
     *
     * @return bool
     */
    abstract public function isIPv4(): bool;

    /**
     * Проверка ipv6.
     *
     * @return bool
     */
    abstract public function isIPv6(): bool;

    /**
     * Закрытие соединения.
     *
     * @param string|null $data
     * @return void
     */
    abstract public function close(string|null $data = null): void;

    /**
     * Получение сокета
     *
     * @return resource
     */
    abstract public function getSocket();
}
