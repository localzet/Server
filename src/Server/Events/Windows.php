<?php declare(strict_types=1);

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

namespace localzet\Server\Events;

use SplPriorityQueue;
use Throwable;
use function count;
use function max;
use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use const DIRECTORY_SEPARATOR;

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
     * @var array
     */
    protected array $readEvents = [];

    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * Массив всех обработчиков событий исключений.
     *
     * @var array
     */
    protected array $exceptEvents = [];

    /**
     * Массив всех обработчиков сигналов.
     *
     * @var array
     */
    protected array $signalEvents = [];

    /**
     * Массив файловых дескрипторов, ожидающих события чтения.
     *
     * @var array
     */
    protected array $readFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих события записи.
     *
     * @var array
     */
    protected array $writeFds = [];

    /**
     * Массив файловых дескрипторов, ожидающих исключительные события.
     *
     * @var array
     */
    protected array $exceptFds = [];

    /**
     * Планировщик таймеров.
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
    protected $errorHandler;

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
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $count = count($this->readFds);
        if ($count >= 1024) {
            echo "Предупреждение: выбор системного вызова превысил максимальное количество подключений 1024, установите расширение event/libevent для большего количества подключений..\n";
        } else if (DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Предупреждение: выбор системного вызова превысил максимальное количество подключений 256.\n";
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
            echo "Предупреждение: выбор системного вызова превысил максимальное количество подключений 1024, установите расширение event/libevent для большего количества подключений..\n";
        } else if (DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Предупреждение: выбор системного вызова превысил максимальное количество подключений 256.\n";
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
     * @param $func
     */
    public function onExcept($stream, $func): void
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
        pcntl_signal($signal, $this->signalHandler(...));
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
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler(int $signal): void
    {
        $this->signalEvents[$signal]($signal);
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
                try {
                    $taskData[0](...$taskData[1]);
                } catch (Throwable $e) {
                    $this->error($e);
                    continue;
                }
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
    public function deleteAllTimer(): void
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
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
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
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
                    $this->readEvents[$fdKey]($fd);
                }
            }

            foreach ($write as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->writeEvents[$fdKey])) {
                    $this->writeEvents[$fdKey]($fd);
                }
            }

            foreach ($except as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->exceptEvents[$fdKey])) {
                    $this->exceptEvents[$fdKey]($fd);
                }
            }

            if (!empty($this->signalEvents)) {
                pcntl_signal_dispatch();
            }
        }
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
        $this->readFds = $this->writeFds = $this->exceptFds = $this->readEvents
            = $this->writeEvents = $this->exceptEvents = $this->signalEvents = [];
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

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->errorHandler;
    }

    /**
     * @param Throwable $e
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $e): void
    {
        if (!$this->errorHandler) {
            throw new $e;
        }
        ($this->errorHandler)($e);
    }
}
