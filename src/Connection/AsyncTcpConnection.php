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
use localzet\Core\Lib\Timer;
use localzet\Core\Server;
use \Exception;

/**
 * AsyncTcpConnection.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * Задаётся, когда подключение к сокетам успешно установлено.
     *
     * @var callable|null
     */
    public $onConnect = null;

    /**
     * Протокол транспортного слоя.
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Статус.
     *
     * @var int
     */
    protected $_status = self::STATUS_INITIAL;

    /**
     * Удаленный хост.
     *
     * @var string
     */
    protected $_remoteHost = '';

    /**
     * Удалённый порт.
     *
     * @var int
     */
    protected $_remotePort = 80;

    /**
     * Время начала подключения.
     *
     * @var float
     */
    protected $_connectStartTime = 0;

    /**
     * Удалённый URI.
     *
     * @var string
     */
    protected $_remoteURI = '';

    /**
     * Контекст.
     *
     * @var array
     */
    protected $_contextOption = null;

    /**
     * Таймер реконнекта.
     *
     * @var int
     */
    protected $_reconnectTimer = null;


    /**
     * Встроенные PHP-протоколы.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls'   => 'tls'
    );

    /**
     * @param string $remote_address
     * @param array $context_option
     * @throws Exception
     */
    public function __construct($remote_address, array $context_option = array())
    {
        $address_info = \parse_url($remote_address);
        if (!$address_info) {
            list($scheme, $this->_remoteAddress) = \explode(':', $remote_address, 2);
            if ('unix' === strtolower($scheme)) {
                $this->_remoteAddress = substr($remote_address, strpos($remote_address, '/') + 2);
            }
            if (!$this->_remoteAddress) {
                Server::safeEcho(new \Exception('bad remote_address'));
            }
        } else {
            if (!isset($address_info['port'])) {
                $address_info['port'] = 0;
            }
            if (!isset($address_info['path'])) {
                $address_info['path'] = '/';
            }
            if (!isset($address_info['query'])) {
                $address_info['query'] = '';
            } else {
                $address_info['query'] = '?' . $address_info['query'];
            }
            $this->_remoteHost    = $address_info['host'];
            $this->_remotePort    = $address_info['port'];
            $this->_remoteURI     = "{$address_info['path']}{$address_info['query']}";
            $scheme               = isset($address_info['scheme']) ? $address_info['scheme'] : 'tcp';
            $this->_remoteAddress = 'unix' === strtolower($scheme)
                ? substr($remote_address, strpos($remote_address, '/') + 2)
                : $this->_remoteHost . ':' . $this->_remotePort;
        }

        $this->id = $this->_id = self::$_idRecorder++;
        if (\PHP_INT_MAX === self::$_idRecorder) {
            self::$_idRecorder = 0;
        }
        // Check application layer protocol class.
        if (!isset(self::$_builtinTransports[$scheme])) {
            $scheme         = \ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Core\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::$_builtinTransports[$scheme];
        }

        // For statistics.
        ++self::$statistics['connection_count'];
        $this->maxSendBufferSize         = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize            = self::$defaultMaxPackageSize;
        $this->_contextOption            = $context_option;
        static::$connections[$this->_id] = $this;
    }

    /**
     * Do connect.
     *
     * @return void
     */
    public function connect()
    {
        if (
            $this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
            $this->_status !== self::STATUS_CLOSED
        ) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = \microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->_remotePort) {
                $this->_remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->_remoteAddress = $this->_remoteHost . ':' . $this->_remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->_contextOption) {
                $context = \stream_context_create($this->_contextOption);
                $this->_socket = \stream_socket_client(
                    "tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno,
                    $errstr,
                    0,
                    \STREAM_CLIENT_ASYNC_CONNECT,
                    $context
                );
            } else {
                $this->_socket = \stream_socket_client(
                    "tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno,
                    $errstr,
                    0,
                    \STREAM_CLIENT_ASYNC_CONNECT
                );
            }
        } else {
            $this->_socket = \stream_socket_client(
                "{$this->transport}://{$this->_remoteAddress}",
                $errno,
                $errstr,
                0,
                \STREAM_CLIENT_ASYNC_CONNECT
            );
        }
        // If failed attempt to emit onError callback.
        if (!$this->_socket || !\is_resource($this->_socket)) {
            $this->emitError(\CORE_CONNECT_FAIL, $errstr);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or faild.
        Server::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
        // Для винды
        if (\DIRECTORY_SEPARATOR === '\\') {
            Server::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnection'));
        }
    }

    /**
     * Переподключение.
     *
     * @param int $after
     * @return void
     */
    public function reconnect($after = 0)
    {
        $this->_status                   = self::STATUS_INITIAL;
        static::$connections[$this->_id] = $this;
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
        if ($after > 0) {
            $this->_reconnectTimer = Timer::add($after, array($this, 'connect'), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Отмена переподключения.
     */
    public function cancelReconnect()
    {
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
    }

    /**
     * Получение удалённого хоста.
     *
     * @return string
     */
    public function getRemoteHost()
    {
        return $this->_remoteHost;
    }

    /**
     * Получение удалённого URI.
     *
     * @return string
     */
    public function getRemoteURI()
    {
        return $this->_remoteURI;
    }

    /**
     * Попытка вызвать onError.
     *
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected function emitError($code, $msg)
    {
        // Статус: закрытие соединения
        $this->_status = self::STATUS_CLOSING;

        // Если onError вообще задан
        if ($this->onError) {
            // Попытка вызова
            // Не получится - останавливай всё и логируй исключение/ошибку
            try {
                \call_user_func($this->onError, $this, $code, $msg);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
    }

    /**
     * Проверка соединения.
     *
     * @param resource $socket
     * @return void
     */
    public function checkConnection()
    {
        // Удалите EV_EXPEPE для Windows.
        if (\DIRECTORY_SEPARATOR === '\\') {
            Server::$globalEvent->del($this->_socket, EventInterface::EV_EXCEPT);
        }

        // Remove write listener.
        Server::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        if ($this->_status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = \stream_socket_get_name($this->_socket, true)) {
            // Nonblocking.
            \stream_set_blocking($this->_socket, false);
            // Compatible with hhvm
            if (\function_exists('stream_set_read_buffer')) {
                \stream_set_read_buffer($this->_socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = \socket_import_stream($this->_socket);
                \socket_set_option($raw_socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($raw_socket, \SOL_TCP, \TCP_NODELAY, 1);
            }

            // SSL handshake.
            if ($this->transport === 'ssl') {
                $this->_sslHandshakeCompleted = $this->doSslHandshake($this->_socket);
                if ($this->_sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->_sendBuffer) {
                    Server::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            }

            // Register a listener waiting read event.
            Server::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));

            $this->_status                = self::STATUS_ESTABLISHED;
            $this->_remoteAddress         = $address;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    \call_user_func($this->onConnect, $this);
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
            // Try to emit protocol::onConnect
            if ($this->protocol && \method_exists($this->protocol, 'onConnect')) {
                try {
                    \call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(\CORE_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(\microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
