<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Connection;

use JetBrains\PhpStorm\{Pure};
use JsonSerializable;
use localzet\Protocols\ProtocolInterface;
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
     */
    public string $transport = 'udp';

    /**
     * Конструктор.
     *
     * @param resource $socket
     */
    public function __construct(
        /**
         * UDP-сокет.
         */
        protected        $socket,
        /**
         * Удаленный адрес.
         */
        protected string $remoteAddress
    )
    {
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
    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        if (false === $raw && $this->protocol) {
            $sendBuffer = $this->protocol::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return null;
            }
        }

        return strlen((string)$sendBuffer) === stream_socket_sendto($this->socket, (string)$sendBuffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->remoteAddress);
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
     */
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
