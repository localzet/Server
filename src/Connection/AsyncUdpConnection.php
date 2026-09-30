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

use JsonSerializable;
use localzet\Server;
use localzet\Server\Events\EventInterface;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Исходящее UDP-соединение.
 *
 * UDP не имеет TCP-подобного handshake, поэтому connect() здесь создаёт
 * connected UDP socket: ОС фиксирует peer, а sendto() вызывается без адреса.
 * Прикладной protocol scheme (например text://) при этом не влияет на транспорт.
 */
class AsyncUdpConnection extends ConnectionInterface implements JsonSerializable
{
    public const MAX_UDP_PACKAGE_SIZE = 65535;

    public int $id;
    public string $transport = 'udp';
    public int $maxPackageSize = 65507;
    public stdClass $context;

    public $onConnect = null;

    protected string $remoteAddress;
    protected array $contextOption = [];

    /** @var resource|null */
    protected $socket = null;
    protected bool $connected = false;
    protected bool $closed = false;

    public function __construct(string $remoteAddress, array|EventInterface $contextOption = [], ?EventInterface $eventLoop = null)
    {
        // Сохраняем совместимость с промежуточным API, где вторым аргументом
        // можно было передать EventInterface напрямую.
        if ($contextOption instanceof EventInterface) {
            $eventLoop = $contextOption;
            $contextOption = [];
        }

        [$scheme, $address] = $this->parseRemoteAddress($remoteAddress);
        if ($scheme !== 'udp') {
            $protocolName = ucfirst($scheme);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $protocolName)) {
                throw new RuntimeException("Invalid UDP protocol scheme: $scheme");
            }

            $globalProtocol = "\\Protocols\\$protocolName";
            $localzetProtocol = "\\localzet\\Server\\Protocols\\$protocolName";
            if (class_exists($globalProtocol)) {
                $this->protocol = $globalProtocol;
            } elseif (class_exists($localzetProtocol)) {
                $this->protocol = $localzetProtocol;
            } else {
                throw new RuntimeException("UDP protocol class for scheme '$scheme' not found.");
            }
        }

        $this->remoteAddress = $address;
        $this->contextOption = $contextOption;
        $this->eventLoop = $eventLoop;
        $this->context = new stdClass();
        $this->id = spl_object_id($this);
        self::$statistics['connection_count']++;
    }

    public function connect(): void
    {
        if ($this->connected || $this->closed) {
            return;
        }

        $this->eventLoop ??= Server::getEventLoop();
        $errno = 0;
        $errstr = '';
        $context = $this->contextOption ? stream_context_create($this->contextOption) : null;
        $uri = 'udp://' . $this->formatSocketAddress($this->remoteAddress);

        $socket = $context !== null
            ? @stream_socket_client($uri, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context)
            : @stream_socket_client($uri, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);

        if (!is_resource($socket)) {
            self::$statistics['send_fail']++;
            $this->emitError(self::CONNECT_FAIL, $errstr !== '' ? $errstr : "Unable to connect UDP peer $this->remoteAddress.");
            return;
        }

        $this->socket = $socket;
        $this->connected = true;
        stream_set_blocking($socket, false);
        $this->eventLoop->onReadable($socket, $this->baseRead(...));

        if ($this->onConnect !== null) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
            }
        }
    }

    public function send(mixed $data, bool $raw = false): ?bool
    {
        if ($this->closed) {
            return false;
        }
        if (!$this->connected) {
            $this->connect();
        }
        if (!is_resource($this->socket)) {
            return false;
        }

        try {
            if (!$raw && $this->protocol !== null) {
                $protocol = $this->protocol;
                $data = $protocol::encode($data, $this);
            }
        } catch (Throwable $e) {
            self::$statistics['throw_exception']++;
            $this->error($e);
            return false;
        }

        if ($data === null || $data === '') {
            return null;
        }
        $data = (string)$data;
        if (strlen($data) > $this->maxPackageSize) {
            self::$statistics['send_fail']++;
            $this->emitError(self::SEND_FAIL, 'UDP datagram exceeds maxPackageSize.');
            return false;
        }

        // На connected UDP socket destination передавать нельзя: на BSD/macOS
        // это, в частности, может завершиться EISCONN.
        $written = @stream_socket_sendto($this->socket, $data);
        if ($written !== strlen($data)) {
            self::$statistics['send_fail']++;
            $this->emitError(self::SEND_FAIL, 'Unable to send complete UDP datagram.');
            return false;
        }
        return true;
    }

    /** @internal */
    public function baseRead($socket): void
    {
        $data = @stream_socket_recvfrom($socket, min(self::MAX_UDP_PACKAGE_SIZE, $this->maxPackageSize));
        if ($data === false || $data === '') {
            return;
        }

        self::$statistics['total_request']++;
        try {
            $message = $this->protocol !== null ? ($this->protocol)::decode($data, $this) : $data;
            if ($this->onMessage !== null && $message !== null) {
                ($this->onMessage)($this, $message);
            }
        } catch (Throwable $e) {
            self::$statistics['throw_exception']++;
            $this->error($e);
        }
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($this->closed) {
            return;
        }
        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->closed = true;
        $this->connected = false;
        if (is_resource($this->socket)) {
            $this->eventLoop?->offReadable($this->socket);
            @fclose($this->socket);
        }
        $this->socket = null;
        self::$statistics['connection_count'] = max(0, self::$statistics['connection_count'] - 1);

        if ($this->onClose !== null) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                self::$statistics['throw_exception']++;
                $this->error($e);
            }
        }
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function getRemoteIp(): string
    {
        return $this->splitAddress($this->remoteAddress)[0];
    }

    public function getRemotePort(): int
    {
        return $this->splitAddress($this->remoteAddress)[1];
    }

    public function getLocalAddress(): string
    {
        return is_resource($this->socket) ? (string)@stream_socket_get_name($this->socket, false) : '';
    }

    public function getLocalIp(): string
    {
        return $this->splitAddress($this->getLocalAddress())[0];
    }

    public function getLocalPort(): int
    {
        return $this->splitAddress($this->getLocalAddress())[1];
    }

    public function isIpV4(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function isIpV6(): bool
    {
        return filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /** @return resource|null */
    public function getSocket()
    {
        return $this->socket;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'transport' => $this->transport,
            'remoteAddress' => $this->getRemoteAddress(),
            'remoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'localAddress' => $this->getLocalAddress(),
            'localIp' => $this->getLocalIp(),
            'localPort' => $this->getLocalPort(),
            'connected' => $this->connected,
        ];
    }

    /** @return array{0:string,1:string} */
    protected function parseRemoteAddress(string $remoteAddress): array
    {
        if (!preg_match('~^([A-Za-z][A-Za-z0-9]*)://(.+)$~D', $remoteAddress, $match)) {
            return ['udp', $remoteAddress];
        }
        return [strtolower($match[1]), $match[2]];
    }

    protected function formatSocketAddress(string $address): string
    {
        // IPv6 address without [] needs brackets when combined with a port.
        [$host, $port] = $this->splitAddress($address);
        if ($port > 0 && str_contains($host, ':') && !str_starts_with($address, '[')) {
            return "[$host]:$port";
        }
        return $address;
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
        return $pos === false ? [$address, 0] : [substr($address, 0, $pos), (int)substr($address, $pos + 1)];
    }

    protected function emitError(int $code, string $message): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)($this, $code, $message);
        } catch (Throwable $e) {
            self::$statistics['throw_exception']++;
            $this->error($e);
        }
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}
