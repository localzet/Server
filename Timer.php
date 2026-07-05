<?php

declare(strict_types=1);

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

namespace localzet;

use localzet\Server\Events\EventInterface;
use localzet\Server\Events\Linux;
use localzet\Server\Events\Swoole;
use RuntimeException;
use Swoole\Coroutine\System;
use Throwable;

use function function_exists;
use function pcntl_alarm;
use function pcntl_signal;

use const PHP_INT_MAX;
use const SIGALRM;

/**
 * Таймер
 *
 * Например:
 * localzet\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * Задачи, основанные на сигнале ALARM
     * [
     *   run_time => [[$func, $args, $persistent, time_interval], ... ],
     *   ...
     * ]
     */
    protected static array $tasks = [];

    /**
     * Событие
     */
    protected static ?EventInterface $event = null;

    /**
     * ID таймера
     */
    protected static int $timerId = 0;

    /**
     * Статус таймеров
     * [
     *   timer_id => bool,
     *   ...
     * ]
     */
    protected static array $status = [];

    /**
     * Инициализация
     *
     * @param EventInterface|null $event
     */
    public static function init(?EventInterface $event = null): void
    {
        if ($event) {
            self::$event = $event;
            return;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, self::signalHandle(...), false);
        }
    }

    /**
     * Repeat.
     *
     * @param float $timeInterval
     * @param callable $func
     * @param array $args
     * @return int
     */
    public static function repeat(float $timeInterval, callable $func, array $args = []): int
    {
        return self::$event->repeat($timeInterval, $func, $args);
    }

    /**
     * Delay.
     *
     * @param float $timeInterval
     * @param callable $func
     * @param array $args
     * @return int
     */
    public static function delay(float $timeInterval, callable $func, array $args = []): int
    {
        return self::$event->delay($timeInterval, $func, $args);
    }

    /**
     * Обработчик сигнала
     */
    public static function signalHandle(): void
    {
        if (!self::$event) {
            pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Добавить таймер
     */
    public static function add(float $timeInterval, callable $func, ?array $args = [], bool $persistent = true): int
    {
        if ($timeInterval < 0) {
            throw new RuntimeException('$timeInterval не может быть меньше 0');
        }

        $args ??= [];

        if (self::$event) {
            return $persistent
                ? self::$event->repeat($timeInterval, $func, $args)
                : self::$event->delay($timeInterval, $func, $args);
        }

        if (!Server::getAllServers()) {
            throw new RuntimeException('Таймер может использоваться только в окружении Localzet');
        }

        if (empty(self::$tasks)) {
            pcntl_alarm(1);
        }

        $runTime = time() + $timeInterval;
        if (!isset(self::$tasks[$runTime])) {
            self::$tasks[$runTime] = [];
        }

        self::$timerId = self::$timerId === PHP_INT_MAX ? 1 : ++self::$timerId;
        self::$status[self::$timerId] = true;
        self::$tasks[$runTime][self::$timerId] = [$func, $args, $persistent, $timeInterval];

        return self::$timerId;
    }

    /**
     * Приостановить выполнение на указанное время (для корутин).
     *
     * @param float $delay Задержка в секундах.
     * @throws Throwable
     */
    public static function sleep(float $delay): void
    {
        if ($delay < 0) {
            throw new RuntimeException('$delay не может быть меньше 0');
        }

        if ($delay === 0.0) {
            return;
        }

        switch (Server::$eventLoopClass) {
            case Linux::class:
                if (Server::$globalEvent === null) {
                    throw new RuntimeException('Глобальный цикл событий не инициализирован');
                }
                $suspension = Server::$globalEvent->getSuspension();
                static::add($delay, function () use ($suspension): void {
                    $suspension->resume();
                }, [], false);
                $suspension->suspend();
                return;
            case Swoole::class:
                System::sleep($delay);
                return;
            default:
                usleep((int)($delay * 1000000));
                return;
        }
    }

    /**
     * Тик
     */
    protected static function tick(): void
    {
        if (empty(self::$tasks)) {
            pcntl_alarm(0);
            return;
        }

        $timeNow = time();
        foreach (self::$tasks as $runTime => $taskData) {
            if ($timeNow >= $runTime) {
                foreach ($taskData as $index => $oneTask) {
                    [$taskFunc, $taskArgs, $persistent, $timeInterval] = $oneTask;
                    try {
                        $taskFunc(...$taskArgs);
                    } catch (Throwable $e) {
                        Server::safeEcho((string)$e);
                    }

                    if ($persistent && !empty(self::$status[$index])) {
                        $newRunTime = time() + $timeInterval;
                        if (!isset(self::$tasks[$newRunTime])) {
                            self::$tasks[$newRunTime] = [];
                        }

                        self::$tasks[$newRunTime][$index] = [$taskFunc, $taskArgs, $persistent, $timeInterval];
                    }
                }

                unset(self::$tasks[$runTime]);
            }
        }
    }

    /**
     * Удалить таймер
     */
    public static function del(int $timerId): bool
    {
        if (self::$event) {
            return self::$event->offDelay($timerId);
        }

        foreach (self::$tasks as $runTime => $taskData) {
            if (isset($taskData[$timerId])) {
                unset(self::$tasks[$runTime][$timerId]);
            }
        }

        unset(self::$status[$timerId]);

        return true;
    }

    /**
     * Удалить все таймеры
     */
    public static function delAll(): void
    {
        self::$tasks = self::$status = [];
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        self::$event?->deleteAllTimer();
    }
}
