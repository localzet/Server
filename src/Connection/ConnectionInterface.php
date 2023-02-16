<?php

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

use Throwable;

use localzet\Server\Events\EventInterface;
use localzet\Server\Events\Event;

use localzet\Server\Server;

/**
 * ConnectionInterface.
 */
#[\AllowDynamicProperties]
abstract class ConnectionInterface
{
    /**
     * Connect failed.
     *
     * @var int
     */
    const CONNECT_FAIL = 1;

    /**
     * Send failed.
     *
     * @var int
     */
    const SEND_FAIL = 2;

    /**
     * Statistics for status command.
     *
     * @var array
     */
    public static array $statistics = [
        'connection_count' => 0,
        'total_request' => 0,
        'throw_exception' => 0,
        'send_fail' => 0,
    ];

    /**
     * Application layer protocol.
     * The format is like this localzet\\Server\\Protocols\\Http.
     *
     * @var ?string
     */
    public ?string $protocol = null;

    /**
     * Emitted when data is received.
     *
     * @var ?callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var ?callable
     */
    public $onError = null;

    /**
     * @var ?EventInterface
     */
    public ?EventInterface $eventLoop = null;

    /**
     * @var ?callable
     */
    public $errorHandler = null;

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false);

    /**
     * Get remote IP.
     *
     * @return string
     */
    abstract public function getRemoteIp(): string;

    /**
     * Get remote port.
     *
     * @return int
     */
    abstract public function getRemotePort(): int;

    /**
     * Get remote address.
     *
     * @return string
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Get local IP.
     *
     * @return string
     */
    abstract public function getLocalIp(): string;

    /**
     * Get local port.
     *
     * @return int
     */
    abstract public function getLocalPort(): int;

    /**
     * Get local address.
     *
     * @return string
     */
    abstract public function getLocalAddress(): string;

    /**
     * Close connection.
     *
     * @param mixed|null $data
     * @return void
     */
    abstract public function close(mixed $data = null, bool $raw = false);

    /**
     * Is ipv4.
     *
     * @return bool
     */
    abstract public function isIpV4(): bool;

    /**
     * Is ipv6.
     *
     * @return bool
     */
    abstract public function isIpV6(): bool;

    /**
     * @param Throwable $exception
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $exception)
    {
        if (!$this->errorHandler) {
            Server::stopAll(250, $exception);
            return;
        }
        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $exception) {
            if ($this->eventLoop instanceof Event) {
                echo $exception;
                return;
            }
            throw $exception;
        }
    }
}
