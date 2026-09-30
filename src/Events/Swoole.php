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

use RuntimeException;
use Throwable;

/**
 * Native Swoole event-loop backend.
 *
 * Реализация использует Swoole Event/Timer/Process напрямую и запускает
 * пользовательские callbacks в coroutine, чтобы один медленный callback не
 * блокировал всю петлю событий. Backend включается только при явном выборе:
 * автоматический runtime не должен неожиданно включать глобальные Swoole hooks.
 */
final class Swoole implements EventInterface, SuspensionCapableInterface
{
    /** @var array<int,int> */
    private array $timerEvents = [];

    /** @var array<int,array{0:resource,1:callable}> */
    private array $readEvents = [];

    /** @var array<int,array{0:resource,1:callable}> */
    private array $writeEvents = [];

    /** @var array<int,callable> */
    private array $signalEvents = [];

    /** @var null|callable(Throwable):void */
    private $errorHandler = null;

    private bool $running = false;

    public function __construct()
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Event::class)) {
            throw new RuntimeException('ext-swoole is required for the Localzet swoole backend.');
        }

        // Hooking happens only for explicit Swoole selection. This is why the
        // factory intentionally excludes Swoole from automatic Unix selection.
        if (class_exists(\Swoole\Coroutine::class) && defined('SWOOLE_HOOK_ALL')) {
            \Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        }
    }

    public function run(): void
    {
        $this->running = true;
        // Swoole may otherwise leave Event::wait() immediately when no watcher
        // exists at the exact instant run() is entered. Keep one long-lived timer.
        $guard = \Swoole\Timer::tick(86_400_000, static fn() => null);
        try {
            \Swoole\Event::wait();
        } finally {
            $this->running = false;
            if (is_int($guard) && $guard > 0) {
                \Swoole\Timer::clear($guard);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
        $this->deleteAllTimer();
        \Swoole\Event::exit();
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        if ($delay < 0) {
            throw new \InvalidArgumentException('Timer delay must be >= 0.');
        }
        $milliseconds = max(1, (int)round($delay * 1000));
        $timerId = \Swoole\Timer::after($milliseconds, function () use (&$timerId, $callback, $args): void {
            unset($this->timerEvents[$timerId]);
            $this->safeCall($callback, ...$args);
        });
        if (!is_int($timerId) || $timerId <= 0) {
            throw new RuntimeException('Unable to register Swoole timer.');
        }
        $this->timerEvents[$timerId] = $timerId;
        return $timerId;
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        if ($interval < 0) {
            throw new \InvalidArgumentException('Timer interval must be >= 0.');
        }
        $milliseconds = max(1, (int)round($interval * 1000));
        $timerId = \Swoole\Timer::tick($milliseconds, fn() => $this->safeCall($callback, ...$args));
        if (!is_int($timerId) || $timerId <= 0) {
            throw new RuntimeException('Unable to register Swoole timer.');
        }
        $this->timerEvents[$timerId] = $timerId;
        return $timerId;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timerEvents[$timerId])) {
            return false;
        }
        \Swoole\Timer::clear($timerId);
        unset($this->timerEvents[$timerId]);
        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function deleteAllTimer(): void
    {
        foreach ($this->timerEvents as $timerId) {
            \Swoole\Timer::clear($timerId);
        }
        $this->timerEvents = [];
    }

    public function getTimerCount(): int
    {
        return count($this->timerEvents);
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->readEvents[$id] = [$stream, $callback];
        $this->refreshStreamWatcher($stream);
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->readEvents[$id])) {
            return false;
        }
        unset($this->readEvents[$id]);
        $this->refreshStreamWatcher($stream);
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->writeEvents[$id] = [$stream, $callback];
        $this->refreshStreamWatcher($stream);
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->writeEvents[$id])) {
            return false;
        }
        unset($this->writeEvents[$id]);
        $this->refreshStreamWatcher($stream);
        return true;
    }

    /**
     * Пересобирает Swoole watcher после изменения read/write callbacks.
     *
     * Swoole хранит read и write handlers в одной записи на fd, поэтому нельзя
     * независимо удалять только одну сторону как в Select.
     *
     * @param resource $stream
     */
    private function refreshStreamWatcher($stream): void
    {
        $id = (int)$stream;
        $read = isset($this->readEvents[$id]);
        $write = isset($this->writeEvents[$id]);

        if (!$read && !$write) {
            @\Swoole\Event::del($stream);
            return;
        }

        $readCallback = $read
            ? fn() => $this->safeCall($this->readEvents[$id][1], $stream)
            : null;
        $writeCallback = $write
            ? fn() => $this->safeCall($this->writeEvents[$id][1], $stream)
            : null;
        $flags = ($read ? SWOOLE_EVENT_READ : 0) | ($write ? SWOOLE_EVENT_WRITE : 0);

        if (!@\Swoole\Event::set($stream, $readCallback, $writeCallback, $flags)) {
            if (!@\Swoole\Event::add($stream, $readCallback, $writeCallback, $flags)) {
                throw new RuntimeException('Unable to register Swoole stream watcher.');
            }
        }
    }

    public function onSignal(int $signal, callable $callback): void
    {
        if (!class_exists(\Swoole\Process::class)) {
            throw new RuntimeException('Swoole Process API is required for signal watchers.');
        }
        $this->signalEvents[$signal] = $callback;
        \Swoole\Process::signal($signal, fn() => $this->safeCall($callback, $signal));
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalEvents[$signal])) {
            return false;
        }
        unset($this->signalEvents[$signal]);
        \Swoole\Process::signal($signal, null);
        return true;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function sleep(float $delay): void
    {
        if ($delay <= 0) {
            return;
        }
        if (class_exists(\Swoole\Coroutine::class)
            && class_exists(\Swoole\Coroutine\System::class)
            && \Swoole\Coroutine::getCid() >= 0) {
            \Swoole\Coroutine\System::sleep($delay);
            return;
        }
        usleep((int)round($delay * 1_000_000));
    }

    private function safeCall(callable $callback, mixed ...$args): void
    {
        $runner = function () use ($callback, $args): void {
            try {
                $callback(...$args);
            } catch (Throwable $e) {
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($e);
                    return;
                }
                throw $e;
            }
        };

        if (class_exists(\Swoole\Coroutine::class)) {
            $cid = \Swoole\Coroutine::create($runner);
            if ($cid !== false) {
                return;
            }
        }
        $runner();
    }
}
