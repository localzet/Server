<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2026 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Connection;

use localzet\Server;
use localzet\Server\Events\EventInterface;
use JsonSerializable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Неблокирующее TCP/Unix/SSL соединение.
 *
 * Класс держит транспортную механику отдельно от протокола приложения. Протокол
 * получает байты через input/decode и отдаёт наружу данные через encode.
 */
class TcpConnection extends ConnectionInterface implements JsonSerializable
{
    public const STATUS_INITIAL = 0;
    public const STATUS_CONNECTING = 1;
    public const STATUS_ESTABLISHED = 2;
    public const STATUS_ENDING = 4;
    public const STATUS_CLOSING = 8;
    public const STATUS_CLOSED = 16;

    public const READ_BUFFER_SIZE = 87380;
    public const MAX_SEND_BUFFER_SIZE = 1048576;
    public const DEFAULT_MAX_PACKAGE_SIZE = 10 * 1024 * 1024;
    public const MAX_CACHE_SIZE = 512;
    public const MAX_CACHE_STRING_LENGTH = 2048;
    public const TCP_KEEPALIVE_INTERVAL = 55;

    public const STATUS_TO_STRING = [
        self::STATUS_INITIAL => 'INITIAL',
        self::STATUS_CONNECTING => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_ENDING => 'ENDING',
        self::STATUS_CLOSING => 'CLOSING',
        self::STATUS_CLOSED => 'CLOSED',
    ];

    /** Defaults can be tuned globally before accepting connections. */
    public static int $defaultMaxSendBufferSize = self::MAX_SEND_BUFFER_SIZE;
    public static int $defaultMaxPackageSize = self::DEFAULT_MAX_PACKAGE_SIZE;
    public static float $defaultLingerTimeout = 1.0;

    /** Все активные TCP connections текущего процесса. */
    public static array $connections = [];

    protected static int $idRecorder = 1;

    public int $id;
    public ?Server $server = null;
    public string $transport = 'tcp';
    public int $bytesRead = 0;
    public int $bytesWritten = 0;
    public int $maxSendBufferSize = self::MAX_SEND_BUFFER_SIZE;
    public int $maxPackageSize = self::DEFAULT_MAX_PACKAGE_SIZE;
    public float $lingerTimeout = 1.0;
    public array $headers = [];
    public stdClass $context;

    public $onBufferFull = null;
    public $onBufferDrain = null;
    public $onWebSocketConnect = null;
    public $onWebSocketConnected = null;
    public $onWebSocketClose = null;
    public $onWebSocketPing = null;
    public $onWebSocketPong = null;

    /** @var resource|null */
    protected $socket;
    public ?EventInterface $eventLoop = null;
    protected int $status = self::STATUS_ESTABLISHED;
    protected string $recvBuffer = '';
    protected string $sendBuffer = '';
    protected int $currentPackageLength = 0;
    protected int $endLingerTimerId = 0;
    protected int $idleTimerId = 0;
    protected int $frameTimerId = 0;
    protected int $tlsHandshakeTimerId = 0;
    protected bool $endWriteShutdown = false;
    protected bool $paused = false;
    protected bool $destroyed = false;
    protected bool $sslHandshakeCompleted = false;
    protected float $idleTimeout = 0.0;
    protected float $frameTimeout = 0.0;
    protected float $tlsHandshakeTimeout = 10.0;
    protected float $lastActivityAt = 0.0;
    protected ?string $remoteAddress = null;
    protected ?string $localAddress = null;

