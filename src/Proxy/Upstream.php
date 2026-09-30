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

namespace localzet\Server\Proxy;

use InvalidArgumentException;

/**
 * Описание одного upstream-узла.
 *
 * Объект хранит не только адрес backend-а, но и runtime-состояние,
 * необходимое балансировщику: число активных соединений, серию ошибок,
 * quarantine deadline и накопительную статистику.
 */
final class Upstream
{
    /** Адрес в формате tcp://host:port, ssl://host:port или unix:///path. */
    public readonly string $address;

    /** Вес backend-а для weighted round-robin. */
    public readonly int $weight;

    /** Произвольное человекочитаемое имя для status/diagnostics. */
    public readonly string $name;

    /** Число текущих соединений, закреплённых за этим upstream. */
    public int $activeConnections = 0;

    /** Сколько соединений было успешно установлено за время жизни worker. */
    public int $successfulConnections = 0;

    /** Сколько transport-level попыток завершилось ошибкой. */
    public int $failedConnections = 0;

    /** Последовательное число ошибок с момента последнего успеха. */
    public int $consecutiveFailures = 0;

    /** UNIX timestamp, до которого backend исключён из выбора. */
    public float $quarantineUntil = 0.0;

    public function __construct(string $address, int $weight = 1, string $name = '')
    {
        $address = trim($address);
        if ($address === '') {
            throw new InvalidArgumentException('Upstream address must not be empty.');
        }
        if ($weight < 1) {
            throw new InvalidArgumentException('Upstream weight must be greater than zero.');
        }

        $this->address = str_contains($address, '://') ? $address : 'tcp://' . $address;
        $this->weight = $weight;
        $this->name = $name !== '' ? $name : $this->address;
    }

    /** Backend доступен для новой попытки прямо сейчас? */
    public function isAvailable(?float $now = null): bool
    {
        return ($now ?? microtime(true)) >= $this->quarantineUntil;
    }

    /** Фиксирует успешное соединение и снимает passive quarantine. */
    public function markSuccess(): void
    {
        $this->successfulConnections++;
        $this->consecutiveFailures = 0;
        $this->quarantineUntil = 0.0;
    }

    /**
     * Фиксирует transport-level ошибку.
     *
     * Quarantine включается не после первой ошибки: одиночный ECONNRESET не
     * должен выбрасывать здоровый backend из pool. Порог и время задаёт pool.
     */
    public function markFailure(int $failureThreshold, float $quarantineSeconds): void
    {
        $this->failedConnections++;
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= max(1, $failureThreshold)) {
            $this->quarantineUntil = microtime(true) + max(0.0, $quarantineSeconds);
        }
    }

    /** @return array<string, int|float|string|bool> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'weight' => $this->weight,
            'available' => $this->isAvailable(),
            'activeConnections' => $this->activeConnections,
            'successfulConnections' => $this->successfulConnections,
            'failedConnections' => $this->failedConnections,
            'consecutiveFailures' => $this->consecutiveFailures,
            'quarantineUntil' => $this->quarantineUntil,
        ];
    }
}
