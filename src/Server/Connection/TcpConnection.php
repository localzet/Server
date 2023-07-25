<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Connection;

use JetBrains\PhpStorm\Pure;
use JsonSerializable;
use localzet\Server;
use localzet\Server\Events\EventInterface;
use localzet\Server\Protocols\{Http\Request, ProtocolInterface, Ws};
use stdClass;
use Throwable;
use function ceil;
use function count;
use function fclose;
use function feof;
use function fread;
use function function_exists;
use function fwrite;
use function is_object;
use function is_resource;
use function key;
use function posix_getpid;
use function restore_error_handler;
use function set_error_handler;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function var_export;
use const PHP_INT_MAX;
use const STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
use const STREAM_CRYPTO_METHOD_SSLv23_SERVER;
use const STREAM_CRYPTO_METHOD_SSLv2_CLIENT;
use const STREAM_CRYPTO_METHOD_SSLv2_SERVER;

/**
 * TCP-соединение.
 * @property string $websocketType
 */
class TcpConnection extends ConnectionInterface implements JsonSerializable
{
    /**
     * Размер буфера чтения.
     *
     * @var int
     */
    public const READ_BUFFER_SIZE = 87380;

    /**
     * Начальный статус.
     *
     * @var int
     */
    public const STATUS_INITIAL = 0;

    /**
     * Статус соединения в процессе установки.
     *
     * @var int
     */
    public const STATUS_CONNECTING = 1;

    /**
     * Статус установленного соединения.
     *
     * @var int
     */
    public const STATUS_ESTABLISHED = 2;

    /**
     * Статус закрытия соединения.
     *
     * @var int
     */
    public const STATUS_CLOSING = 4;

    /**
     * Статус закрытого соединения.
     *
     * @var int
     */
    public const STATUS_CLOSED = 8;

    /**
     * Массив для преобразования статуса в строковое представление.
     *
     * @var array
     */
    public const STATUS_TO_STRING = [
        self::STATUS_INITIAL => 'INITIAL',
        self::STATUS_CONNECTING => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING => 'CLOSING',
        self::STATUS_CLOSED => 'CLOSED',
    ];

    /**
     * Размер буфера отправки по умолчанию.
     *
     * @var int
     */
    public static int $defaultMaxSendBufferSize = 1048576;

    /**
     * Максимальный допустимый размер пакета по умолчанию.
     *
     * @var int
     */
    public static int $defaultMaxPackageSize = 10485760;

    /**
     * Массив всех экземпляров соединения.
     *
     * @var array
     */
    public static array $connections = [];

    /**
     * Идентификатор записывателя.
     *
     * @var int
     */
    protected static int $idRecorder = 1;

    /**
     * Кэш.
     *
     * @var bool
     */
    protected static bool $enableCache = true;

    /**
     * Событие, возникающее при успешном установлении сокетного соединения.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Событие, возникающее после успешного завершения рукопожатия WebSocket (работает только для протокола ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Событие, возникающее при получении данных.
     *
     * @var ?callable
     */
    public $onMessage = null;

    /**
     * Событие, возникающее при получении пакета FIN от другого конца сокета.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Событие, возникающее при возникновении ошибки в соединении.
     *
     * @var ?callable
     */
    public $onError = null;

    /**
     * Событие, возникающее при заполнении отправочного буфера.
     *
     * @var ?callable
     */
    public $onBufferFull = null;

    /**
     * Событие, возникающее при опустошении отправочного буфера.
     *
     * @var ?callable
     */
    public $onBufferDrain = null;

    /**
     * Транспорт (tcp/udp/unix/ssl).
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * К какому серверу принадлежит соединение.
     *
     * @var ?Server
     */
    public ?Server $server = null;

    /**
     * Прочитанные байты.
     *
     * @var int
     */
    public int $bytesRead = 0;

    /**
     * Записанные байты.
     *
     * @var int
     */
    public int $bytesWritten = 0;

    /**
     * Идентификатор соединения.
     *
     * @var int
     */
    public int $id = 0;

    /**
     * Задает максимальный размер отправочного буфера для текущего соединения.
     * Событие onBufferFull будет возникать, когда буфер отправки будет полон.
     *
     * @var int
     */
    public int $maxSendBufferSize = 1048576;

