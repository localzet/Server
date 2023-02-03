<?php

/**
 * @package     Triangle Server (WebCore)
 * @link        https://github.com/localzet/WebCore
 * @link        https://github.com/Triangle-org/Server
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Core\Connection;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use JsonSerializable;
use localzet\Core\Protocols\ProtocolInterface;
use function stream_socket_get_name;
use function stream_socket_sendto;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function trim;

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface implements JsonSerializable
{
    /**
     * Max udp package size.
     *
     * @var int
     */
    const MAX_UDP_PACKAGE_SIZE = 65535;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public string $transport = 'udp';

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $socket;

    /**
     * Remote address.
     *
     * @var string
     */
    protected string $remoteAddress = '';

    /**
     * Construct.
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
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
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
     * Get remote IP.
     *
     * @return string
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
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort(): int
    {
        if ($this->remoteAddress) {
            return (int)substr(strrchr($this->remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Get local IP.
     *
     * @return string
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
     * Get local port.
     *
     * @return int
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
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress(): string
    {
        return (string)@stream_socket_get_name($this->socket, false);
    }


    /**
     * Close connection.
     *
     * @param mixed|null $data
     * @return void
     */
    public function close(mixed $data = null, bool $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop = $this->errorHandler = null;
    }

    /**
     * Is ipv4.
     *
     * @return bool.
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
     * Is ipv6.
     *
     * @return bool.
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
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Get the json_encode information.
     *
     * @return array
     */
    #[ArrayShape(['transport' => "string", 'getRemoteIp' => "string", 'remotePort' => "int", 'getRemoteAddress' => "string", 'getLocalIp' => "string", 'getLocalPort' => "int", 'getLocalAddress' => "string", 'isIpV4' => "bool", 'isIpV6' => "bool"])] public function jsonSerialize(): array
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
}
