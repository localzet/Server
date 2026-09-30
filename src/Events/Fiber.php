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

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Throwable;

/**
 * Тонкий адаптер Localzet Server -> Revolt EventLoop.
 *
 * В старой реализации Localzet содержал собственную копию значительной части
 * Revolt drivers. Теперь драйверы остаются ответственностью Revolt, а Server
 * держит только совместимый адаптер. Это уменьшает maintenance и риск расхождения.
 */
class Fiber implements EventInterface, SuspensionCapableInterface
{
    protected Driver $driver;
    /** @var array<int, string> */
    protected array $read = [];
    /** @var array<int, string> */
    protected array $write = [];
    /** @var array<int, string> */
    protected array $signals = [];
    /** @var array<int, string> */
    protected array $timers = [];
    protected int $nextTimerId = 1;
    protected $errorHandler = null;

    public function __construct()
    {
        $this->driver = EventLoop::getDriver();
        $this->driver->setErrorHandler(function (Throwable $e): void {
            if ($this->errorHandler !== null) {
                ($this->errorHandler)($e);
                return;
            }
            throw $e;
        });
    }

    public function driver(): Driver
    {
        return $this->driver;
    }

    public function run(): void
    {
        $this->driver->run();
    }

    public function stop(): void
    {
        foreach ($this->signals as $watcher) {
            $this->driver->cancel($watcher);
        }
        $this->signals = [];
        $this->driver->stop();
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        $id = $this->nextTimerId++;
        $watcher = $this->driver->delay($delay, function () use ($id, $callback, $args): void {
            unset($this->timers[$id]);
            $this->safeCall($callback, ...$args);
        });
        $this->timers[$id] = $watcher;
        return $id;
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        $id = $this->nextTimerId++;
        $this->timers[$id] = $this->driver->repeat(
            $interval,
            fn() => $this->safeCall($callback, ...$args)
        );
        return $id;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        $this->driver->cancel($this->timers[$timerId]);
        unset($this->timers[$timerId]);
        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function deleteAllTimer(): void
    {
        foreach ($this->timers as $watcher) {
            $this->driver->cancel($watcher);
        }
        $this->timers = [];
    }

    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        if (isset($this->read[$id])) {
            $this->driver->cancel($this->read[$id]);
        }
        $this->read[$id] = $this->driver->onReadable(
            $stream,
            fn() => $this->safeCall($callback, $stream)
        );
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->read[$id])) {
            return false;
        }
        $this->driver->cancel($this->read[$id]);
        unset($this->read[$id]);
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        if (isset($this->write[$id])) {
            $this->driver->cancel($this->write[$id]);
        }
        $this->write[$id] = $this->driver->onWritable(
            $stream,
            fn() => $this->safeCall($callback, $stream)
        );
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->write[$id])) {
            return false;
        }
        $this->driver->cancel($this->write[$id]);
        unset($this->write[$id]);
        return true;
    }

    public function onSignal(int $signal, callable $callback): void
    {
        if (isset($this->signals[$signal])) {
            $this->driver->cancel($this->signals[$signal]);
        }
        $this->signals[$signal] = $this->driver->onSignal(
            $signal,
            fn() => $this->safeCall($callback, $signal)
        );
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signals[$signal])) {
            return false;
        }
        $this->driver->cancel($this->signals[$signal]);
        unset($this->signals[$signal]);
        return true;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * Неблокирующий sleep текущего Revolt Fiber.
     *
     * Driver сам исполняет event callbacks в Fiber, поэтому suspension относится
     * именно к текущему application callback и не останавливает event loop.
     */
    public function sleep(float $delay): void
    {
        if ($delay <= 0) {
            return;
        }

        $suspension = $this->driver->getSuspension();
        $this->delay($delay, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    protected function safeCall(callable $callback, mixed ...$args): void
    {
        // Revolt Driver contract already executes every event callback in its own
        // Fiber. Wrapping it again creates a nested Fiber and breaks the natural
        // suspension ownership used by EventLoop::getSuspension()/Timer::sleep().
        try {
            $callback(...$args);
        } catch (Throwable $e) {
            if ($this->errorHandler !== null) {
                ($this->errorHandler)($e);
                return;
            }
            throw $e;
        }
    }
}
