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

use \localzet\Core\Protocols\Http\Response;
use localzet\Core\Events\EventInterface;
use localzet\Core\Server;
use \Exception;

/**
 * TcpConnection.
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * Размер буфера чтения
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;

    /**
     * Статус: Инициализация
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * Статус: Соединение
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * Статус: Соединение установлено
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * Статус: Закрытие
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * Статус: Закрыто
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * Текущий сервер
     *
     * @var Server
     */
    public $server = null;

    /**
     * Считанные байты
     *
     * @var int
     */
    public $bytesRead = 0;

    /**
     * Записанные байты
     *
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * Connection->id
     *
     * @var int
     */
    public $id = 0;

    /**
     * Копия $server->id который использовался для очистки $server->connections
     *
     * @var int
     */
    protected int $_id = 0;

    /**
     * Максимальный размер буфера отправки для текущего соединения
     * Когда буфер заполнится будет вызван OnBufferFull
     *
     * @var int
     */
    public $maxSendBufferSize = 1048576;

    /**
     * Стандартный размер буфера отправки
     *
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * Sets the maximum acceptable packet size for the current connection.
     *
     * @var int
     */
    public $maxPackageSize = 1048576;

    /**
     * Максимально приемлемый размер пакета по умолчанию
     *
     * @var int
     */
    public static $defaultMaxPackageSize = 10485760;

    /**
     * ID Регистратор
     *
     * @var int
     */
    protected static int $_idRecorder = 1;

    /**
     * Буфер отправки
     *
     * @var string
     */
    protected string $_sendBuffer = '';

    /**
     * Буфер получения
     *
     * @var string
     */
    protected string $_recvBuffer = '';

    /**
     * Длина текущего пакета
     *
     * @var int
     */
    protected int $_currentPackageLength = 0;

    /**
     * Статус соединения
     *
     * @var int
     */
    protected int $_status = self::STATUS_ESTABLISHED;

    /**
     * Пауза?
     *
     * @var bool
     */
    protected bool $_isPaused = false;

    /**
     * SSL-handshake?
     *
     * @var bool
     */
    protected bool $_sslHandshakeCompleted = false;

    /**
     * Все экземпляры соеденения
     *
     * @var array
     */
    public static array $connections = array();

    /**
     * Статус в строку
     *
     * @var array
     */
    public static array $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING     => 'CLOSING',
        self::STATUS_CLOSED      => 'CLOSED',
    );

    /**
     * {@inheritdoc}
     */
    public $transport = 'tcp';

    /**
     * {@inheritdoc}
     */
    public function __construct($socket, string $remote_address = '')
    {
        ++self::$statistics['connection_count'];
        $this->id = $this->_id = self::$_idRecorder++;
        if (self::$_idRecorder === \PHP_INT_MAX) {
            self::$_idRecorder = 0;
        }
        $this->_socket = $socket;
        \stream_set_blocking($this->_socket, 0);
        // Compatible with hhvm
        if (\function_exists('stream_set_read_buffer')) {
            \stream_set_read_buffer($this->_socket, 0);
        }
        Server::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize           = self::$defaultMaxPackageSize;
        $this->_remoteAddress           = $remote_address;
        static::$connections[$this->id] = $this;
    }

    /**
     * {@inheritdoc}
     */
    public function send($send_buffer, bool $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }

        if (
            $this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return;
        }

        // Attempt to send data directly.
        if ($this->_sendBuffer === '') {
            if ($this->transport === 'ssl') {
                Server::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                return;
            }
            $len = 0;
            try {
                $len = @\fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {
                Server::log($e);
            } catch (\Error $e) {
                Server::log($e);
            }
            // send successful.
            if ($len === \strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->_sendBuffer = \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!\is_resource($this->_socket) || \feof($this->_socket)) {
                    ++self::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, \V3_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Server::stopAll(250, $e);
                        } catch (\Error $e) {
                            Server::stopAll(250, $e);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            Server::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->_sendBuffer .= $send_buffer;
        // Check if the send buffer is full.
        $this->checkBufferWillFull();
    }

    /**
     * {@inheritdoc}
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return (string) \substr($this->_remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int) \substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalAddress()
    {
        if (!\is_resource($this->_socket)) {
            return '';
        }
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * {@inheritdoc}
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * {@inheritdoc}
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function close($data = null, $raw = false)
    {
        if ($this->_status === self::STATUS_CONNECTING) {
            $this->destroy();
            return;
        }

        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->_status = self::STATUS_CLOSING;

        if ($this->_sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * Получение статуса.
     *
     * @param bool $raw_output
     *
     * @return int|string
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        return self::$_statusToString[$this->_status];
    }

    /**
     * Получить размер буфера отправки
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return \strlen($this->_sendBuffer);
    }

    /**
     * Получить размер очереди буфера получения
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return \strlen($this->_recvBuffer);
    }

    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv()
    {
        Server::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        $this->_isPaused = true;
    }

    /**
     * Возобновление чтения после вызова pauseRecv
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            Server::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            $this->baseRead($this->_socket, false);
        }
    }

    /**
     * Базовый обработчик чтения
     *
     * @param resource $socket
     * @param bool $check_eof
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL-handshake
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                if ($this->_sendBuffer) {
                    Server::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Exception $e) {
        } catch (\Error $e) {
        }

        // Проверка закрытия соединения
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += \strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }

        // If the application layer protocol has been set up.
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // The current packet length is known.
                if ($this->_currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    } catch (\Exception $e) {
                    } catch (\Error $e) {
                    }
                    // The packet length is unknown.
                    if ($this->_currentPackageLength === 0) {
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        Server::safeEcho('Error package. package_length=' . \var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                if (\strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    // Get a full package from the buffer.
                    $one_request_buffer = \substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->_recvBuffer = \substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->_currentPackageLength = 0;
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    // Decode request buffer before Emitting onMessage callback.
                    \call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // Applications protocol is not set.
        ++self::$statistics['total_request'];
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        try {
            \call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Server::stopAll(250, $e);
        } catch (\Error $e) {
            Server::stopAll(250, $e);
        }
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }

    /**
     * Базовый обработчик записи
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        // Обработчик ошибок... пока не до тебя
        \set_error_handler(function () {
        });

        // Если это SSL - ограничим длину
        if ($this->transport === 'ssl') {
            $len = @\fwrite($this->_socket, $this->_sendBuffer, 8192);
        } else {
            $len = @\fwrite($this->_socket, $this->_sendBuffer);
        }

        // А теперь восстанавливаем прежний обработчик))
        \restore_error_handler();

        // Следим за буфером
        if ($len === \strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            Server::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // Попытка вызвать onBufferDrain когда буфер отправки пустой
            if ($this->onBufferDrain) {
                try {
                    \call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer = \substr($this->_sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * SSL-handshake
     *
     * @param resource $socket
     * @return bool
     */
    public function doSslHandshake($socket)
    {
        if (\feof($socket)) {
            $this->destroy();
            return false;
        }
        $async = $this instanceof AsyncTcpConnection;

        /**
         *  Поддержка SSL3 отключена. Подробнее: https://blog.qualys.com/ssllabs/2014/10/15/ssl-3-is-dead-killed-by-the-poodle-attack.
         *  Лучше не включать, но пусть это побудет здесь
         */
        /*if($async){
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
        }else{
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER | STREAM_CRYPTO_METHOD_SSLv3_SERVER;
        }*/

        if ($async) {
            $type = \STREAM_CRYPTO_METHOD_SSLv2_CLIENT | \STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        } else {
            $type = \STREAM_CRYPTO_METHOD_SSLv2_SERVER | \STREAM_CRYPTO_METHOD_SSLv23_SERVER;
        }

        // Обработчик ошибок с SSL
        \set_error_handler(function ($errno, $errstr, $file) {
            if (!Server::$daemonize) {
                Server::safeEcho("SSL-handshake: $errstr \n");
            }
        });
        $ret = \stream_socket_enable_crypto($socket, true, $type);
        \restore_error_handler();

        // Соединение прервалось
        if (false === $ret) {
            $this->destroy();
            return false;
        } elseif (0 === $ret) {
            // Там недостаточно данных, мы должны попробовать еще раз
            return 0;
        }
        if (isset($this->onSslHandshake)) {
            try {
                \call_user_func($this->onSslHandshake, $this);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Этот метод вытаскивает все данные из читаемого потока и записывает их в указанное место назначения.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest)
    {
        $source = $this;
        $this->onMessage = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose = function ($source) use ($dest) {
            $dest->close();
        };
        $dest->onBufferFull = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * Удаление $length данных из буфера чтения
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = \substr($this->_recvBuffer, $length);
    }

    /**
     * Проверка заполнения буфера отправки
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    \call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
        }
    }

    /**
     * Заполнен ли буфер отправки?
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    \call_user_func($this->onError, $this, \V3_SEND_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Server::stopAll(250, $e);
                } catch (\Error $e) {
                    Server::stopAll(250, $e);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Пуст ли буфер отправки?
     *
     * @return bool
     */
    public function bufferIsEmpty()
    {
        return empty($this->_sendBuffer);
    }

    /**
     * Разорвать соединение
     *
     * @return void
     */
    public function destroy()
    {
        // Избегаем повторяющихся вызовов
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }

        // Удаляем обработчик события
        Server::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Server::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        // Закрытие сокета
        try {
            @\fclose($this->_socket);
        } catch (\Exception $e) {
        } catch (\Error $e) {
        }

        $this->_status = self::STATUS_CLOSED;

        // Попытка вызова onClose
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }
        
        // Попытка вызова protocol::onClose
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                \call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Server::stopAll(250, $e);
            } catch (\Error $e) {
                Server::stopAll(250, $e);
            }
        }

        $this->_sendBuffer = $this->_recvBuffer = '';
        $this->_currentPackageLength = 0;
        $this->_isPaused = $this->_sslHandshakeCompleted = false;
        if ($this->_status === self::STATUS_CLOSED) {
            // Очищаем обработчик, чтобы избежать утечки памяти
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            // Удаляем соединение из server->connections.
            if ($this->server) {
                unset($this->server->connections[$this->_id]);
            }
            unset(static::$connections[$this->_id]);
        }
    }

    /**
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Server::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = \ceil((self::$statistics['connection_count'] + 1) / 3);
            }

            if (0 === self::$statistics['connection_count'] % $mod) {
                Server::log('Сервер [' . \posix_getpid() . ']: осталось ' . self::$statistics['connection_count'] . ' соединений');
            }

            if (0 === self::$statistics['connection_count']) {
                Server::stopAll();
            }
        }
    }
}
