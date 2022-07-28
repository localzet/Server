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

use localzet\Core\Events\EventInterface;
use localzet\Core\Server;
use \Exception;

/**
 * AsyncUdpConnection.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Задаётся, когда подключение к сокетам успешно установлено.
     *
     * @var callable
     */
    public $onConnect = null;

    /**
     * Задаётся, когда подключение к сокетам закрыто.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Подключен или нет.
     *
     * @var bool
     */
    protected $connected = false;

    /**
     * Контекст.
     *
     * @var array
     */
    protected $_contextOption = null;

    /**
     * @param string $remote_address
     * @throws Exception
     */
    public function __construct($remote_address, $context_option = null)
    {
        // Получить протокол связи приложений и адрес прослушивания.
        list($scheme, $address) = \explode(':', $remote_address, 2);
        // Проверяем класс протокола приложения.
        if ($scheme !== 'udp') {
            $scheme         = \ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Core\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }

        $this->_remoteAddress = \substr($address, 2);
        $this->_contextOption = $context_option;
    }

    /**
     * Для пакета UDP.
     *
     * @param resource $socket
     * @return bool
     */
    public function baseRead($socket)
    {
        $recv_buffer = \stream_socket_recvfrom($socket, Server::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }

        if ($this->onMessage) {
            if ($this->protocol) {
                $parser      = $this->protocol;
                $recv_buffer = $parser::decode($recv_buffer, $this);
            }
            ++ConnectionInterface::$statistics['total_request'];
            try {
                \call_user_func($this->onMessage, $this, $recv_buffer);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Отправляет данные на соединение.
     *
     * @param string $send_buffer
     * @param bool   $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }
        if ($this->connected === false) {
            $this->connect();
        }
        return \strlen($send_buffer) === \stream_socket_sendto($this->_socket, $send_buffer, 0);
    }


    /**
     * Закрытие соединения
     *
     * @param mixed $data
     * @param bool $raw
     *
     * @return bool
     */
    public function close($data = null, $raw = false)
    {
        // Если есть что сказать - скажи сейчас
        if ($data !== null) {
            $this->send($data, $raw);
        }

        // Удаляем из событий на чтение и закрываем стрим
        Server::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        \fclose($this->_socket);
        $this->connected = false;

        // Попытка вызвать onClose
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
        $this->onConnect = $this->onMessage = $this->onClose = null;
        return true;
    }

    /**
     * Соединение
     *
     * @return void
     */
    public function connect()
    {
        // Если соединение уже есть не нужно подключаться дважды
        if ($this->connected === true) {
            return;
        }

        // Если есть контекст - используем его
        // Если нет - просто слушаем стрим
        if ($this->_contextOption) {
            $context = \stream_context_create($this->_contextOption);
            $this->_socket = \stream_socket_client(
                "udp://{$this->_remoteAddress}",
                $errno,
                $errmsg,
                30,
                \STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            $this->_socket = \stream_socket_client("udp://{$this->_remoteAddress}", $errno, $errmsg);
        }

        // Обрабатываем исключение
        if (!$this->_socket) {
            Server::safeEcho(new \Exception($errmsg));
            return;
        }

        // Отключаем блокировку стрима (non-blocking mode)
        // Так мы сможем получать данные сразу без ожиданий
        \stream_set_blocking($this->_socket, false);

        // Если есть обработчик - добавляем событие
        // Если нет - не тратим время
        if ($this->onMessage) {
            Server::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        }

        // Соединено
        $this->connected = true;

        // Попытка вызвать onConnect
        if ($this->onConnect) {
            try {
                \call_user_func($this->onConnect, $this);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
    }
}
