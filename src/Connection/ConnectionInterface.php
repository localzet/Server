<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2025 Localzet Group
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
use localzet\Server\Events\Event;
use localzet\Server\Events\EventInterface;
use localzet\Server;
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
     * @var ?callable(ConnectionInterface, mixed): void
     */
    public $onMessage = null;

    /**
     * Вызывается, когда другой конец сокета отправляет пакет FIN.
     *
     * @var ?callable(ConnectionInterface): void
     */
    public $onClose = null;

    /**
     * Вызывается, когда возникает ошибка соединения.
     *
     * @var ?callable(ConnectionInterface, int, string): void
     */
    public $onError = null;

    /**
     * Цикл событий для обработки асинхронных операций.
     */
    public ?EventInterface $eventLoop = null;

    /**
     * Обработчик ошибок для соединения.
     *
     * @var ?callable(Throwable): void
     */
    public $errorHandler = null;

    /**
     * Отправляет данные по соединению.
     *
     * @param mixed $sendBuffer Данные для отправки.
     * @param bool $raw Отправлять данные в сыром виде (без кодирования протоколом).
     * @return bool|null true в случае успеха, false в случае неудачи, null если буфер полон.
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false): bool|null;

    /**
     * Получить удаленный IP-адрес.
     *
     * @return string IP-адрес клиента.
     */
    abstract public function getRemoteIp(): string;

    /**
     * Получить удаленный порт.
     *
     * @return int Порт клиента.
     */
    abstract public function getRemotePort(): int;

    /**
     * Получить удаленный адрес (IP:порт).
     *
     * @return string Полный адрес клиента в формате "IP:порт".
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Получить локальный IP-адрес.
     *
     * @return string IP-адрес сервера.
     */
    abstract public function getLocalIp(): string;

    /**
     * Получить локальный порт.
     *
     * @return int Порт сервера.
     */
    abstract public function getLocalPort(): int;

    /**
     * Получить локальный адрес (IP:порт).
     *
     * @return string Полный адрес сервера в формате "IP:порт".
     */
    abstract public function getLocalAddress(): string;

    /**
     * Закрыть соединение.
     *
     * @param mixed $data Опциональные данные для отправки перед закрытием.
     * @param bool $raw Отправлять данные в сыром виде.
     */
    abstract public function close(mixed $data = null, bool $raw = false): void;

    /**
     * Является ли адрес IPv4.
     *
     * @return bool true если адрес IPv4, иначе false.
     */
    abstract public function isIpV4(): bool;

    /**
     * Является ли адрес IPv6.
     *
     * @return bool true если адрес IPv6, иначе false.
     */
    abstract public function isIpV6(): bool;

    /**
     * Обработать ошибку соединения.
     *
     * @param Throwable $exception Исключение для обработки.
     * @throws Throwable Если обработчик ошибок не установлен или выбросил исключение в синхронном контексте.
     */
    public function error(Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            Server::stopAll(250, $exception);
            return;
        }

        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $throwable) {
            // В асинхронном контексте просто логируем, иначе пробрасываем дальше
            if ($this->eventLoop instanceof Event) {
                Server::safeEcho((string)$throwable);
                return;
            }

            throw $throwable;
        }
    }
}
