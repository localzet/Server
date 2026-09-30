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

namespace localzet\Server\Protocols\Http\Session;

/**
 * Хранилище HTTP-сессий Localzet Server.
 *
 * Контракт намеренно совместим с современным PHP session handler API:
 * handler умеет не только читать/писать данные, но и обновлять timestamp без
 * полной перезаписи payload. Это важно для долгоживущих сессий и Redis/Mongo TTL.
 */
interface SessionHandlerInterface
{
    public function open(string $savePath, string $name): bool;

    public function close(): bool;

    public function read(string $sessionId): string|false;

    public function write(string $sessionId, string $sessionData): bool;

    public function destroy(string $sessionId): bool;

    public function gc(int $maxLifetime): bool;

    public function updateTimestamp(string $sessionId, string $data = ''): bool;
}
