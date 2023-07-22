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

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use JsonSerializable;
use localzet\Server\Protocols\ProtocolInterface;
use function stream_socket_get_name;
use function stream_socket_sendto;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function trim;

/**
 * UDP-соединение.
 */
class UdpConnection extends ConnectionInterface implements JsonSerializable
{
    /**
     * Максимальный размер пакета UDP.
     *
     * @var int
     */
    public const MAX_UDP_PACKAGE_SIZE = 65535;

    /**
     * Протокол транспортного уровня.
     *
     * @var string
     */
    public string $transport = 'udp';

    /**
     * UDP-сокет.
     *
     * @var resource
     */
    protected $socket;

    /**
     * Удаленный адрес.
     *
     * @var string
     */
    protected string $remoteAddress = '';

    /**
     * Конструктор.
     *
     * @param resource $socket
     * @param string $remoteAddress
     */
    public function __construct($socket, string $remoteAddress)
    {
        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * @inheritdoc
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop = $this->errorHandler = null;
    }

    /**
     * @inheritdoc
     */
    public function send(mixed $sendBuffer, bool $raw = false)
    {
        if (false === $raw && $this->protocol) {
            /** @var ProtocolInterface $parser */
            $parser = $this->protocol;
            $sendBuffer = $parser::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return;
            }
        }
        return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->remoteAddress);
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
     * @inheritdoc
     */
    public function getRemoteIp(): string
    {
        $pos = strrpos($this->remoteAddress, ':');
        if ($pos) {
            return trim(substr($this->remoteAddress, 0, $pos), '[]');
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
     * Получает реальный сокет.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Получает информацию для json_encode.
     *
     * @return array
     */
    #[ArrayShape(
        [
            'transport' => "string",
            'getRemoteIp' => "string",
            'remotePort' => "int",
            'getRemoteAddress' => "string",
            'getLocalIp' => "string",
            'getLocalPort' => "int",
            'getLocalAddress' => "string",
            'isIpV4' => "bool",
            'isIpV6' => "bool"
        ]
    )]
    public function jsonSerialize(): array
    {
        return [
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
}
