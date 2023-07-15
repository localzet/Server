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

use Exception;
use localzet\Server;
use localzet\Timer;
use RuntimeException;
use stdClass;
use Throwable;
use function class_exists;
use function explode;
use function function_exists;
use function is_resource;
use function method_exists;
use function microtime;
use function parse_url;
use function socket_import_stream;
use function socket_set_option;
use function stream_context_create;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_client;
use function stream_socket_get_name;
use function ucfirst;
use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const TCP_NODELAY;

/**
 * AsyncTcpConnection.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * Встроенные протоколы PHP.
     *
     * @var array<string,string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls' => 'tls'
    ];
    /**
     * Вызывается при успешном установлении соединения по сокету.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Вызывается, когда завершено рукопожатие веб-сокета (работает только при протоколе ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Протокол транспортного уровня.
     *
     * @var string
     */
    public string $transport = 'tcp';
    /**
     * Socks5-прокси.
     *
     * @var string
     */
    public string $proxySocks5 = '';
    /**
     * HTTP-прокси.
     *
     * @var string
     */
    public string $proxyHttp = '';
    /**
     * Статус соединения.
     *
     * @var int
     */
    protected int $status = self::STATUS_INITIAL;
    /**
     * Удаленный хост.
     *
     * @var string
     */
    protected string $remoteHost = '';
    /**
     * Удаленный порт.
     *
     * @var int
     */
    protected int $remotePort = 80;
    /**
     * Время начала установки соединения.
     *
     * @var float
     */
    protected float $connectStartTime = 0;
    /**
     * Удаленный URI.
     *
     * @var string
     */
    protected string $remoteURI = '';
    /**
     * Опции контекста.
     *
     * @var array
     */
    protected array $contextOption = [];
    /**
     * Таймер переподключения.
     *
     * @var int
     */
    protected int $reconnectTimer = 0;

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @param array $contextOption
     * @throws Exception
     */
    public function __construct(string $remoteAddress, array $contextOption = [])
    {
        $addressInfo = parse_url($remoteAddress);
        if (!$addressInfo) {
            [$scheme, $this->remoteAddress] = explode(':', $remoteAddress, 2);
            if ('unix' === strtolower($scheme)) {
                $this->remoteAddress = substr($remoteAddress, strpos($remoteAddress, '/') + 2);
            }
            if (!$this->remoteAddress) {
                throw new RuntimeException('Bad remoteAddress');
            }
        } else {
            if (!isset($addressInfo['port'])) {
                $addressInfo['port'] = 0;
            }
            if (!isset($addressInfo['path'])) {
                $addressInfo['path'] = '/';
            }
            if (!isset($addressInfo['query'])) {
                $addressInfo['query'] = '';
            } else {
                $addressInfo['query'] = '?' . $addressInfo['query'];
            }
            $this->remoteHost = $addressInfo['host'];
            $this->remotePort = $addressInfo['port'];
            $this->remoteURI = "{$addressInfo['path']}{$addressInfo['query']}";
            $scheme = $addressInfo['scheme'] ?? 'tcp';
            $this->remoteAddress = 'unix' === strtolower($scheme)
                ? substr($remoteAddress, strpos($remoteAddress, '/') + 2)
                : $this->remoteHost . ':' . $this->remotePort;
        }

        $this->id = $this->realId = self::$idRecorder++;
        if (PHP_INT_MAX === self::$idRecorder) {
            self::$idRecorder = 0;
        }
        // Check application layer protocol class.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Server\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::BUILD_IN_TRANSPORTS[$scheme];
        }

        // For statistics.
        ++self::$statistics['connection_count'];
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->contextOption = $contextOption;
        static::$connections[$this->realId] = $this;
        $this->context = new stdClass;
    }

    /**
     * Reconnect.
     *
     * @param int $after
     * @return void
     * @throws Throwable
     */
    public function reconnect(int $after = 0): void
    {
        $this->status = self::STATUS_INITIAL;
        static::$connections[$this->realId] = $this;
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
        }
        if ($after > 0) {
            $this->reconnectTimer = Timer::add($after, [$this, 'connect'], null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Do connect.
     *
     * @return void
     * @throws Throwable
     */
    public function connect(): void
    {
        if (
            $this->status !== self::STATUS_INITIAL && $this->status !== self::STATUS_CLOSING &&
            $this->status !== self::STATUS_CLOSED
        ) {
            return;
        }

        if (!$this->eventLoop) {
            $this->eventLoop = Server::$globalEvent;
        }

        $this->status = self::STATUS_CONNECTING;
        $this->connectStartTime = microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->remotePort) {
                $this->remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->remoteAddress = $this->remoteHost . ':' . $this->remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->proxySocks5) {
                $this->contextOption['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->contextOption);
                $this->socket = stream_socket_client("tcp://$this->proxySocks5", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
                fwrite($this->socket, chr(5) . chr(1) . chr(0));
                fread($this->socket, 512);
                fwrite($this->socket, chr(5) . chr(1) . chr(0) . chr(3) . chr(strlen($this->remoteHost)) . $this->remoteHost . pack("n", $this->remotePort));
                fread($this->socket, 512);
            } else if ($this->proxyHttp) {
                $this->contextOption['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->contextOption);
                $this->socket = stream_socket_client("tcp://$this->proxyHttp", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
                $str = "CONNECT $this->remoteHost:$this->remotePort HTTP/1.1\n";
                $str .= "Host: $this->remoteHost:$this->remotePort\n";
                $str .= "Proxy-Connection: keep-alive\n";
                fwrite($this->socket, $str);
                fread($this->socket, 512);
            } else if ($this->contextOption) {
                $context = stream_context_create($this->contextOption);
                $this->socket = stream_socket_client(
                    "tcp://$this->remoteHost:$this->remotePort",
                    $errno,
                    $err_str,
                    0,
                    STREAM_CLIENT_ASYNC_CONNECT,
                    $context
                );
            } else {
                $this->socket = stream_socket_client(
                    "tcp://$this->remoteHost:$this->remotePort",
                    $errno,
                    $err_str,
                    0,
                    STREAM_CLIENT_ASYNC_CONNECT
                );
            }
        } else {
            $this->socket = stream_socket_client(
                "$this->transport://$this->remoteAddress",
                $errno,
                $err_str,
                0,
                STREAM_CLIENT_ASYNC_CONNECT
            );
        }
        // If failed attempt to emit onError callback.
        if (!$this->socket || !is_resource($this->socket)) {
            $this->emitError(static::CONNECT_FAIL, $err_str);
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or faild.
        $this->eventLoop->onWritable($this->socket, [$this, 'checkConnection']);
        // For windows.
        if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'onExcept')) {
            $this->eventLoop->onExcept($this->socket, [$this, 'checkConnection']);
        }
    }

    /**
     * Try to emit onError callback.
     *
     * @param int $code
     * @param mixed $msg
     * @return void
     * @throws Throwable
     */
    protected function emitError(int $code, mixed $msg): void
    {
        $this->status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                ($this->onError)($this, $code, $msg);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * CancelReconnect.
     */
    public function cancelReconnect(): void
    {
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = 0;
        }
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteHost(): string
    {
        return $this->remoteHost;
    }

    /**
     * Get remote URI.
     *
     * @return string
     */
    public function getRemoteURI(): string
    {
        return $this->remoteURI;
    }

    /**
     * Check connection is successfully established or failed.
     *
     * @return void
     * @throws Throwable
     */
    public function checkConnection(): void
    {
        // Remove EV_EXPECT for windows.
        if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'offExcept')) {
            $this->eventLoop->offExcept($this->socket);
        }

        // Remove write listener.
        $this->eventLoop->offWritable($this->socket);

        if ($this->status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = stream_socket_get_name($this->socket, true)) {
            // Nonblocking.
            stream_set_blocking($this->socket, false);
            // Compatible with hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($this->socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $rawSocket = socket_import_stream($this->socket);
                socket_set_option($rawSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($rawSocket, SOL_TCP, TCP_NODELAY, 1);
            }
            // SSL handshake.
            if ($this->transport === 'ssl') {
                $this->sslHandshakeCompleted = $this->doSslHandshake($this->socket);
                if ($this->sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->sendBuffer) {
                    $this->eventLoop->onWritable($this->socket, [$this, 'baseWrite']);
                }
            }
            // Register a listener waiting read event.
            $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);

            $this->status = self::STATUS_ESTABLISHED;
            $this->remoteAddress = $address;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    ($this->onConnect)($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            // Try to emit protocol::onConnect
            if ($this->protocol && method_exists($this->protocol, 'onConnect')) {
                try {
                    [$this->protocol, 'onConnect']($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
        } else {

            // Connection failed.
            $this->emitError(static::CONNECT_FAIL, 'connect ' . $this->remoteAddress . ' fail after ' . round(microtime(true) - $this->connectStartTime, 4) . ' seconds');
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
