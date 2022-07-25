<?php

/**
 * @version     1.0.0-dev
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core;

use localzet\Core\Events\EventInterface;
use localzet\Core\Server;
use \Exception;

/**
 * Таймер.
 *
 * Пример:
 * localzet\Core\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * Задачи, основанные на сигнале
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   ..
     * ]
     *
     * @var array
     */
    protected static $_tasks = array();

    /**
     * Событие
     *
     * @var EventInterface
     */
    protected static $_event = null;

    /**
     * ID Таймера
     *
     * @var int
     */
    protected static $_timerId = 0;

    /**
     * Статус таймера
     * [
     *   timer_id1 => bool,
     *   timer_id2 => bool,
     *   ....................,
     * ]
     *
     * @var array
     */
    protected static $_status = array();

    /**
     * Инициализация
     *
     * @param EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        if ($event) {
            self::$_event = $event;
            return;
        }
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGALRM, array('\localzet\Core\Lib\Timer', 'signalHandle'), false);
        }
    }

    /**
     * Обработчик сигнала
     *
     * @return void
     */
    public static function signalHandle()
    {
        if (!self::$_event) {
            \pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Добавить таймер.
     *
     * @param float    $time_interval
     * @param callable $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int|bool
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if ($time_interval <= 0) {
            Server::safeEcho(new Exception("Отрицательный интервал для таймера"));
            return false;
        }

        if ($args === null) {
            $args = array();
        }

        if (self::$_event) {
            return self::$_event->add(
                $time_interval,
                $persistent ? EventInterface::EV_TIMER : EventInterface::EV_TIMER_ONCE,
                $func,
                $args
            );
        }

        if (!\is_callable($func)) {
            Server::safeEcho(new Exception("Некорректный callback"));
            return false;
        }

        if (empty(self::$_tasks)) {
            \pcntl_alarm(1);
        }

        $run_time = \time() + $time_interval;
        if (!isset(self::$_tasks[$run_time])) {
            self::$_tasks[$run_time] = array();
        }

        self::$_timerId = self::$_timerId == \PHP_INT_MAX ? 1 : ++self::$_timerId;
        self::$_status[self::$_timerId] = true;
        self::$_tasks[$run_time][self::$_timerId] = array($func, (array)$args, $persistent, $time_interval);

        return self::$_timerId;
    }


    /**
     * ТИК (или ТАК, кому как нравится)
     *
     * @return void
     */
    public static function tick()
    {
        if (empty(self::$_tasks)) {
            \pcntl_alarm(0);
            return;
        }
        $time_now = \time();
        foreach (self::$_tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func     = $one_task[0];
                    $task_args     = $one_task[1];
                    $persistent    = $one_task[2];
                    $time_interval = $one_task[3];
                    try {
                        \call_user_func_array($task_func, $task_args);
                    } catch (\Exception $e) {
                        Server::safeEcho($e);
                    }
                    if ($persistent && !empty(self::$_status[$index])) {
                        $new_run_time = \time() + $time_interval;
                        if (!isset(self::$_tasks[$new_run_time])) self::$_tasks[$new_run_time] = array();
                        self::$_tasks[$new_run_time][$index] = array($task_func, (array)$task_args, $persistent, $time_interval);
                    }
                }
                unset(self::$_tasks[$run_time]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_event) {
            return self::$_event->del($timer_id, EventInterface::EV_TIMER);
        }

        foreach (self::$_tasks as $run_time => $task_data) {
            if (array_key_exists($timer_id, $task_data)) unset(self::$_tasks[$run_time][$timer_id]);
        }

        if (array_key_exists($timer_id, self::$_status)) unset(self::$_status[$timer_id]);

        return true;
    }

    /**
     * Удалить все таймеры.
     *
     * @return void
     */
    public static function delAll()
    {
        self::$_tasks = self::$_status = array();
        \pcntl_alarm(0);
        if (self::$_event) {
            self::$_event->clearAllTimer();
        }
    }
}
