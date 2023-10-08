<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
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

namespace localzet\Server\Connection;

use Exception;
use localzet\Server;
use localzet\Server\Protocols\ProtocolInterface;
use Throwable;
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
 * Асинхронное UDP-соединение.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Событие вызывается, когда соединение с сокетом успешно установлено.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Событие вызывается при закрытии сокета и разрыве соединения.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Признак установленного соединения.
     *
     * @var bool
     */
    protected bool $connected = false;

    /**
     * Опции контекста.
     *
     * @var array
     */
    protected array $contextOption = [];

    /**
     * Конструктор.
     *
     * @param string $remoteAddress
     * @throws Exception
     */
    public function __construct($remoteAddress, $contextOption = [])
    {
        // Получаем протокол связи уровня приложения и адрес прослушивания.
        [$scheme, $address] = explode(':', $remoteAddress, 2);
        // Проверяем класс протокола связи уровня приложения.
        if ($scheme !== 'udp') {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Server\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("Класс \\Protocols\\$scheme не существует");
                }
            }
        }

        $this->remoteAddress = substr($address, 2);
        $this->contextOption = $contextOption;
    }

    /**
     * Для пакетов UDP.
     *
     * @param resource $socket
     * @return void
     * @throws Throwable
     */
    public function baseRead($socket): void
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
     * Закрыть соединение.
     *
     * @param mixed|null $data
     * @param bool $raw
     * @return void
     * @throws Throwable
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop->offReadable($this->socket);
        fclose($this->socket);
        $this->connected = false;
        // Попытка вызова обработчика события onClose.
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
     * Отправить данные по соединению.
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
        return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer);
    }

    /**
     * Установить соединение.
     *
     * @return void
     * @throws Throwable
     */
    public function connect(): void
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
            Server::safeEcho((string)(new Exception($errmsg)));
            $this->eventLoop = null;
            return;
        }

        stream_set_blocking($this->socket, false);
        if ($this->onMessage) {
            $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
        }
        $this->connected = true;
        // Попытка вызова обработчика события onConnect.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }
}