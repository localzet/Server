<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Events;

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
     *
     * @var bool
     */
    protected bool $running = true;

    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, callable>
     */
    protected array $readEvents = [];

    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, callable>
     */
    protected array $writeEvents = [];

    /**
     * Массив всех обработчиков событий исключений.
     *
     * @var array<int, callable>
     */
    protected array $exceptEvents = [];

    /**
     * Массив всех обработчиков сигналов.
     *
     * @var array<int, callable>
     */
    protected array $signalEvents = [];

    /**
     * Массив файловых дескрипторов, ожидающих события чтения.
     *
     * @var array<int, resource>
     */
    protected array $readFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих события записи.
     *
     * @var array<int, resource>
     */
    protected array $writeFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих исключительные события.
     *
     *
     * @var array<int, resource>
     */
    protected array $exceptFds = [];

    /**
     * Планировщик таймеров.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var SplPriorityQueue
     */
    protected SplPriorityQueue $scheduler;

    /**
     * Массив всех таймеров.
     *
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * Идентификатор таймера.
     *
     * @var int
     */
    protected int $timerId = 1;

    /**
     * Таймаут события select.
     *
     * @var int
     */
    protected int $selectTimeout = 100000000;

    /**
     * Обработчик ошибок.
     *
     * @var ?callable
     */
    protected $errorHandler = null;

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
        $selectTimeout = ($runTime - microtime(true)) * 1000000;
        $selectTimeout = $selectTimeout <= 0 ? 1 : (int)$selectTimeout;
        if ($this->selectTimeout > $selectTimeout) {
            $this->selectTimeout = $selectTimeout;
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
        $selectTimeout = ($runTime - microtime(true)) * 1000000;
        $selectTimeout = $selectTimeout <= 0 ? 1 : (int)$selectTimeout;
        if ($this->selectTimeout > $selectTimeout) {
            $this->selectTimeout = $selectTimeout;
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
            Server::safeEcho("Предупреждение: выбор системного вызова превысил максимальное количество подключений 1024, установите расширение event/libevent для большего количества подключений.\n");
        } else if (!is_unix() && $count >= 256) {
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
        } else if (!is_unix() && $count >= 256) {
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
     * @param callable $func
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
     * @return bool
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

    /**
     * @param callable $func
     * @param array $args
     * @return void
     */
    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $e) {
            if ($this->errorHandler === null) {
                echo $e;
            } else {
                ($this->errorHandler)($e);
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
            if (!empty($read) || !empty($write) || !empty($except)) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
                    // do nothing
                }
            } else {
                $this->selectTimeout >= 1 && usleep($this->selectTimeout);
            }

            if (!$this->scheduler->isEmpty()) {
                $this->tick();
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

            if (!empty($this->signalEvents)) {
                // Calls signal handlers for pending signals
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Tick for timer.
     *
     * @return void
     * @throws Throwable
     */
    protected function tick(): void
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
        foreach ($tasksToInsert as $item) {
            $this->scheduler->insert($item[0], $item[1]);
        }
        if (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = microtime(true);
            $this->selectTimeout = max((int)(($nextRunTime - $timeNow) * 1000000), 0);
            return;
        }
        $this->selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
        $this->deleteAllTimer();
        foreach ($this->signalEvents as $signal => $item) {
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
