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

use Exception;
use Throwable;
use localzet\Core\Protocols\ProtocolInterface;
use localzet\Core\Server;
use function class_exists;
use function explode;
use function fclose;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function strlen;
use function substr;
use function ucfirst;
use const STREAM_CLIENT_CONNECT;

/**
 * AsyncUdpConnection.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Emitted when socket connection closed.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Connected or not.
     *
     * @var bool
     */
    protected bool $connected = false;

    /**
     * Context option.
     *
     * @var array
     */
    protected array $contextOption = [];

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @throws Exception
     */
    public function __construct($remoteAddress, $contextOption = [])
    {
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = explode(':', $remoteAddress, 2);
        // Check application layer protocol class.
        if ($scheme !== 'udp') {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Core\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }

        $this->remoteAddress = substr($address, 2);
        $this->contextOption = $contextOption;
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return void
     * @throws Throwable
     */
    public function baseRead($socket)
    {
        $recvBuffer = stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        if (false === $recvBuffer || empty($remoteAddress)) {
            return;
        }

        if ($this->onMessage) {
            if ($this->protocol) {
                /** @var ProtocolInterface $parser */
                $parser = $this->protocol;
                $recvBuffer = $parser::decode($recvBuffer, $this);
            }
            ++ConnectionInterface::$statistics['total_request'];
            try {
                ($this->onMessage)($this, $recvBuffer);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Close connection.
     *
     * @param mixed|null $data
     * @param bool $raw
     * @return void
     * @throws Throwable
     */
    public function close(mixed $data = null, bool $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop->offReadable($this->socket);
        fclose($this->socket);
        $this->connected = false;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        $this->onConnect = $this->onMessage = $this->onClose = $this->eventLoop = $this->errorHandler = null;
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
     * @throws Throwable
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
        if ($this->connected === false) {
            $this->connect();
        }
        return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer, 0);
    }

    /**
     * Connect.
     *
     * @return void
     * @throws Throwable
     */
    public function connect()
    {
        if ($this->connected === true) {
            return;
        }
        if (!$this->eventLoop) {
            $this->eventLoop = Server::$globalEvent;
        }
        if ($this->contextOption) {
            $context = stream_context_create($this->contextOption);
            $this->socket = stream_socket_client(
                "udp://$this->remoteAddress",
                $errno,
                $errmsg,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            $this->socket = stream_socket_client("udp://$this->remoteAddress", $errno, $errmsg);
        }

        if (!$this->socket) {
            Server::safeEcho(new Exception($errmsg));
            $this->eventLoop = null;
            return;
        }

        stream_set_blocking($this->socket, false);
        if ($this->onMessage) {
            $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
        }
        $this->connected = true;
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }
}
