<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server;

use Exception;
use localzet\Server\Events\EventInterface;
use localzet\Server\Events\Revolt;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use function function_exists;
use function is_callable;
use function pcntl_alarm;
use function pcntl_signal;
use function time;
use const PHP_INT_MAX;
use const SIGALRM;

/**
 * Таймер
 *
 * Например:
 * localzet\Server\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * Задачи, основанные на сигнале
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     * ]
     *
     * @var array
     */
    protected static array $tasks = [];

    /**
     * Событие
     *
     * @var ?EventInterface
     */
    protected static ?EventInterface $event = null;

    /**
     * ID Таймера
     *
     * @var int
     */
    protected static int $timerId = 0;

    /**
     * Статус таймера
     * [
     *   timer_id1 => bool,
     *   timer_id2 => bool,
     * ]
     *
     * @var array
     */
    protected static array $status = [];

    /**
     * Инициализация
     *
     * @param EventInterface|null $event
     * @return void
     */
    public static function init(EventInterface $event = null): void
    {
        if ($event) {
            self::$event = $event;
            return;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, ['\localzet\Server\Timer', 'signalHandle'], false);
        }
    }

    /**
     * Обработчик сигнала
     *
     * @return void
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
     *
     * @param float $timeInterval
     * @param callable $func
     * @param mixed|array $args
     * @param bool $persistent
     * @return int|bool
     */
    public static function add(float $timeInterval, callable $func, null|array $args = [], bool $persistent = true): int|bool
    {
        if ($timeInterval < 0) {
            throw new RuntimeException('$timeInterval не может быть меньше 0');
        }

        if ($args === null) {
            $args = [];
        }

        if (self::$event) {
            return $persistent ? self::$event->repeat($timeInterval, $func, $args) : self::$event->delay($timeInterval, $func, $args);
        }

        if (!Server::getAllServers()) {
            return false;
        }

        if (!is_callable($func)) {
            Server::safeEcho((string)new Exception("Невозможно вызвать функцию"));
            return false;
        }

        if (empty(self::$tasks)) {
            pcntl_alarm(1);
        }

        $runTime = time() + $timeInterval;
        if (!isset(self::$tasks[$runTime])) {
            self::$tasks[$runTime] = [];
        }

        self::$timerId = self::$timerId == PHP_INT_MAX ? 1 : ++self::$timerId;
        self::$status[self::$timerId] = true;
        self::$tasks[$runTime][self::$timerId] = [$func, (array)$args, $persistent, $timeInterval];

        return self::$timerId;
    }

    /**
     * Coroutine sleep.
     *
     * @param float $delay
     * @return void
     */
    public static function sleep(float $delay): void
    {
        if (Server::$eventLoopClass == Revolt::class) {
            $suspension = EventLoop::getSuspension();
            static::add($delay, function () use ($suspension) {
                $suspension->resume();
            }, null, false);
            $suspension->suspend();
            return;
        }
        throw new RuntimeException('Timer::sleep() требует revolt/event-loop. Запусти команду "composer require revolt/event-loop" и перезагрузи сервер');
    }

    /**
     * Тик
     *
     * @return void
     */
    public static function tick(): void
    {
        if (empty(self::$tasks)) {
            pcntl_alarm(0);
            return;
        }
        $timeNow = time();
        foreach (self::$tasks as $runTime => $taskData) {
            if ($timeNow >= $runTime) {
                foreach ($taskData as $index => $oneTask) {
                    $taskFunc = $oneTask[0];
                    $taskArgs = $oneTask[1];
                    $persistent = $oneTask[2];
                    $timeInterval = $oneTask[3];
                    try {
                        $taskFunc(...$taskArgs);
                    } catch (Throwable $e) {
                        Server::safeEcho((string)$e);
                    }
                    if ($persistent && !empty(self::$status[$index])) {
                        $newRunTime = time() + $timeInterval;
                        if (!isset(self::$tasks[$newRunTime])) self::$tasks[$newRunTime] = [];
                        self::$tasks[$newRunTime][$index] = [$taskFunc, (array)$taskArgs, $persistent, $timeInterval];
                    }
                }
                unset(self::$tasks[$runTime]);
            }
        }
    }

    /**
     * Удалить таймер
     *
     * @param int $timerId
     * @return bool
     */
    public static function del(int $timerId): bool
    {
        if (self::$event) {
            return self::$event->offDelay($timerId);
        }
        foreach (self::$tasks as $runTime => $taskData) {
            if (array_key_exists($timerId, $taskData)) {
                unset(self::$tasks[$runTime][$timerId]);
            }
        }
        if (array_key_exists($timerId, self::$status)) {
            unset(self::$status[$timerId]);
        }
        return true;
    }

    /**
     * Удалить все таймеры
     *
     * @return void
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
