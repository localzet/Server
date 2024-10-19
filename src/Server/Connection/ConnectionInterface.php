<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Connection;

use localzet\Server;
use localzet\Server\Events\Event;
use localzet\Server\Events\EventInterface;
use Throwable;

/**
 * ConnectionInterface.
 */
#[\AllowDynamicProperties]
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

    public ?EventInterface $eventLoop = null;

    /**
     * @var ?callable
     */
    public $errorHandler = null;

    /**
     * Отправляет данные по соединению.
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false): bool|null;

    /**
     * Получить удаленный IP-адрес.
     */
    abstract public function getRemoteIp(): string;

    /**
     * Получить удаленный порт.
     */
    abstract public function getRemotePort(): int;

    /**
     * Получить удаленный адрес.
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Получить локальный IP-адрес.
     */
    abstract public function getLocalIp(): string;

    /**
     * Получить локальный порт.
     */
    abstract public function getLocalPort(): int;

    /**
     * Получить локальный адрес.
     */
    abstract public function getLocalAddress(): string;

    /**
     * Закрыть соединение.
     */
    abstract public function close(mixed $data = null, bool $raw = false): void;

    /**
     * Является ли адрес IPv4.
     */
    abstract public function isIpV4(): bool;

    /**
     * Является ли адрес IPv6.
     */
    abstract public function isIpV6(): bool;

    /**
     * @throws Throwable
     */
    public function error(Throwable $exception): void
    {
        if (!$this->errorHandler) {
            Server::stopAll(250, $exception);
            return;
        }
        
        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $throwable) {
            if ($this->eventLoop instanceof Event) {
                echo $throwable;
                return;
            }
            
            throw $throwable;
        }
    }
}
