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

namespace localzet;

use localzet\Server\Events\EventInterface;
use localzet\Server\Events\Select;
use localzet\Server\Events\SuspensionCapableInterface;
use RuntimeException;

/**
 * Таймеры Localzet Server.
 *
 * Например:
 * localzet\Timer::add($timeInterval, $callback, [$arg1, $arg2]);
 *
 * Начиная с 6.x таймеры всегда принадлежат event loop текущего server process.
 * Старый SIGALRM fallback больше не нужен для worker runtime: это убирает второй
 * независимый scheduler и делает семантику timer ID одинаковой для всех backend'ов.
 */
final class Timer
{
    /** Событийная петля, которой принадлежат таймеры текущего процесса. */
    protected static ?EventInterface $event = null;

    /**
     * Инициализация.
     *
     * @param EventInterface|null $event Явная петля событий или globalEvent Server.
     */
    public static function init(?EventInterface $event = null): void
    {
        self::$event = $event ?? Server::$globalEvent ?? new Select();
    }

    /**
     * Добавить повторяющийся таймер.
     *
     * @param float $timeInterval Интервал в секундах.
     * @param callable $func Callback таймера.
     * @param array $args Аргументы callback.
     * @return int Идентификатор таймера.
     */
    public static function repeat(float $timeInterval, callable $func, array $args = []): int
    {
        self::validateInterval($timeInterval);
        self::ensureEvent();
        return self::$event->repeat($timeInterval, $func, $args);
    }

    /**
     * Добавить одноразовый таймер.
     *
     * @param float $timeInterval Задержка в секундах.
     * @param callable $func Callback таймера.
     * @param array $args Аргументы callback.
     * @return int Идентификатор таймера.
     */
    public static function delay(float $timeInterval, callable $func, array $args = []): int
    {
        self::validateInterval($timeInterval);
        self::ensureEvent();
        return self::$event->delay($timeInterval, $func, $args);
    }

    /**
     * Совместимый обработчик старого SIGALRM API.
     *
     * В современной реализации отдельного alarm scheduler нет: timers обслуживает
     * EventInterface. Метод сохранён, чтобы старый пользовательский код, который
     * ссылался на Timer::signalHandle(), не падал после обновления.
     */
    public static function signalHandle(): void
    {
        // Намеренно пусто: event loop уже является единственным scheduler'ом.
    }

    /**
     * Добавить таймер.
     *
     * @param float $timeInterval Интервал/задержка в секундах.
     * @param callable $func Callback таймера.
     * @param array|null $args Аргументы callback.
     * @param bool $persistent true — повторяющийся timer, false — one-shot.
     * @return int Идентификатор таймера.
     */
    public static function add(
        float    $timeInterval,
        callable $func,
        ?array   $args = [],
        bool     $persistent = true,
    ): int
    {
        self::validateInterval($timeInterval);

        return $persistent
            ? self::repeat($timeInterval, $func, $args ?? [])
            : self::delay($timeInterval, $func, $args ?? []);
    }

    /**
     * Приостановить выполнение на указанное время.
     *
     * Для backend'ов, реализующих SuspensionCapableInterface, приостанавливается
     * только текущий Fiber/coroutine, а event loop продолжает обслуживать I/O.
     * Для обычных loop'ов используется блокирующий usleep().
     *
     * @param float $delay Задержка в секундах.
     */
    public static function sleep(float $delay): void
    {
        self::validateInterval($delay);
        if ($delay === 0.0) {
            return;
        }

        self::ensureEvent();
        if (self::$event instanceof SuspensionCapableInterface) {
            self::$event->sleep($delay);
            return;
        }

        usleep((int)round($delay * 1_000_000));
    }

    /**
     * Удалить таймер.
     *
     * @param int $timerId Идентификатор таймера.
     */
    public static function del(int $timerId): bool
    {
        self::ensureEvent();

        // Backend'ы используют единое пространство timer ID. Некоторые старые
        // реализации различали delay/repeat, поэтому сохраняем оба вызова.
        return self::$event->offDelay($timerId) || self::$event->offRepeat($timerId);
    }

    /** Удалить все таймеры текущего процесса. */
    public static function delAll(): void
    {
        self::ensureEvent();
        self::$event->deleteAllTimer();
    }

    /**
     * Получить диагностическую информацию о таймерах.
     *
     * @return array{count:int}
     */
    public static function getAll(): array
    {
        self::ensureEvent();
        return ['count' => self::$event->getTimerCount()];
    }

    /** Гарантирует наличие event loop даже при использовании Timer вне runAll(). */
    private static function ensureEvent(): void
    {
        if (self::$event === null) {
            self::init();
        }
    }

    /** Проверяет пользовательский интервал до передачи backend'у. */
    private static function validateInterval(float $interval): void
    {
        if ($interval < 0) {
            throw new RuntimeException('Timer interval cannot be negative.');
        }
    }
}
