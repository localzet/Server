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

namespace localzet\Server\Events;

use Swoole\Coroutine;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Throwable;

use const SWOOLE_EVENT_READ;
use const SWOOLE_EVENT_WRITE;
use const SWOOLE_HOOK_ALL;

/**
 * Класс Windows реализует интерфейс EventInterface и представляет select event loop.
 */
final class Swoole implements EventInterface
{
    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, array>
     */
    private array $readEvents = [];

    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, array>
     */
    private array $writeEvents = [];

    /**
     * Массив всех таймеров.
     *
     * @var array<int, int>
     */
    private array $eventTimer = [];

    /**
     * Обработчик ошибок.
     *
     * @var ?callable
     */
    private $errorHandler = null;

    private bool $stopping = false;

    /**
     * Constructor.
     */
    public function __construct()
    {
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $t = (int)($delay * 1000);
        $t = max($t, 1);

        $timerId = Timer::after($t, function () use ($func, $args, &$timerId): void {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, $args);
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    private function safeCall(callable $func, array $args = []): void
    {
        Coroutine::create(function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (Throwable $e) {
                if ($this->errorHandler === null) {
                    echo $e;
                } else {
                    ($this->errorHandler)($e);
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $t = (int)($interval * 1000);
        $t = max($t, 1);

        $timerId = Timer::tick($t, function () use ($func, $args): void {
            $this->safeCall($func, $args);
        });
        $this->eventTimer[$timerId] = $timerId;
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
            Timer::clear($timerId);
            unset($this->eventTimer[$timerId]);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;

        // Отменим все сопрограммы перед Event::exit
        foreach (Coroutine::listCoroutines() as $coroutine) {
            Coroutine::cancel($coroutine);
        }

        // Дождемся завершения работы сопрограмм.
        usleep(200000);
        Event::exit();
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, fn () => $this->callRead($fd), null, SWOOLE_EVENT_READ);
        } elseif (isset($this->writeEvents[$fd])) {
            Event::set($stream, fn () => $this->callRead($fd), null, SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE);
        } else {
            Event::set($stream, fn () => $this->callRead($fd), null, SWOOLE_EVENT_READ);
        }

        $this->readEvents[$fd] = [$func, [$stream]];
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd])) {
            return false;
        }

        unset($this->readEvents[$fd]);
        if (!isset($this->writeEvents[$fd])) {
            Event::del($stream);
            return true;
        }

        Event::set($stream, null, null, SWOOLE_EVENT_WRITE);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, null, fn () => $this->callWrite($fd), SWOOLE_EVENT_WRITE);
        } elseif (isset($this->readEvents[$fd])) {
            Event::set($stream, null, fn () => $this->callWrite($fd), SWOOLE_EVENT_WRITE | SWOOLE_EVENT_READ);
        } else {
            Event::set($stream, null, fn () => $this->callWrite($fd), SWOOLE_EVENT_WRITE);
        }

        $this->writeEvents[$fd] = [$func, [$stream]];
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->writeEvents[$fd])) {
            return false;
        }

        unset($this->writeEvents[$fd]);
        if (!isset($this->readEvents[$fd])) {
            Event::del($stream);
            return true;
        }

        Event::set($stream, null, null, SWOOLE_EVENT_READ);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        Process::signal($signal, fn () => $this->safeCall($func, [$signal]));
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        // Avoid process exit due to no listening
        Timer::tick(100000000, static fn () => null);
        Event::wait();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $timerId) {
            Timer::clear($timerId);
        }
    }

    /**
     * @see https://wiki.swoole.com/#/process/process?id=signal
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        return Process::signal($signal, null);
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
     * @param $fd
     */
    private function callRead(int $fd): void
    {
        if (isset($this->readEvents[$fd])) {
            $this->safeCall($this->readEvents[$fd][0], $this->readEvents[$fd][1]);
        }
    }

    /**
     * @param $fd
     */
    private function callWrite(int $fd): void
    {
        if (isset($this->writeEvents[$fd])) {
            $this->safeCall($this->writeEvents[$fd][0], $this->writeEvents[$fd][1]);
        }
    }
}