    public function __construct(EventInterface $eventLoop, $socket, string $remoteAddress = '')
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException('TcpConnection requires a valid stream resource.');
        }

        $this->eventLoop = $eventLoop;
        $this->socket = $socket;
        $this->context = new stdClass();
        $this->id = self::nextConnectionId();
        $this->remoteAddress = $remoteAddress !== '' ? $remoteAddress : null;
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->lingerTimeout = self::$defaultLingerTimeout;
        $this->lastActivityAt = microtime(true);

        stream_set_blocking($this->socket, false);
        stream_set_read_buffer($this->socket, 0);
        self::$statistics['connection_count']++;
        self::$statistics['connection_total']++;
        self::$connections[$this->id] = $this;
        $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
    }

    public function getStatus(bool $rawOutput = true): int|string
    {
        return $rawOutput ? $this->status : self::STATUS_TO_STRING[$this->status];
    }

    public function getEventLoop(): EventInterface
    {
        return $this->eventLoop;
    }

    /**
     * Закрывает соединение, если по нему не было успешного I/O указанное время.
     * Таймер отключён по умолчанию, поэтому long-lived sockets не меняют поведение.
     */
    public function setIdleTimeout(float $seconds): static
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Idle timeout must be >= 0.');
        }
        $this->idleTimeout = $seconds;
        $this->lastActivityAt = microtime(true);
        $this->armIdleTimer();
        return $this;
    }

    public function getIdleTimeout(): float
    {
        return $this->idleTimeout;
    }

    /**
     * Ограничивает полное время сборки одного protocol frame.
     *
     * В отличие от idle timeout этот deadline не продлевается каждым новым байтом.
     */
    public function setFrameTimeout(float $seconds): static
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Frame timeout must be >= 0.');
        }
        $this->frameTimeout = $seconds;
        if ($seconds <= 0) {
            $this->disarmFrameTimer();
        } elseif ($this->protocol !== null && $this->recvBuffer !== '') {
            $this->armFrameTimer();
        }
        return $this;
    }

    public function getFrameTimeout(): float
    {
        return $this->frameTimeout;
    }

    /** Ограничение времени TLS handshake; 0 полностью отключает deadline. */
    public function setTlsHandshakeTimeout(float $seconds): static
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('TLS handshake timeout must be >= 0.');
        }
        $this->tlsHandshakeTimeout = $seconds;
        return $this;
    }

    public function getLastActivityAt(): float
    {
        return $this->lastActivityAt;
    }

    public function send(mixed $data, bool $raw = false): ?bool
    {
        $closeAfterProtocolSend = false;
        if (in_array($this->status, [self::STATUS_ENDING, self::STATUS_CLOSING, self::STATUS_CLOSED], true)) {
            self::$statistics['send_fail']++;
            return false;
        }

        try {
            if (!$raw && $this->protocol !== null) {
                $protocol = $this->protocol;
                $data = $protocol::encode($data, $this);
                $closeAfterProtocolSend = (bool)($this->context->closeAfterProtocolSend ?? false);
                unset($this->context->closeAfterProtocolSend);
            }
        } catch (Throwable $e) {
            self::$statistics['throw_exception']++;
            $this->emitError(0, 'Protocol encode failed: ' . $e->getMessage());
            return false;
        }

        if ($data === null || $data === '') {
            return $this->finalizeProtocolSend(null, $closeAfterProtocolSend);
        }
        if (!is_string($data)) {
            $data = (string)$data;
        }

        // До завершения TCP/TLS establishment данные только буферизуются.
        if ($this->status !== self::STATUS_ESTABLISHED
            || ($this->transport === 'ssl' && !$this->sslHandshakeCompleted)) {
            return $this->finalizeProtocolSend($this->bufferData($data), $closeAfterProtocolSend);
        }

        // Если в очереди уже есть данные, сохраняем порядок и только дописываем хвост.
        if ($this->sendBuffer !== '') {
            return $this->finalizeProtocolSend($this->bufferData($data), $closeAfterProtocolSend);
        }

        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            self::$statistics['send_fail']++;
            $this->emitError(0, 'Unable to write to socket.');
            return false;
        }

        if ($written < strlen($data)) {
            if ($written > 0) {
                $this->bytesWritten += $written;
                self::$statistics['bytes_written'] += $written;
                $this->touchActivity();
            }
            return $this->finalizeProtocolSend(
                $this->bufferData(substr($data, max(0, $written))),
                $closeAfterProtocolSend
            );
        }

        $this->bytesWritten += $written;
        self::$statistics['bytes_written'] += $written;
        if ($written > 0) {
            $this->touchActivity();
        }
        return $this->finalizeProtocolSend(true, $closeAfterProtocolSend);
    }

    /**
     * Завершает protocol-triggered close только после постановки текущего response
     * в send path. Ошибка отправки не должна оставлять клиенту обрезанный keep-alive.
     */
    protected function finalizeProtocolSend(?bool $result, bool $closeAfterProtocolSend): ?bool
    {
        if (!$closeAfterProtocolSend || $this->status === self::STATUS_CLOSED) {
            return $result;
        }
        if ($result === false) {
            $this->destroy();
            return false;
        }
        $this->end();
        return $result;
    }

    /**
     * Graceful close: сначала отправляет остаток данных, затем закрывает socket.
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        // Потоковая HTTP-отправка владеет последовательностью байтов до EOF.
        // Закрытие откладываем, иначе крупный файл оборвётся посередине.
        if (($this->context->streamSending ?? false) === true) {
            $this->context->closeAfterStream = ['mode' => 'close', 'data' => $data, 'raw' => $raw];
            return;
        }

        if ($data !== null && $data !== '') {
            $this->send($data, $raw);
        }
        $this->status = self::STATUS_CLOSING;
        if ($this->sendBuffer === '') {
            $this->destroy();
        } else {
            // После close() business input больше не нужен: ждём только flush send buffer.
            $this->pauseRecv();
        }
    }

    /**
     * Graceful end: дописывает send buffer, отправляет FIN и некоторое время
     * дренирует входящие данные. Это снижает риск TCP RST при закрытии HTTP/TLS.
     */
    public function end(mixed $data = null, bool $raw = false): void
    {
        if (in_array($this->status, [self::STATUS_ENDING, self::STATUS_CLOSING, self::STATUS_CLOSED], true)) {
            return;
        }
        if ($this->status === self::STATUS_INITIAL || $this->status === self::STATUS_CONNECTING) {
            $this->destroy();
            return;
        }

        if (($this->context->streamSending ?? false) === true) {
            $this->context->closeAfterStream = ['mode' => 'end', 'data' => $data, 'raw' => $raw];
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->status = self::STATUS_ENDING;
        $this->onMessage = static function (): void {
        };
        $this->recvBuffer = '';
        $this->currentPackageLength = 0;

        if ($this->sendBuffer === '') {
            $this->endMaybeShutdownWrite();
        }
    }

    /** Немедленно закрывает соединение, не дожидаясь send buffer. */
    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }
        $this->destroyed = true;
        $this->status = self::STATUS_CLOSED;

        if ($this->endLingerTimerId !== 0 && $this->eventLoop !== null) {
            $this->eventLoop->offDelay($this->endLingerTimerId);
            $this->endLingerTimerId = 0;
        }
        if ($this->idleTimerId !== 0 && $this->eventLoop !== null) {
            $this->eventLoop->offDelay($this->idleTimerId);
            $this->idleTimerId = 0;
        }
        $this->disarmFrameTimer();
        if ($this->tlsHandshakeTimerId !== 0 && $this->eventLoop !== null) {
            $this->eventLoop->offDelay($this->tlsHandshakeTimerId);
            $this->tlsHandshakeTimerId = 0;
        }

        // Если соединение уничтожено во время sendFile, закрываем file descriptor
        // и освобождаем замыкания, удерживающие потоковую передачу.
        if (isset($this->context->streamCleanup) && is_callable($this->context->streamCleanup)) {
            $cleanup = $this->context->streamCleanup;
            unset($this->context->streamCleanup);
            $cleanup(false);
        }

        if (is_resource($this->socket)) {
            $this->eventLoop->offReadable($this->socket);
            $this->eventLoop->offWritable($this->socket);
            @fclose($this->socket);
        }

        unset(self::$connections[$this->id]);
        if ($this->server !== null) {
            unset($this->server->connections[$this->id]);
            $this->server = null;
        }
        self::$statistics['connection_count'] = max(0, self::$statistics['connection_count'] - 1);
        if ($this->onClose !== null) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                Server::log($e);
            }
        }
        if ($this->protocol !== null && method_exists($this->protocol, 'onClose')) {
            try {
                ($this->protocol)::onClose($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
            }
        }
    }

    public function pauseRecv(): void
    {
        if ($this->paused || !is_resource($this->socket)) {
            return;
        }
        $this->paused = true;
        $this->eventLoop->offReadable($this->socket);
    }

    public function resumeRecv(): void
    {
        if (!$this->paused || !is_resource($this->socket)) {
            return;
        }
        $this->paused = false;
        $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
        if ($this->recvBuffer !== '') {
            $this->processRecvBuffer();
        }
    }

    /** @internal вызывается event loop при готовности socket к чтению. */
    public function baseRead($socket): void
    {
        if ($this->paused || $this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($this->transport === 'ssl' && !$this->sslHandshakeCompleted) {
            $this->enableSsl();
            return;
        }

        $buffer = @fread($socket, self::READ_BUFFER_SIZE);
        if ($buffer === '' || $buffer === false) {
            if (feof($socket)) {
                $this->destroy();
            }
            return;
        }

        $readLength = strlen($buffer);
        $this->bytesRead += $readLength;
        self::$statistics['bytes_read'] += $readLength;
        $this->touchActivity();

        // Первый байт нового protocol packet запускает абсолютный frame deadline.
        // В отличие от idle timeout последующие read() его не продлевают.
        if ($this->protocol !== null && $this->recvBuffer === '') {
            $this->armFrameTimer();
        }

        if ($this->status === self::STATUS_ENDING) {
            // В ENDING данные только дренируются; business protocol больше не вызывается.
            return;
        }

        $this->recvBuffer .= $buffer;
        if (strlen($this->recvBuffer) > $this->maxPackageSize) {
            $this->emitError(1, 'Receive buffer exceeded maxPackageSize.');
            $this->destroy();
            return;
        }

        $this->processRecvBuffer();
    }

    /** @internal вызывается event loop при возможности дописать send buffer. */
    public function baseWrite($socket): void
    {
        if ($this->transport === 'ssl' && !$this->sslHandshakeCompleted) {
            $this->enableSsl();
            return;
        }

        if ($this->sendBuffer === '' || $this->status === self::STATUS_CLOSED) {
            $this->eventLoop->offWritable($socket);
            return;
        }

        $written = $this->transport === 'ssl'
            ? @fwrite($socket, $this->sendBuffer, 8192)
            : @fwrite($socket, $this->sendBuffer);
        if ($written === false) {
            self::$statistics['send_fail']++;
            $this->destroy();
            return;
        }

        if ($written > 0) {
            $this->bytesWritten += $written;
            self::$statistics['bytes_written'] += $written;
            $this->touchActivity();
            $this->sendBuffer = (string)substr($this->sendBuffer, $written);
        }

        if ($this->sendBuffer === '') {
            $this->eventLoop->offWritable($socket);
            if ($this->onBufferDrain !== null) {
                try {
                    ($this->onBufferDrain)($this);
                } catch (Throwable $e) {
                    self::$statistics['throw_exception']++;
                    $this->error($e);
                }
            }
            if ($this->status === self::STATUS_ENDING) {
                $this->endMaybeShutdownWrite();
            } elseif ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
        }
    }

    public function getSendBufferQueueSize(): int
    {
        return strlen($this->sendBuffer);
    }

    public function getRecvBufferQueueSize(): int
    {
        return strlen($this->recvBuffer);
    }

    /** Удаляет указанное число байт из receive buffer. */
    public function consumeRecvBuffer(int $length): void
    {
        if ($length <= 0) {
            return;
        }
        $this->recvBuffer = (string)substr($this->recvBuffer, $length);
        $this->currentPackageLength = 0;
    }

    /** Связывает source -> destination с backpressure. */
    public function pipe(self $destination): void
    {
        $this->onMessage = static fn(self $source, mixed $data) => $destination->send($data);
        $this->onClose = static fn() => $destination->close();
        $destination->onBufferFull = fn() => $this->pauseRecv();
        $destination->onBufferDrain = fn() => $this->resumeRecv();
    }

    /** @return resource|null */
    public function getSocket()
    {
        return $this->socket;
    }

    public function bufferIsEmpty(): bool
    {
        return $this->sendBuffer === '';
    }

    protected function endMaybeShutdownWrite(): void
    {
        if ($this->status !== self::STATUS_ENDING || $this->endWriteShutdown || $this->sendBuffer !== '') {
            return;
        }

        if (is_resource($this->socket)) {
            @stream_socket_shutdown($this->socket, STREAM_SHUT_WR);
        }
        $this->endWriteShutdown = true;

        if ($this->lingerTimeout <= 0 || $this->eventLoop === null) {
            $this->close();
            return;
        }

        $this->endLingerTimerId = $this->eventLoop->delay($this->lingerTimeout, function (): void {
            $this->endLingerTimerId = 0;
            if ($this->status !== self::STATUS_CLOSED) {
                $this->close();
            }
        });
    }

    /**
     * Выполняет серверный TLS handshake без блокировки event loop.
     */
    public function enableSsl(int $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        if (!$this->sslHandshakeCompleted && $this->tlsHandshakeTimeout > 0 && $this->tlsHandshakeTimerId === 0) {
            $this->tlsHandshakeTimerId = $this->eventLoop->delay($this->tlsHandshakeTimeout, function (): void {
                $this->tlsHandshakeTimerId = 0;
                if (!$this->sslHandshakeCompleted && $this->status !== self::STATUS_CLOSED) {
                    self::$statistics['timeout']++;
                    $this->emitError(2, 'TLS handshake timed out.');
                    $this->destroy();
                }
            });
        }
        $result = @stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
        if ($result === true) {
            $this->sslHandshakeCompleted = true;
            if ($this->tlsHandshakeTimerId !== 0) {
                $this->eventLoop->offDelay($this->tlsHandshakeTimerId);
                $this->tlsHandshakeTimerId = 0;
            }
            $this->touchActivity();
            return true;
        }
        if ($result === 0) {
            return false;
        }
        $this->emitError(2, 'TLS handshake failed.');
        $this->destroy();
        return false;
    }

    protected function processRecvBuffer(): void
    {
        while ($this->recvBuffer !== '' && !$this->paused && $this->status !== self::STATUS_CLOSED) {
            $length = strlen($this->recvBuffer);
            $payloadLength = $length;

            try {
                if ($this->protocol !== null) {
                    $protocol = $this->protocol;
                    $payloadLength = (int)$protocol::input($this->recvBuffer, $this);
                    if ($payloadLength === 0) {
                        return;
                    }
                    if ($payloadLength < 0 || $payloadLength > $this->maxPackageSize) {
                        throw new RuntimeException('Invalid protocol frame length: ' . $payloadLength);
                    }
                    if ($payloadLength > $length) {
                        return;
                    }
                }

                // Полный frame собран — deadline этого frame больше не нужен.
                $this->disarmFrameTimer();

                $frame = substr($this->recvBuffer, 0, $payloadLength);
                $this->recvBuffer = (string)substr($this->recvBuffer, $payloadLength);

                // Если в kernel read уже лежит следующий packet, запускаем для него
                // отдельный абсолютный deadline.
                if ($this->protocol !== null && $this->recvBuffer !== '') {
                    $this->armFrameTimer();
                }

                $message = $this->protocol !== null
                    ? ($this->protocol)::decode($frame, $this)
                    : $frame;

                self::$statistics['total_request']++;
                if ($this->onMessage !== null && $message !== null) {
                    ($this->onMessage)($this, $message);
                }
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                Server::log($e);
                $this->destroy();
                return;
            }
        }
    }

    protected function bufferData(string $data): ?bool
    {
        if (strlen($this->sendBuffer) + strlen($data) > $this->maxSendBufferSize) {
            self::$statistics['send_fail']++;
            $this->emitError(3, 'Send buffer exceeded maxSendBufferSize.');
            return false;
        }

        $wasEmpty = $this->sendBuffer === '';
        $this->sendBuffer .= $data;
        if ($wasEmpty && is_resource($this->socket)) {
            $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
        }
        if (strlen($this->sendBuffer) >= $this->maxSendBufferSize && $this->onBufferFull !== null) {
            try {
                ($this->onBufferFull)($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
            }
        }
        return null;
    }

    protected function armFrameTimer(): void
    {
        if ($this->frameTimeout <= 0 || $this->frameTimerId !== 0 || $this->eventLoop === null) {
            return;
        }

        $this->frameTimerId = $this->eventLoop->delay($this->frameTimeout, function (): void {
            $this->frameTimerId = 0;
            if ($this->status === self::STATUS_CLOSED || $this->recvBuffer === '') {
                return;
            }
            self::$statistics['timeout']++;
            $this->emitError(4, 'Protocol frame timeout exceeded.');
            $this->destroy();
        });
    }

    protected function disarmFrameTimer(): void
    {
        if ($this->frameTimerId !== 0 && $this->eventLoop !== null) {
            $this->eventLoop->offDelay($this->frameTimerId);
        }
        $this->frameTimerId = 0;
    }

    protected function touchActivity(): void
    {
        $this->lastActivityAt = microtime(true);
        if ($this->idleTimeout > 0) {
            $this->armIdleTimer();
        }
    }

    protected function armIdleTimer(): void
    {
        if ($this->eventLoop === null) {
            return;
        }
        if ($this->idleTimerId !== 0) {
            $this->eventLoop->offDelay($this->idleTimerId);
            $this->idleTimerId = 0;
        }
        if ($this->idleTimeout <= 0 || $this->status === self::STATUS_CLOSED) {
            return;
        }

        $this->idleTimerId = $this->eventLoop->delay($this->idleTimeout, $this->handleIdleTimer(...));
    }

    protected function handleIdleTimer(): void
    {
        $this->idleTimerId = 0;
        if ($this->status === self::STATUS_CLOSED || $this->idleTimeout <= 0) {
            return;
        }
        $remaining = $this->idleTimeout - (microtime(true) - $this->lastActivityAt);
        if ($remaining > 0.001) {
            $this->idleTimerId = $this->eventLoop->delay($remaining, $this->handleIdleTimer(...));
            return;
        }
        self::$statistics['timeout']++;
        $this->emitError(4, 'Connection idle timeout exceeded.');
        $this->destroy();
    }

    protected function emitError(int $code, string $message): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($this, $code, $message);
            } catch (Throwable $e) {
                Server::log($e);
            }
        }
    }

    public function getRemoteIp(): string
    {
        return $this->splitAddress($this->getRemoteAddress())[0];
    }

    public function getRemotePort(): int
    {
        return $this->splitAddress($this->getRemoteAddress())[1];
    }

    public function getRemoteAddress(): string
    {
        if ($this->remoteAddress !== null) {
            return $this->remoteAddress;
        }
        if (!is_resource($this->socket)) {
            return '';
        }
        return $this->remoteAddress = (string)@stream_socket_get_name($this->socket, true);
    }

    public function getLocalIp(): string
    {
        return $this->splitAddress($this->getLocalAddress())[0];
    }

    public function getLocalPort(): int
    {
        return $this->splitAddress($this->getLocalAddress())[1];
    }

    public function getLocalAddress(): string
    {
        if ($this->localAddress !== null) {
            return $this->localAddress;
        }
        if (!is_resource($this->socket)) {
            return '';
        }
        return $this->localAddress = (string)@stream_socket_get_name($this->socket, false);
    }

    public function isIpV4(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function isIpV6(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /** @return array{0:string,1:int} */
    protected function splitAddress(string $address): array
    {
        if ($address === '') {
            return ['', 0];
        }
        if ($address[0] === '[' && ($end = strpos($address, ']')) !== false) {
            return [substr($address, 1, $end - 1), (int)ltrim(substr($address, $end + 1), ':')];
        }
        $pos = strrpos($address, ':');
        if ($pos === false || str_contains($address, '/')) {
            return [$address, 0];
        }
        return [substr($address, 0, $pos), (int)substr($address, $pos + 1)];
    }

    protected static function nextConnectionId(): int
    {
        $id = self::$idRecorder++;
        if (self::$idRecorder === PHP_INT_MAX) {
            self::$idRecorder = 1;
        }
        return $id;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->getStatus(false),
            'transport' => $this->transport,
            'remoteAddress' => $this->getRemoteAddress(),
            'remoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'localAddress' => $this->getLocalAddress(),
            'localIp' => $this->getLocalIp(),
            'localPort' => $this->getLocalPort(),
            'bytesRead' => $this->bytesRead,
            'bytesWritten' => $this->bytesWritten,
            'sendBuffer' => $this->getSendBufferQueueSize(),
            'recvBuffer' => $this->getRecvBufferQueueSize(),
            'idleTimeout' => $this->idleTimeout,
            'frameTimeout' => $this->frameTimeout,
            'lastActivityAt' => $this->lastActivityAt,
        ];
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
