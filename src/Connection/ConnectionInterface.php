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

use AllowDynamicProperties;
use localzet\Server;
use localzet\Server\Events\EventInterface;
use Throwable;

/**
 * Базовый контракт соединений Localzet Server.
 *
 * Исторически это именно abstract class, а не PHP interface. Сохраняем эту
 * модель для совместимости с Localzet-подобным пользовательским
 * кодом и держим здесь общие callbacks, statistics и error policy.
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
     * Статистика соединений текущего процесса.
     */
    public static array $statistics = [
        'connection_count' => 0,   // currently open
        'connection_total' => 0,   // created during this worker lifetime
        'connection_rejected' => 0,
        'total_request' => 0,
        'throw_exception' => 0,
        'send_fail' => 0,
        'timeout' => 0,
        'bytes_read' => 0,
        'bytes_written' => 0,
    ];

    /** @var ?class-string Прикладной протокол. */
    public ?string $protocol = null;

    public $onMessage = null;
    public $onClose = null;
    public $onError = null;

    /** Event loop, обслуживающая конкретное соединение. */
    public ?EventInterface $eventLoop = null;

    /** Пользовательский обработчик исключений connection layer. */
    public $errorHandler = null;

    abstract public function send(mixed $sendBuffer, bool $raw = false): bool|null;

    abstract public function close(mixed $data = null, bool $raw = false): void;

    abstract public function getRemoteIp(): string;

    abstract public function getRemotePort(): int;

    abstract public function getRemoteAddress(): string;

    abstract public function getLocalIp(): string;

    abstract public function getLocalPort(): int;

    abstract public function getLocalAddress(): string;

    abstract public function isIpV4(): bool;

    abstract public function isIpV6(): bool;

    /**
     * Передаёт исключение пользовательскому error handler либо останавливает
     * процесс с кодом 250, если обработчик отсутствует/сам выбросил исключение.
     */
    public function error(Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            Server::stopAll(250, $exception);
            return;
        }

        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $handlerException) {
            Server::stopAll(250, $handlerException);
        }
    }
}
