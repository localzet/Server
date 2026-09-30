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
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Исходящее неблокирующее TCP/Unix/TLS соединение.
 *
 * Соединение создаётся лениво. Пока TCP/TLS/proxy handshake не завершён,
 * исходящие данные складываются в send buffer и сохраняют порядок.
 *
 * API совместим с историческим Localzet-клиентом: поддерживаются
 * reconnect(), HTTP CONNECT, SOCKS5 (включая username/password), URI helpers
 * и пользовательские application protocols вроде ws://.
 */
class AsyncTcpConnection extends TcpConnection
{
    /** PHP transport schemes, не являющиеся application protocols. */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'unix' => 'unix',
        'ssl' => 'ssl',
        'tls' => 'ssl',
        'sslv2' => 'ssl',
        'sslv3' => 'ssl',
    ];

    public $onConnect = null;
    public $onReconnect = null;

    /** SOCKS5 proxy address, например 127.0.0.1:1080. */
    public string $proxySocks5 = '';
    /** HTTP CONNECT proxy address, например 127.0.0.1:8080. */
    public string $proxyHttp = '';
    /** Готовое значение Proxy-Authorization, например "Basic ...". */
    public string $proxyAuthorization = '';
    public string $proxySocks5Username = '';
    public string $proxySocks5Password = '';

    protected string $remoteHost = '';
    protected int $remotePort = 0;
    protected string $remoteURI = '/';
    protected string $scheme = 'tcp';
    protected array $socketContext = [];

    protected float $connectTimeout = 5.0;
    protected float $connectStartTime = 0.0;
    protected int $connectTimer = 0;
    protected int $reconnectTimer = 0;
    protected bool $connecting = false;

    /** Proxy handshake state. */
    protected string $proxyStage = '';
    protected string $proxyReadBuffer = '';
    protected string $proxyWriteBuffer = '';
    protected string $proxyNextReadStage = '';

    public function __construct(string $remoteAddress, array $socketContext = [], ?EventInterface $eventLoop = null)
    {
        $this->socketContext = $socketContext;
        $this->eventLoop = $eventLoop ?? Server::getEventLoop();
        $this->context = new stdClass();
        $this->id = self::nextConnectionId();
        $this->status = self::STATUS_INITIAL;
        $this->socket = null;
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->lingerTimeout = self::$defaultLingerTimeout;

        $this->parseRemoteAddress($remoteAddress);

        self::$statistics['connection_count']++;
        self::$connections[$this->id] = $this;
    }

    /**
     * Начинает установку соединения. Повторный вызов во время connect/established
     * безопасно игнорируется.
     */
    public function connect(): void
    {
        if ($this->connecting || $this->status === self::STATUS_ESTABLISHED) {
            return;
        }
        if ($this->status === self::STATUS_ENDING || $this->status === self::STATUS_CLOSING) {
            return;
        }

        // После полного destroy() объект можно использовать повторно через reconnect/connect.
        $this->restoreRegistryIfNeeded();

        $this->connecting = true;
        $this->destroyed = false;
        $this->status = self::STATUS_CONNECTING;
        $this->connectStartTime = microtime(true);
        $this->sslHandshakeCompleted = $this->transport !== 'ssl';
        $this->endWriteShutdown = false;
        $this->proxyStage = '';
        $this->proxyReadBuffer = '';
        $this->proxyWriteBuffer = '';

        $target = $this->connectTarget();
        $context = stream_context_create($this->socketContext);
        $errno = 0;
        $errstr = '';

        $flags = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        $socket = @stream_socket_client($target, $errno, $errstr, 0, $flags, $context);
        if (!is_resource($socket)) {
            $this->connecting = false;
            $this->status = self::STATUS_CLOSING;
            $this->emitError(self::CONNECT_FAIL, $errstr !== '' ? $errstr : "Unable to connect to {$target}");
            $this->destroy();
            return;
        }

        $this->socket = $socket;
        $this->remoteAddress = null;
        $this->localAddress = null;
        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);

        $this->eventLoop->onWritable($socket, $this->checkConnection(...));
        $this->connectTimer = $this->eventLoop->delay($this->connectTimeout, $this->connectTimeout(...));
    }

    /**
     * Закрывает текущий transport и подключается заново.
     */
    public function reconnect(int|float $after = 0): void
    {
        $this->cancelReconnect();
        $this->resetTransportForReconnect();

        if ($after > 0) {
            $this->reconnectTimer = $this->eventLoop->delay((float)$after, function (): void {
                $this->reconnectTimer = 0;
                if ($this->onReconnect !== null) {
                    ($this->onReconnect)($this);
                }
                $this->connect();
            });
            return;
        }

        if ($this->onReconnect !== null) {
            ($this->onReconnect)($this);
        }
        $this->connect();
    }

    public function cancelReconnect(): void
    {
        if ($this->reconnectTimer !== 0) {
            $this->eventLoop->offDelay($this->reconnectTimer);
            $this->reconnectTimer = 0;
        }
    }

    public function setConnectTimeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Connect timeout must be greater than zero.');
        }
        $this->connectTimeout = $seconds;
        return $this;
    }

    public function getRemoteHost(): string
    {
        return $this->remoteHost;
    }

    public function getRemoteURI(): string
    {
        return $this->remoteURI;
    }

    public function send(mixed $data, bool $raw = false): ?bool
    {
        if ($this->status === self::STATUS_INITIAL || $this->status === self::STATUS_CLOSED) {
            $this->connect();
        }

        if ($this->status === self::STATUS_CONNECTING) {
            try {
                if (!$raw && $this->protocol !== null) {
                    $protocol = $this->protocol;
                    $data = $protocol::encode($data, $this);
                }
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->emitError(0, 'Protocol encode failed: ' . $e->getMessage());
                return false;
            }

            if ($data === null || $data === '') {
                return null;
            }
            return $this->bufferData((string)$data);
        }

        return parent::send($data, $raw);
    }

    /** @internal event-loop callback. */
    public function checkConnection($socket = null): void
    {
        $socket ??= $this->socket;
        if (!is_resource($socket) || $this->status !== self::STATUS_CONNECTING) {
            return;
        }

        $this->eventLoop->offWritable($socket);

        // stream_socket_get_name(remote=true) становится доступен только после TCP connect.
        $peer = @stream_socket_get_name($socket, true);
        if ($peer === false) {
            $this->failConnect(self::CONNECT_FAIL, 'Connection refused or async connect failed.');
            return;
        }

        $this->configureConnectedSocket($socket);

        if ($this->proxySocks5 !== '') {
            $this->beginSocks5Handshake();
            return;
        }
        if ($this->proxyHttp !== '') {
            $this->beginHttpProxyHandshake();
            return;
        }

        $this->finishTransportHandshake();
    }

    /** @internal proxy write callback. */
    public function flushProxyWrite($socket): void
    {
        if (!is_resource($socket) || $this->proxyWriteBuffer === '') {
            return;
        }
        $written = @fwrite($socket, $this->proxyWriteBuffer);
        if ($written === false) {
            $this->failConnect(self::CONNECT_FAIL, 'Unable to write proxy handshake.');
            return;
        }
        if ($written > 0) {
            $this->proxyWriteBuffer = (string)substr($this->proxyWriteBuffer, $written);
        }
        if ($this->proxyWriteBuffer !== '') {
            return;
        }

        $this->eventLoop->offWritable($socket);
        $this->proxyStage = $this->proxyNextReadStage;
        $this->proxyNextReadStage = '';
        $this->eventLoop->onReadable($socket, $this->readProxyHandshake(...));
    }

    /** @internal proxy read callback. */
    public function readProxyHandshake($socket): void
    {
        if (!is_resource($socket)) {
            return;
        }
        $chunk = @fread($socket, 8192);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            $this->failConnect(self::CONNECT_FAIL, 'Proxy closed connection during handshake.');
            return;
        }
        if ($chunk === '') {
            return;
        }
        $this->proxyReadBuffer .= $chunk;

        switch ($this->proxyStage) {
            case 'http-response':
                $end = strpos($this->proxyReadBuffer, "\r\n\r\n");
                if ($end === false) {
                    if (strlen($this->proxyReadBuffer) > 65536) {
                        $this->failConnect(self::CONNECT_FAIL, 'HTTP proxy response headers are too large.');
                    }
                    return;
                }
                $head = substr($this->proxyReadBuffer, 0, $end + 4);
                if (!preg_match('~^HTTP/\d(?:\.\d)?\s+200\b~i', $head)) {
                    preg_match('~^HTTP/\S+\s+(\d{3})(?:\s+([^\r\n]+))?~i', $head, $match);
                    $status = $match[1] ?? 'unknown';
                    $reason = trim($match[2] ?? '');
                    $this->failConnect(self::CONNECT_FAIL, "HTTP proxy CONNECT failed: {$status}" . ($reason !== '' ? " {$reason}" : ''));
                    return;
                }
                $this->finishProxyHandshake();
                return;

            case 'socks-method':
                if (strlen($this->proxyReadBuffer) < 2) {
                    return;
                }
                [$ver, $method] = [ord($this->proxyReadBuffer[0]), ord($this->proxyReadBuffer[1])];
                $this->proxyReadBuffer = (string)substr($this->proxyReadBuffer, 2);
                if ($ver !== 5 || $method === 0xff) {
                    $this->failConnect(self::CONNECT_FAIL, 'SOCKS5 authentication method negotiation failed.');
                    return;
                }
                if ($method === 0x02) {
                    if ($this->proxySocks5Username === '' && $this->proxySocks5Password === '') {
                        $this->failConnect(self::CONNECT_FAIL, 'SOCKS5 proxy requires username/password authentication.');
                        return;
                    }
                    $user = $this->proxySocks5Username;
                    $pass = $this->proxySocks5Password;
                    if (strlen($user) > 255 || strlen($pass) > 255) {
                        $this->failConnect(self::CONNECT_FAIL, 'SOCKS5 credentials are too long.');
                        return;
                    }
                    $this->queueProxyWrite(chr(1) . chr(strlen($user)) . $user . chr(strlen($pass)) . $pass, 'socks-auth');
                    return;
                }
                if ($method !== 0x00) {
                    $this->failConnect(self::CONNECT_FAIL, "Unsupported SOCKS5 auth method {$method}.");
                    return;
                }
                $this->sendSocks5ConnectRequest();
                return;

            case 'socks-auth':
                if (strlen($this->proxyReadBuffer) < 2) {
                    return;
                }
                $status = ord($this->proxyReadBuffer[1]);
                $this->proxyReadBuffer = (string)substr($this->proxyReadBuffer, 2);
                if ($status !== 0) {
                    $this->failConnect(self::CONNECT_FAIL, 'SOCKS5 username/password authentication failed.');
                    return;
                }
                $this->sendSocks5ConnectRequest();
                return;

            case 'socks-connect':
                $required = $this->socks5ReplyLength($this->proxyReadBuffer);
                if ($required === 0 || strlen($this->proxyReadBuffer) < $required) {
                    return;
                }
                if (ord($this->proxyReadBuffer[0]) !== 5 || ord($this->proxyReadBuffer[1]) !== 0) {
                    $code = isset($this->proxyReadBuffer[1]) ? ord($this->proxyReadBuffer[1]) : -1;
                    $this->failConnect(self::CONNECT_FAIL, "SOCKS5 CONNECT failed with code {$code}.");
                    return;
                }
                $this->finishProxyHandshake();
                return;
        }
    }

    /** @internal TLS progress callback. */
    public function finishTransportHandshake($socket = null): void
    {
        $socket ??= $this->socket;
        if (!is_resource($socket) || $this->status !== self::STATUS_CONNECTING) {
            return;
        }

        $this->eventLoop->offReadable($socket);
        $this->eventLoop->offWritable($socket);

        if ($this->transport === 'ssl') {
            $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === 0) {
                // OpenSSL может потребовать продолжения как на read, так и на write readiness.
                $this->eventLoop->onReadable($socket, $this->finishTransportHandshake(...));
                $this->eventLoop->onWritable($socket, $this->finishTransportHandshake(...));
                return;
            }
            if ($result !== true) {
                $this->failConnect(self::CONNECT_FAIL, 'TLS client handshake failed.');
                return;
            }
            $this->sslHandshakeCompleted = true;
        }

        $this->cancelConnectTimer();
        $this->connecting = false;
        $this->status = self::STATUS_ESTABLISHED;
        $this->remoteAddress = $this->remoteHost !== ''
            ? $this->remoteHost . ':' . $this->remotePort
            : (string)@stream_socket_get_name($socket, true);
        $this->localAddress = null;

        $this->eventLoop->onReadable($socket, $this->baseRead(...));

        // Application protocol получает возможность выполнить свой handshake (например Ws).
        if ($this->protocol !== null && method_exists($this->protocol, 'onConnect')) {
            try {
                ($this->protocol)::onConnect($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
                return;
            }
        }

        if ($this->sendBuffer !== '') {
            $this->eventLoop->onWritable($socket, $this->baseWrite(...));
        }

        if ($this->onConnect !== null) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
            }
        }
    }

    public function destroy(): void
    {
        $this->cancelConnectTimer();
        $this->cancelReconnect();
        $this->connecting = false;
        parent::destroy();
    }

    protected function connectTimeout(): void
    {
        if ($this->status !== self::STATUS_CONNECTING) {
            return;
        }
        $this->connectTimer = 0;
        $elapsed = round(microtime(true) - $this->connectStartTime, 3);
        $this->failConnect(self::CONNECT_FAIL, "Connection timed out after {$elapsed}s.");
    }

    protected function cancelConnectTimer(): void
    {
        if ($this->connectTimer !== 0) {
            $this->eventLoop->offDelay($this->connectTimer);
            $this->connectTimer = 0;
        }
    }

    protected function failConnect(int $code, string $message): void
    {
        $this->cancelConnectTimer();
        $this->connecting = false;
        $this->status = self::STATUS_CLOSING;
        $this->emitError($code, $message);
        $this->destroy();
    }

    protected function connectTarget(): string
    {
        if ($this->proxySocks5 !== '') {
            return $this->normalizeProxyAddress($this->proxySocks5);
        }
        if ($this->proxyHttp !== '') {
            return $this->normalizeProxyAddress($this->proxyHttp);
        }
        if ($this->transport === 'unix') {
            return 'unix://' . $this->remoteHost;
        }
        return 'tcp://' . $this->formatHostPort($this->remoteHost, $this->remotePort);
    }

    protected function beginHttpProxyHandshake(): void
    {
        $authority = $this->formatHostPort($this->remoteHost, $this->remotePort);
        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\nProxy-Connection: keep-alive\r\n";
        if ($this->proxyAuthorization !== '') {
            $value = str_replace(["\r", "\n"], '', $this->proxyAuthorization);
            $request .= "Proxy-Authorization: {$value}\r\n";
        }
        $request .= "\r\n";
        $this->queueProxyWrite($request, 'http-response');
    }

    protected function beginSocks5Handshake(): void
    {
        $methods = ($this->proxySocks5Username !== '' || $this->proxySocks5Password !== '')
            ? "\x00\x02"
            : "\x00";
        $this->queueProxyWrite("\x05" . chr(strlen($methods)) . $methods, 'socks-method');
    }

    protected function queueProxyWrite(string $buffer, string $nextReadStage): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        $this->eventLoop->offReadable($this->socket);
        $this->eventLoop->offWritable($this->socket);
        $this->proxyReadBuffer = '';
        $this->proxyWriteBuffer = $buffer;
        $this->proxyNextReadStage = $nextReadStage;
        $this->eventLoop->onWritable($this->socket, $this->flushProxyWrite(...));
    }

    protected function sendSocks5ConnectRequest(): void
    {
        if ($this->remoteHost === '' || strlen($this->remoteHost) > 255) {
            $this->failConnect(self::CONNECT_FAIL, 'SOCKS5 target host is invalid.');
            return;
        }
        // Domain-name ATYP avoids resolving DNS on the client side and lets the proxy resolve it.
        $request = "\x05\x01\x00\x03" . chr(strlen($this->remoteHost)) . $this->remoteHost . pack('n', $this->remotePort);
        $this->queueProxyWrite($request, 'socks-connect');
    }

    protected function socks5ReplyLength(string $buffer): int
    {
        if (strlen($buffer) < 5) {
            return 0;
        }
        return match (ord($buffer[3])) {
            0x01 => 10,
            0x04 => 22,
            0x03 => 7 + ord($buffer[4]),
            default => throw new RuntimeException('Invalid SOCKS5 reply address type.'),
        };
    }

    protected function finishProxyHandshake(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        $this->eventLoop->offReadable($this->socket);
        $this->eventLoop->offWritable($this->socket);
        $this->proxyStage = '';
        $this->proxyReadBuffer = '';
        $this->proxyWriteBuffer = '';
        $this->finishTransportHandshake();
    }

    protected function configureConnectedSocket($socket): void
    {
        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);

        if ($this->transport === 'unix' || !function_exists('socket_import_stream')) {
            return;
        }
        $native = @socket_import_stream($socket);
        if ($native === false) {
            return;
        }
        @socket_set_option($native, SOL_SOCKET, SO_KEEPALIVE, 1);
        if (defined('SOL_TCP') && defined('TCP_NODELAY')) {
            @socket_set_option($native, SOL_TCP, TCP_NODELAY, 1);
        }
        if (defined('SOL_TCP') && defined('TCP_KEEPIDLE')) {
            @socket_set_option($native, SOL_TCP, TCP_KEEPIDLE, 55);
        }
        if (defined('SOL_TCP') && defined('TCP_KEEPINTVL')) {
            @socket_set_option($native, SOL_TCP, TCP_KEEPINTVL, 55);
        }
        if (defined('SOL_TCP') && defined('TCP_KEEPCNT')) {
            @socket_set_option($native, SOL_TCP, TCP_KEEPCNT, 1);
        }
    }

    protected function resetTransportForReconnect(): void
    {
        $this->cancelConnectTimer();
        if (is_resource($this->socket)) {
            $this->eventLoop->offReadable($this->socket);
            $this->eventLoop->offWritable($this->socket);
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->status = self::STATUS_INITIAL;
        $this->connecting = false;
        $this->destroyed = false;
        $this->sslHandshakeCompleted = false;
        $this->remoteAddress = null;
        $this->localAddress = null;
        $this->recvBuffer = '';
        $this->sendBuffer = '';
        $this->currentPackageLength = 0;
        $this->proxyStage = '';
        $this->proxyReadBuffer = '';
        $this->proxyWriteBuffer = '';
        $this->restoreRegistryIfNeeded();
    }

    protected function restoreRegistryIfNeeded(): void
    {
        if (!isset(self::$connections[$this->id])) {
            self::$connections[$this->id] = $this;
            self::$statistics['connection_count']++;
        }
    }

    protected function parseRemoteAddress(string $remoteAddress): void
    {
        if (!str_contains($remoteAddress, '://')) {
            $remoteAddress = 'tcp://' . $remoteAddress;
        }

        $info = parse_url($remoteAddress);
        if ($info === false || !isset($info['scheme'])) {
            throw new RuntimeException("Invalid remote address: {$remoteAddress}");
        }

        $this->scheme = strtolower($info['scheme']);

        if ($this->scheme === 'unix') {
            $path = $info['path'] ?? preg_replace('~^unix://~i', '', $remoteAddress);
            if (!is_string($path) || $path === '') {
                throw new RuntimeException('Unix socket path is missing.');
            }
            $this->remoteHost = $path;
            $this->remotePort = 0;
            $this->remoteURI = '/';
            $this->transport = 'unix';
            return;
        }

        $host = $info['host'] ?? '';
        if ($host === '') {
            throw new RuntimeException("Remote host is missing: {$remoteAddress}");
        }

        $this->remoteHost = $host;
        $this->remoteURI = ($info['path'] ?? '/') . (isset($info['query']) ? '?' . $info['query'] : '');

        if (isset(self::BUILD_IN_TRANSPORTS[$this->scheme])) {
            $this->transport = self::BUILD_IN_TRANSPORTS[$this->scheme];
        } elseif ($this->scheme === 'ws' || $this->scheme === 'wss') {
            $this->protocol = \localzet\Server\Protocols\Ws::class;
            $this->transport = $this->scheme === 'wss' ? 'ssl' : 'tcp';
            $this->context->wsPath = $this->remoteURI;
            $this->context->wsHost = $host;
        } else {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $this->scheme)) {
                throw new RuntimeException("Invalid protocol scheme '{$this->scheme}'.");
            }
            $class = ucfirst($this->scheme);
            $global = "\\Protocols\\{$class}";
            $local = "\\localzet\\Server\\Protocols\\{$class}";
            if (class_exists($global)) {
                $this->protocol = $global;
            } elseif (class_exists($local)) {
                $this->protocol = $local;
            } else {
                throw new RuntimeException("Application protocol for '{$this->scheme}' was not found.");
            }
            $this->transport = 'tcp';
        }

        $this->remotePort = isset($info['port']) ? (int)$info['port'] : $this->defaultPortForScheme($this->scheme);
        $this->remoteAddress = $this->formatHostPort($this->remoteHost, $this->remotePort);

        if ($this->transport === 'ssl') {
            $this->socketContext['ssl']['peer_name'] ??= $this->remoteHost;
            $this->socketContext['ssl']['verify_peer'] ??= true;
            $this->socketContext['ssl']['verify_peer_name'] ??= true;
        }
    }

    protected function defaultPortForScheme(string $scheme): int
    {
        return match ($scheme) {
            'ssl', 'tls', 'wss', 'https' => 443,
            default => 80,
        };
    }

    protected function normalizeProxyAddress(string $proxy): string
    {
        return preg_match('~^[a-z]+://~i', $proxy) ? $proxy : 'tcp://' . $proxy;
    }

    protected function formatHostPort(string $host, int $port): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return $port > 0 ? "{$host}:{$port}" : $host;
    }

    public function __destruct()
    {
        if (!$this->destroyed) {
            $this->destroy();
        }
    }
}
