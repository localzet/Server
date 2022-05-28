<?php

/**
 * @version     1.0.0-dev
 * @package     localzet V3 WebEngine
 * @link        https://v3.localzet.ru
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

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface
{
    /**
     * {@inheritdoc}
     */
    public string $transport = 'udp';

    /**
     * {@inheritdoc}
     */
    public function __construct($socket, string $remote_address)
    {
        $this->_socket        = $socket;
        $this->_remoteAddress = $remote_address;
    }

    /**
     * {@inheritdoc}
     */
    public function send(string|Response $send_buffer, bool $raw = false): bool
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }
        return \strlen($send_buffer) === \stream_socket_sendto($this->_socket, $send_buffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->_remoteAddress);
    }

    /**
     * {@inheritdoc}
     */
    public function getRemoteIp(): string
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return \trim(\substr($this->_remoteAddress, 0, $pos), '[]');
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getRemotePort(): int
    {
        if ($this->_remoteAddress) {
            return (int)\substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getRemoteAddress(): string
    {
        return $this->_remoteAddress;
    }

    /**
     * {@inheritdoc}
     */
    public function getLocalIp(): string
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
    public function getLocalPort(): int
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
    public function getLocalAddress(): string
    {
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * {@inheritdoc}
     */
    public function isIpV4(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * {@inheritdoc}
     */
    public function isIpV6(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function close(string|null $data = null, $raw = false): void
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        return;
    }

    /**
     * {@inheritdoc}
     */
    public function getSocket()
    {
        return $this->_socket;
    }
}
