<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
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

namespace localzet\Events;

use localzet\Server;
use SplPriorityQueue;
use Throwable;
use function count;
use function max;
use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;

/**
 * Класс Windows реализует интерфейс EventInterface и представляет select event loop.
 */
final class Windows implements EventInterface
{
    /**
     * Флаг, указывающий, работает ли событийный цикл.
     */
    private bool $running = true;

    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, callable>
     */
    private array $readEvents = [];

    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, callable>
     */
    private array $writeEvents = [];

    /**
     * Массив всех обработчиков событий исключений.
     *
     * @var array<int, callable>
     */
    private array $exceptEvents = [];

    /**
     * Массив всех обработчиков сигналов.
     *
     * @var array<int, callable>
     */
    private array $signalEvents = [];

    /**
     * Массив файловых дескрипторов, ожидающих события чтения.
     *
     * @var array<int, resource>
     */
    private array $readFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих события записи.
     *
     * @var array<int, resource>
     */
    private array $writeFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих исключительные события.
     *
     *
     * @var array<int, resource>
     */
    private array $exceptFds = [];

    /**
     * Планировщик таймеров.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     */
    private SplPriorityQueue $scheduler;

    /**
     * Массив всех таймеров.
     */
    private array $eventTimer = [];

    /**
     * Идентификатор таймера.
     */
    private int $timerId = 1;

    /**
     * Таймаут события select.
     */
    private int $selectTimeout = 100000000;

    /**
     * Следующее время срабатывания таймера.
     */
    private float $nextTickTime = 0;

    /**
     * Обработчик ошибок.
     *
     * @var ?callable
     */
    private $errorHandler = null;

    /**
     * Конструктор.
     */
    public function __construct()
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $delay;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args];
        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
        }

        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $interval;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, $interval];
        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
        }

        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            unset($this->eventTimer[$timerId]);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $count = count($this->readFds);
        if ($count >= 1024) {
            Server::safeEcho("Предупреждение: выбор системного вызова превысил максимальное количество подключений 1024, установите расширение event для большего количества подключений.\n");
        } elseif (!is_unix() && $count >= 256) {
            Server::safeEcho("Предупреждение: выбор системного вызова превысил максимальное количество подключений 256.\n");
        }

        $fdKey = (int)$stream;
        $this->readEvents[$fdKey] = $func;
        $this->readFds[$fdKey] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            unset($this->readEvents[$fdKey], $this->readFds[$fdKey]);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $count = count($this->writeFds);
        if ($count >= 1024) {
            Server::safeEcho("Предупреждение: выбор системного вызова превысил максимальное количество подключений 1024, установите расширение event/libevent для большего количества подключений.\n");
        } elseif (!is_unix() && $count >= 256) {
            Server::safeEcho("Предупреждение: выбор системного вызова превысил максимальное количество подключений 256.\n");
        }

        $fdKey = (int)$stream;
        $this->writeEvents[$fdKey] = $func;
        $this->writeFds[$fdKey] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            unset($this->writeEvents[$fdKey], $this->writeFds[$fdKey]);
            return true;
        }

        return false;
    }

    /**
     * On except.
     * @param resource $stream
     */
    public function onExcept($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $this->exceptEvents[$fdKey] = $func;
        $this->exceptFds[$fdKey] = $stream;
    }

    /**
     * Off except.
     * @param resource $stream
     */
    public function offExcept($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->exceptEvents[$fdKey])) {
            unset($this->exceptEvents[$fdKey], $this->exceptFds[$fdKey]);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $this->signalEvents[$signal] = $func;
        pcntl_signal($signal, fn() => $this->safeCall($this->signalEvents[$signal], [$signal]));
    }

    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $throwable) {
            if ($this->errorHandler === null) {
                echo $throwable;
            } else {
                ($this->errorHandler)($throwable);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        while ($this->running) {
            $read = $this->readFds;
            $write = $this->writeFds;
            $except = $this->exceptFds;
            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
                    // do nothing
                }
            } elseif ($this->selectTimeout >= 1) {
                usleep($this->selectTimeout);
            }

            foreach ($read as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->readEvents[$fdKey])) {
                    $this->safeCall($this->readEvents[$fdKey], [$fd]);
                }
            }

            foreach ($write as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->writeEvents[$fdKey])) {
                    $this->safeCall($this->writeEvents[$fdKey], [$fd]);
                }
            }

            foreach ($except as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->exceptEvents[$fdKey])) {
                    $this->safeCall($this->exceptEvents[$fdKey], [$fd]);
                }
            }

            if ($this->nextTickTime > 0 && microtime(true) >= $this->nextTickTime) {
                $this->tick();
            }

            if ($this->signalEvents) {
                // Calls signal handlers for pending signals
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Tick for timer.
     *
     * @throws Throwable
     */
    private function tick(): void
    {
        $tasksToInsert = [];
        while (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $timerId = $schedulerData['data'];
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = microtime(true);
            $this->selectTimeout = (int)(($nextRunTime - $timeNow) * 1000000);

            if ($this->selectTimeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->eventTimer[$timerId])) {
                    continue;
                }

                // [func, args, timer_interval]
                $taskData = $this->eventTimer[$timerId];
                if (isset($taskData[2])) {
                    $nextRunTime = $timeNow + $taskData[2];
                    $tasksToInsert[] = [$timerId, -$nextRunTime];
                } else {
                    unset($this->eventTimer[$timerId]);
                }

                $this->safeCall($taskData[0], $taskData[1]);
            } else {
                break;
            }
        }

        foreach ($tasksToInsert as $taskToInsert) {
            $this->scheduler->insert($taskToInsert[0], $taskToInsert[1]);
        }

        if (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $nextRunTime = -$schedulerData['priority'];
            $this->setNextTickTime($nextRunTime);
            return;
        }

        $this->setNextTickTime(0);
    }

    /**
     * Установить время следующего тика.
     */
    private function setNextTickTime(float $nextTickTime): void
    {
        $this->nextTickTime = $nextTickTime;
        if ($nextTickTime == 0) {
            $this->selectTimeout = 10000000;
            return;
        }
        
        $timeNow = microtime(true);
        $this->selectTimeout = max((int)(($nextTickTime - $timeNow) * 1000000), 0);
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
        $this->deleteAllTimer();
        foreach (array_keys($this->signalEvents) as $signal) {
            $this->offsignal($signal);
        }

        $this->readFds = [];
        $this->writeFds = [];
        $this->exceptFds = [];
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->exceptEvents = [];
        $this->signalEvents = [];
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }

        pcntl_signal($signal, SIG_IGN);
        if (isset($this->signalEvents[$signal])) {
            unset($this->signalEvents[$signal]);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }
}