    /**
     * Контекст.
     *
     * @var ?stdClass
     */
    public ?stdClass $context = null;

    /**
     * Заголовки.
     *
     * @var array
     */
    public array $headers = [];

    /**
     * Запрос.
     *
     * @var ?Request
     */
    public ?Request $request = null;

    /**
     * Задает максимальный допустимый размер пакета для текущего соединения.
     *
     * @var int
     */
    public int $maxPackageSize = 1048576;

    /**
     * Копия $server->id, используется для очистки соединения в $server->connections.
     *
     * @var int
     */
    protected int $realId = 0;

    /**
     * Сокет.
     *
     * @var resource
     */
    protected $socket = null;

    /**
     * Буфер отправки.
     *
     * @var string
     */
    protected string $sendBuffer = '';

    /**
     * Буфер приема.
     *
     * @var string
     */
    protected string $recvBuffer = '';

    /**
     * Длина текущего пакета.
     *
     * @var int
     */
    protected int $currentPackageLength = 0;

    /**
     * Статус соединения.
     *
     * @var int
     */
    protected int $status = self::STATUS_ESTABLISHED;

    /**
     * Удаленный адрес.
     *
     * @var string
     */
    protected string $remoteAddress = '';

    /**
     * Соединение приостановлено?
     *
     * @var bool
     */
    protected bool $isPaused = false;

    /**
     * SSL-рукопожатие совержено?
     *
     * @var bool
     */
    protected bool|int $sslHandshakeCompleted = false;

