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

use Throwable;

/**
 * Интерфейс событийной петли Localzet Server.
 *
 * Контракт намеренно остаётся небольшим: таймеры, I/O watchers, signals и
 * обработка исключений. Благодаря этому Connection/Protocol слой не зависит
 * от конкретной реализации event loop, а backend можно выбирать во время запуска.
 */
interface EventInterface
{
    /**
     * Задержать выполнение callback на указанное время.
     *
     * @param float $delay Задержка в секундах.
     * @param callable(mixed...): void $func Callback, который нужно выполнить.
     * @param array $args Аргументы, передаваемые в callback.
     * @return int Идентификатор таймера.
     */
    public function delay(float $delay, callable $func, array $args = []): int;

    /**
     * Отменить таймер задержки.
     *
     * @param int $timerId Идентификатор таймера.
     * @return bool true, если таймер существовал и был отменён.
     */
    public function offDelay(int $timerId): bool;

    /**
     * Повторно выполнять callback через указанный интервал времени.
     *
     * @param float $interval Интервал в секундах.
     * @param callable(mixed...): void $func Callback, который нужно выполнить.
     * @param array $args Аргументы, передаваемые в callback.
     * @return int Идентификатор таймера.
     */
    public function repeat(float $interval, callable $func, array $args = []): int;

    /**
     * Отменить повторяющийся таймер.
     *
     * @param int $timerId Идентификатор таймера.
     * @return bool true, если таймер существовал и был отменён.
     */
    public function offRepeat(int $timerId): bool;

    /**
     * Зарегистрировать callback при готовности потока к чтению или его закрытии.
     *
     * @param resource $stream Поток для наблюдения.
     * @param callable(resource): void $func Callback чтения.
     */
    public function onReadable($stream, callable $func): void;

    /**
     * Удалить watcher чтения.
     *
     * @param resource $stream Поток.
     * @return bool true, если watcher существовал.
     */
    public function offReadable($stream): bool;

    /**
     * Зарегистрировать callback при готовности потока к записи или его закрытии.
     *
     * @param resource $stream Поток для наблюдения.
     * @param callable(resource): void $func Callback записи.
     */
    public function onWritable($stream, callable $func): void;

    /**
     * Удалить watcher записи.
     *
     * @param resource $stream Поток.
     * @return bool true, если watcher существовал.
     */
    public function offWritable($stream): bool;

    /**
     * Зарегистрировать callback системного сигнала.
     *
     * @param int $signal Номер сигнала.
     * @param callable(int): void $func Callback сигнала.
     * @throws Throwable
     */
    public function onSignal(int $signal, callable $func): void;

    /**
     * Удалить watcher системного сигнала.
     *
     * @param int $signal Номер сигнала.
     * @return bool true, если watcher существовал.
     */
    public function offSignal(int $signal): bool;

    /**
     * Запустить цикл обработки событий.
     *
     * Метод блокирует текущий main context до stop() либо до естественного
     * завершения выбранного backend'а.
     *
     * @throws Throwable
     */
    public function run(): void;

    /** Остановить цикл событий. */
    public function stop(): void;

    /** Удалить все зарегистрированные таймеры. */
    public function deleteAllTimer(): void;

    /** @return int Количество активных таймеров. */
    public function getTimerCount(): int;

    /**
     * Установить обработчик исключений из event callbacks.
     *
     * @param callable(Throwable): void $errorHandler Обработчик ошибок.
     */
    public function setErrorHandler(callable $errorHandler): void;
}
