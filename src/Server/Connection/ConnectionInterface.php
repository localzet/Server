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

namespace localzet\Server\Connection;

use AllowDynamicProperties;
use localzet\Server;
use localzet\Server\Events\EventInterface;
use Throwable;

/**
 * ConnectionInterface.
 */
#[AllowDynamicProperties]
abstract class ConnectionInterface
{
    /**
     * Соединение не удалось.
     *
     * @var int
     */
    public const CONNECT_FAIL = 1;

    /**
     * Ошибка отправки данных.
     *
     * @var int
     */
    public const SEND_FAIL = 2;

    /**
     * Статистика для команды status.
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
     * Протокол прикладного уровня.
     * Формат аналогичен localzet\\Server\\Protocols\\Http.
     *
     * @var ?string
     */
    public ?string $protocol = null;

    /**
     * Вызывается при получении данных.
     *
     * @var ?callable
     */
    public $onMessage = null;

    /**
     * Вызывается, когда другой конец сокета отправляет пакет FIN.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Вызывается, когда возникает ошибка соединения.
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
     * Отправляет данные по соединению.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false);

    /**
     * Получить удаленный IP-адрес.
     *
     * @return string
     */
    abstract public function getRemoteIp(): string;

    /**
     * Получить удаленный порт.
     *
     * @return int
     */
    abstract public function getRemotePort(): int;

    /**
     * Получить удаленный адрес.
     *
     * @return string
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Получить локальный IP-адрес.
     *
     * @return string
     */
    abstract public function getLocalIp(): string;

    /**
     * Получить локальный порт.
     *
     * @return int
     */
    abstract public function getLocalPort(): int;

    /**
     * Получить локальный адрес.
     *
     * @return string
     */
    abstract public function getLocalAddress(): string;

    /**
     * Закрыть соединение.
     *
     * @param mixed|null $data
     * @param bool $raw
     * @return void
     */
    abstract public function close(mixed $data = null, bool $raw = false): void;

    /**
     * Является ли адрес IPv4.
     *
     * @return bool
     */
    abstract public function isIpV4(): bool;

    /**
     * Является ли адрес IPv6.
     *
     * @return bool
     */
    abstract public function isIpV6(): bool;

    /**
     * @param Throwable $exception
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $exception): void
    {
        if (!$this->errorHandler) {
            Server::stopAll(250, $exception);
            return;
        }

        ($this->errorHandler)($exception);
    }
}