    /**
     * Конструктор.
     *
     * @param EventInterface $eventLoop
     * @param resource $socket
     * @param string $remoteAddress
     */
    public function __construct(EventInterface $eventLoop, $socket, string $remoteAddress = '')
    {
        ++self::$statistics['connection_count'];
        $this->id = $this->realId = self::$idRecorder++;
        if (self::$idRecorder === PHP_INT_MAX) {
            self::$idRecorder = 0;
        }
        $this->socket = $socket;
        stream_set_blocking($this->socket, false);
        // Совместимость с hhvm
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->socket, 0);
        }
        $this->eventLoop = $eventLoop;
        $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->remoteAddress = $remoteAddress;
        static::$connections[$this->id] = $this;
        $this->context = new stdClass;
    }

    /**
     * Включение или отключение кэша.
     *
     * @param bool $value
     */
    public static function enableCache(bool $value = true): void
    {
        static::$enableCache = $value;
    }

    /**
     * Получить размер очереди буфера отправки.
     *
     * @return integer
     */
    public function getSendBufferQueueSize(): int
    {
        return strlen($this->sendBuffer);
    }

    /**
     * Получить размер очереди буфера приема.
     *
     * @return integer
     */
    public function getRecvBufferQueueSize(): int
    {
        return strlen($this->recvBuffer);
    }

    /**
     * Основной обработчик записи.
     *
     * @return void
     * @throws Throwable
     */
    public function baseWrite(): void
    {
        $len = 0;
        try {
            if ($this->transport === 'ssl') {
                $len = @fwrite($this->socket, $this->sendBuffer, 8192);
            } else {
                $len = @fwrite($this->socket, $this->sendBuffer);
            }
        } catch (Throwable) {
        }
        if ($len === strlen($this->sendBuffer)) {
            $this->bytesWritten += $len;
            $this->eventLoop->offWritable($this->socket);
            $this->sendBuffer = '';
            // Попытка вызвать обратный вызов onBufferDrain, когда буфер отправки становится пустым.
            if ($this->onBufferDrain) {
                try {
                    ($this->onBufferDrain)($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            if ($this->status === self::STATUS_CLOSING) {
                if (!empty($this->context->streamSending)) {
                    return;
                }
                $this->destroy();
            }
            return;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->sendBuffer = substr($this->sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * Уничтожить соединение.
     *
     * @return void
     * @throws Throwable
     */
    public function destroy(): void
    {
        // Избежать повторных вызовов.
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        // Удалить обработчик событий.
        $this->eventLoop->offReadable($this->socket);
        $this->eventLoop->offWritable($this->socket);

        // Закрыть сокет.
        try {
            @fclose($this->socket);
        } catch (Throwable) {
        }

        $this->status = self::STATUS_CLOSED;
        // Попытка вызвать обратный вызов onClose.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        // Попытка вызвать protocol::onClose
        if ($this->protocol && $this->protocol instanceof Ws) {
            try {
                $this->protocol::onClose($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        $this->sendBuffer = $this->recvBuffer = '';
        $this->currentPackageLength = 0;
        $this->isPaused = $this->sslHandshakeCompleted = false;
        if ($this->status === self::STATUS_CLOSED) {
            // Очистка обратного вызова для предотвращения утечек памяти.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = $this->eventLoop = $this->errorHandler = null;
            // Удаление из server->connections.
            if ($this->server) {
                unset($this->server->connections[$this->realId]);
            }
            unset(static::$connections[$this->realId]);
        }
    }

    /**
     * Метод pipe() позволяет установить канал передачи данных между текущим соединением и другим соединением (dest).
     * Входящие данные из текущего соединения будут отправлены на соединение dest.
     * Этот метод используется для перенаправления данных между соединениями.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest): void
    {
        $source = $this;
        $this->onMessage = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose = function () use ($dest) {
            $dest->close();
        };
        $dest->onBufferFull = function () use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function () use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function send(mixed $sendBuffer, bool $raw = false)
    {
        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return false;
        }

        // Попытка вызвать protocol::encode($sendBuffer) перед отправкой.
        if (false === $raw && $this->protocol !== null) {
            /** @var ProtocolInterface $parser */
            $parser = $this->protocol;
            $sendBuffer = $parser::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return;
            }
        }

        // Если соединение еще не установлено или еще не завершено SSL-рукопожатие.
        if (
            $this->status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->sslHandshakeCompleted !== true)
        ) {
            if ($this->sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->sendBuffer .= $sendBuffer;
            $this->checkBufferWillFull();
            return;
        }

        // Попытка отправить данные напрямую.
        if ($this->sendBuffer === '') {
            if ($this->transport === 'ssl') {
                $this->eventLoop->onWritable($this->socket, [$this, 'baseWrite']);
                $this->sendBuffer = $sendBuffer;
                $this->checkBufferWillFull();
                return;
            }
            $len = 0;
            try {
                $len = @fwrite($this->socket, $sendBuffer);
            } catch (Throwable $e) {
                Server::log($e);
            }
            // Отправка успешна.
            if ($len === strlen($sendBuffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Отправить только часть данных.
            if ($len > 0) {
                $this->sendBuffer = substr($sendBuffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Соединение закрыто?
                if (!is_resource($this->socket) || feof($this->socket)) {
                    ++self::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            ($this->onError)($this, static::SEND_FAIL, 'client closed');
                        } catch (Throwable $e) {
                            $this->error($e);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->sendBuffer = $sendBuffer;
            }
            $this->eventLoop->onWritable($this->socket, [$this, 'baseWrite']);
            // Проверка, будет ли буфер отправки заполнен.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->sendBuffer .= $sendBuffer;
        // Проверка, будет ли буфер отправки заполнен.
        $this->checkBufferWillFull();
    }

    /**
     * Метод bufferIsFull() используется для проверки заполненности буфера отправки.
     *
     * @return bool
     * @throws Throwable
     */
    protected function bufferIsFull(): bool
    {
        // Если буфер был помечен как заполненный, но еще есть данные для отправки, пакет отбрасывается.
        if ($this->maxSendBufferSize <= strlen($this->sendBuffer)) {
            if ($this->onError) {
                try {
                    ($this->onError)($this, static::SEND_FAIL, 'send buffer full and drop package');
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Метод checkBufferWillFull() используется для проверки заполнения буфера отправки.
     *
     * @return void
     * @throws Throwable
     */
    protected function checkBufferWillFull(): void
    {
        if ($this->onBufferFull && $this->maxSendBufferSize <= strlen($this->sendBuffer)) {
            try {
                ($this->onBufferFull)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($this->status === self::STATUS_CONNECTING) {
            $this->destroy();
            return;
        }

        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->status = self::STATUS_CLOSING;

        if ($this->sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }

    /**
     * Приостанавливает чтение данных. Это означает, что onMessage не будет вызван. Полезно для снижения нагрузки при загрузке данных.
     *
     * @return void
     */
    public function pauseRecv(): void
    {
        $this->eventLoop->offReadable($this->socket);
        $this->isPaused = true;
    }

    /**
     * Возобновляет чтение данных после вызова pauseRecv.
     *
     * @return void
     * @throws Throwable
     */
    public function resumeRecv(): void
    {
        if ($this->isPaused === true) {
            $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
            $this->isPaused = false;
            $this->baseRead($this->socket, false);
        }
    }

    /**
     * Основной обработчик чтения.
     *
     * @param resource $socket
     * @param bool $checkEof
     * @return void
     * @throws Throwable
     */
    public function baseRead($socket, bool $checkEof = true): void
    {
        static $requests = [];
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->sslHandshakeCompleted = true;
                if ($this->sendBuffer) {
                    $this->eventLoop->onWritable($socket, [$this, 'baseWrite']);
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @fread($socket, self::READ_BUFFER_SIZE);
        } catch (Throwable) {
        }

        // Проверка закрытия соединения.
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += strlen($buffer);
            if ($this->recvBuffer === '') {
                if (static::$enableCache && !isset($buffer[512]) && isset($requests[$buffer])) {
                    ++self::$statistics['total_request'];
                    $request = $requests[$buffer];
                    if ($request instanceof Request) {
                        $request = clone $request;
                        $requests[$buffer] = $request;
                        $request->connection = $this;
                        $this->request = $request;
                        $request->properties = [];
                    }
                    try {
                        ($this->onMessage)($this, $request);
                    } catch (Throwable $e) {
                        $this->error($e);
                    }
                    return;
                }
                $this->recvBuffer = $buffer;
            } else {
                $this->recvBuffer .= $buffer;
            }
        }

        // Если протокол прикладного уровня был установлен.
        if ($this->protocol !== null) {
            while ($this->recvBuffer !== '' && !$this->isPaused) {
                // Длина текущего пакета известна.
                if ($this->currentPackageLength) {
                    // Данных недостаточно для пакета.
                    if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                        break;
                    }
                } else {
                    // Получить текущую длину пакета.
                    try {
                        /** @var ProtocolInterface $parser */
                        $parser = $this->protocol;
                        $this->currentPackageLength = $parser::input($this->recvBuffer, $this);
                    } catch (Throwable) {
                    }
                    // Длина пакета неизвестна.
                    if ($this->currentPackageLength === 0) {
                        break;
                    } elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) {
                        // Данных недостаточно для пакета.
                        if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                            break;
                        }
                    } // Неверный пакет.
                    else {
                        Server::safeEcho('Ошибка пакета. package_length=' . var_export($this->currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                // Данных достаточно для пакета.
                ++self::$statistics['total_request'];
                // Длина текущего пакета равна длине буфера.
                if ($one = (strlen($this->recvBuffer) === $this->currentPackageLength)) {
                    $oneRequestBuffer = $this->recvBuffer;
                    $this->recvBuffer = '';
                } else {
                    // Получить полный пакет из буфера.
                    $oneRequestBuffer = substr($this->recvBuffer, 0, $this->currentPackageLength);
                    // Удалить текущий пакет из буфера чтения.
                    $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
                }
                // Сбросить текущую длину пакета на 0.
                $this->currentPackageLength = 0;
                try {
                    // Декодировать буфер запроса перед вызовом обратного вызова onMessage.
                    /** @var ProtocolInterface $parser */
                    $parser = $this->protocol;
                    $request = $parser::decode($oneRequestBuffer, $this);
                    if (static::$enableCache && (!is_object($request) || $request instanceof Request) && $one && !isset($oneRequestBuffer[512])) {
                        $requests[$oneRequestBuffer] = $request;
                        if (count($requests) > 512) {
                            unset($requests[key($requests)]);
                        }
                    }
                    ($this->onMessage)($this, $request);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            return;
        }

        if ($this->recvBuffer === '' || $this->isPaused) {
            return;
        }

        // Протокол приложения не установлен.
        ++self::$statistics['total_request'];
        try {
            ($this->onMessage)($this, $this->recvBuffer);
        } catch (Throwable $e) {
            $this->error($e);
        }
        // Очистить буфер чтения.
        $this->recvBuffer = '';
    }

    /**
     * SSL handshake.
     *
     * @param resource $socket
     * @return bool|int
     * @throws Throwable
     */
    public function doSslHandshake($socket): bool|int
    {
        if (feof($socket)) {
            $this->destroy();
            return false;
        }
        $async = $this instanceof AsyncTcpConnection;

        /**
         * SSLv3 небезопасен.
         * @see https://blog.qualys.com/ssllabs/2014/10/15/ssl-3-is-dead-killed-by-the-poodle-attack
         */
        if ($async) {
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
        } else {
            // if (defined('STREAM_CRYPTO_METHOD_SERVER')) {
            //     $type = STREAM_CRYPTO_METHOD_SERVER;
            // } else {
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER | STREAM_CRYPTO_METHOD_SSLv3_SERVER;
            // }
        }

        // Скрытая ошибка.
        set_error_handler(function ($errno, $err_str) {
            if (!Server::$daemonize) {
                Server::safeEcho("Ошибка SSL-соединения: $err_str \n");
            }
        });
        $ret = stream_socket_enable_crypto($socket, true, $type);
        restore_error_handler();
        // Переговоры не удалось.
        if (false === $ret) {
            $this->destroy();
            return false;
        }
        if (0 === $ret) {
            // Данных недостаточно, нужно повторить попытку.
            return 0;
        }
        return true;
    }

    /**
     * Удаляет $length данных из буфера чтения.
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer(int $length): void
    {
        $this->recvBuffer = substr($this->recvBuffer, $length);
    }

    /**
     * Получает реальный сокет.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Проверяет, пустой ли буфер отправки.
     *
     * @return bool
     */
    public function bufferIsEmpty(): bool
    {
        return empty($this->sendBuffer);
    }

    /**
     * Получает информацию для json_encode.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->getStatus(),
            'transport' => $this->transport,
            'getRemoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'getRemoteAddress' => $this->getRemoteAddress(),
            'getLocalIp' => $this->getLocalIp(),
            'getLocalPort' => $this->getLocalPort(),
            'getLocalAddress' => $this->getLocalAddress(),
            'isIpV4' => $this->isIpV4(),
            'isIpV6' => $this->isIpV6(),
        ];
    }

    /**
     * Получает статус.
     *
     * @param bool $rawOutput
     *
     * @return int|string
     */
    public function getStatus(bool $rawOutput = true): int|string
    {
        if ($rawOutput) {
            return $this->status;
        }
        return self::STATUS_TO_STRING[$this->status];
    }

    /**
     * @inheritdoc
     */
    public function getRemoteIp(): string
    {
        $pos = strrpos($this->remoteAddress, ':');
        if ($pos) {
            return substr($this->remoteAddress, 0, $pos);
        }
        return '';
    }

    /**
     * @inheritdoc
     */
    public function getRemotePort(): int
    {
        if ($this->remoteAddress) {
            return (int)substr(strrchr($this->remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * @inheritdoc
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * @inheritdoc
     */
    public function getLocalIp(): string
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return substr($address, 0, $pos);
    }

    /**
     * @inheritdoc
     */
    public function getLocalAddress(): string
    {
        if (!is_resource($this->socket)) {
            return '';
        }
        return (string)@stream_socket_get_name($this->socket, false);
    }

    /**
     * @inheritdoc
     */
    public function getLocalPort(): int
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * @inheritdoc
     */
    #[Pure]
    public function isIpV4(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return !str_contains($this->getRemoteIp(), ':');
    }

    /**
     * @inheritdoc
     */
    #[Pure]
    public function isIpV6(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return str_contains($this->getRemoteIp(), ':');
    }

    /**
     * Деструктор.
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Server::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = ceil((self::$statistics['connection_count'] + 1) / 3);
            }

            if (0 === self::$statistics['connection_count'] % $mod) {
                $pid = function_exists('posix_getpid') ? posix_getpid() : 0;
                Server::log('server[' . $pid . '] осталось ' . self::$statistics['connection_count'] . ' соединений(я)');
            }

            if (0 === self::$statistics['connection_count']) {
                Server::stopAll();
            }
        }
    }
}
