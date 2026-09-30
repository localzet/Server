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
use localzet\Server\Events\EventInterface;
use stdClass;
use Throwable;

/**
 * Логическое UDP-соединение.
 *
 * Для server socket объект представляет конкретный peer поверх общего datagram
 * socket. Для connected UDP socket адрес назначения в sendto() намеренно не
 * передаётся: это важно для BSD/macOS, где иначе можно получить EISCONN.
 */
class UdpConnection extends ConnectionInterface implements JsonSerializable
{
    public const MAX_UDP_PACKAGE_SIZE = 65535;

    public int $id;
    public string $transport = 'udp';
    public int $maxPackageSize = 65507;
    public stdClass $context;
    public array $headers = [];

    /** @var resource|null */
    protected $socket;
    public ?EventInterface $eventLoop = null;
    protected string $remoteAddress;
    protected bool $connected = false;
    protected bool $closed = false;

    public function __construct(EventInterface $eventLoop, $socket, string $remoteAddress)
    {
        if (!is_resource($socket)) {
            throw new \InvalidArgumentException('UdpConnection requires a valid stream resource.');
        }
        $this->eventLoop = $eventLoop;
        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
        $this->context = new stdClass();
        $this->id = spl_object_id($this);
        $this->connected = @stream_socket_get_name($socket, true) !== false;
    }

    public function send(mixed $data, bool $raw = false): ?bool
    {
        if ($this->closed || !is_resource($this->socket)) {
            return false;
        }

        try {
            if (!$raw && $this->protocol !== null) {
                $protocol = $this->protocol;
                $data = $protocol::encode($data, $this);
            }
        } catch (Throwable $e) {
            self::$statistics['throw_exception']++;
            $this->emitError(0, $e->getMessage());
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

        $written = $this->connected
            ? @stream_socket_sendto($this->socket, $data)
            : @stream_socket_sendto($this->socket, $data, 0, $this->formatRemoteAddress());

        if ($written === false || $written !== strlen($data)) {
            self::$statistics['send_fail']++;
            $this->emitError(self::SEND_FAIL, 'Unable to send complete UDP datagram.');
            return false;
        }
        return true;
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

        // Server-side UdpConnection shares the listening socket with the Server and
        // must not close it. Only connected/client-owned sockets are closed here.
        if ($this->connected && is_resource($this->socket)) {
            $this->eventLoop?->offReadable($this->socket);
            @fclose($this->socket);
            $this->socket = null;
        }

        if ($this->onClose !== null) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    public function getRemoteIp(): string
    {
        return $this->splitAddress($this->remoteAddress)[0];
    }

    public function getRemotePort(): int
    {
        return $this->splitAddress($this->remoteAddress)[1];
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
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
        return is_resource($this->socket) ? (string)@stream_socket_get_name($this->socket, false) : '';
    }

    public function isIpV4(): bool
    {
        return $this->transport !== 'unix' && filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    public function isIpV6(): bool
    {
        return $this->transport !== 'unix' && filter_var($this->getRemoteIp(), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
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
        ];
    }

    protected function emitError(int $code, string $message): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($this, $code, $message);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    protected function formatRemoteAddress(): string
    {
        return $this->isIpV6()
            ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort()
            : $this->remoteAddress;
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
}
