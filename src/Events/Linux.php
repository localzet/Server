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

namespace localzet\Server\Events;

/**
 * Compatibility backend для старого `localzet\\Server\\Events\\Linux` API.
 *
 * Исторически Linux был отдельной реализацией event loop. В 6.2 класс оставлен
 * как BC-слой, но больше не означает "Revolt под другим именем": внутри он
 * выбирает лучший безопасный Unix backend через EventLoopFactory.
 */
final class Linux implements EventInterface
{
    private EventInterface $backend;

    public function __construct()
    {
        $this->backend = EventLoopFactory::create('linux');
    }

    /** Возвращает фактический backend, выбранный compatibility-wrapper'ом. */
    public function backend(): EventInterface
    {
        return $this->backend;
    }

    public function run(): void
    {
        $this->backend->run();
    }

    public function stop(): void
    {
        $this->backend->stop();
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        return $this->backend->delay($delay, $callback, $args);
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        return $this->backend->repeat($interval, $callback, $args);
    }

    public function offDelay(int $timerId): bool
    {
        return $this->backend->offDelay($timerId);
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->backend->offRepeat($timerId);
    }

    public function deleteAllTimer(): void
    {
        $this->backend->deleteAllTimer();
    }

    public function getTimerCount(): int
    {
        return $this->backend->getTimerCount();
    }

    public function onReadable($stream, callable $callback): void
    {
        $this->backend->onReadable($stream, $callback);
    }

    public function offReadable($stream): bool
    {
        return $this->backend->offReadable($stream);
    }

    public function onWritable($stream, callable $callback): void
    {
        $this->backend->onWritable($stream, $callback);
    }

    public function offWritable($stream): bool
    {
        return $this->backend->offWritable($stream);
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->backend->onSignal($signal, $callback);
    }

    public function offSignal(int $signal): bool
    {
        return $this->backend->offSignal($signal);
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->backend->setErrorHandler($errorHandler);
    }
}
