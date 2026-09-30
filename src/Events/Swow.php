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
use Swow\Coroutine;
use Swow\Signal;
use Throwable;

use function Swow\stream_poll_one;
use function Swow\Sync\waitAll;

/**
 * Native Swow coroutine backend.
 *
 * Каждый watcher живёт в собственной Swow coroutine и ждёт готовность stream
 * через stream_poll_one(). Это позволяет Localzet сохранить общий EventInterface,
 * не подменяя Swow обычным stream_select fallback'ом.
 */
final class Swow implements EventInterface, SuspensionCapableInterface
{
    /** @var array<int,Coroutine> */
    private array $readEvents = [];

    /** @var array<int,Coroutine> */
    private array $writeEvents = [];

    /** @var array<int,Coroutine> */
    private array $signalEvents = [];

    /** @var array<int,Coroutine> */
    private array $timerEvents = [];

    /** @var null|callable(Throwable):void */
    private $errorHandler = null;

    public function __construct()
    {
        if (!extension_loaded('swow') || !class_exists(Coroutine::class)) {
            throw new RuntimeException('ext-swow is required for the Localzet swow backend.');
        }
    }

    public function run(): void
    {
        waitAll();
    }

    public function stop(): void
    {
        $this->deleteAllTimer();
        foreach ([$this->readEvents, $this->writeEvents, $this->signalEvents] as $events) {
            foreach ($events as $coroutine) {
                if ($coroutine->isAvailable()) {
                    $coroutine->kill();
                }
            }
        }
        $this->readEvents = $this->writeEvents = $this->signalEvents = [];
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        if ($delay < 0) {
            throw new \InvalidArgumentException('Timer delay must be >= 0.');
        }
        $milliseconds = max(1, (int)round($delay * 1000));
        $coroutine = Coroutine::run(function () use ($milliseconds, $callback, $args): void {
            usleep($milliseconds * 1000);
            $id = Coroutine::getCurrent()->getId();
            unset($this->timerEvents[$id]);
            $this->safeCall($callback, ...$args);
        });
        $id = $coroutine->getId();
        $this->timerEvents[$id] = $coroutine;
        return $id;
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        if ($interval < 0) {
            throw new \InvalidArgumentException('Timer interval must be >= 0.');
        }
        $microseconds = max(1, (int)round($interval * 1_000_000));
        $coroutine = Coroutine::run(function () use ($microseconds, $callback, $args): void {
            while (true) {
                usleep($microseconds);
                $this->safeCall($callback, ...$args);
            }
        });
        $id = $coroutine->getId();
        $this->timerEvents[$id] = $coroutine;
        return $id;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timerEvents[$timerId])) {
            return false;
        }
        $coroutine = $this->timerEvents[$timerId];
        unset($this->timerEvents[$timerId]);
        if ($coroutine->isAvailable()) {
            $coroutine->kill();
        }
        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function deleteAllTimer(): void
    {
        foreach (array_keys($this->timerEvents) as $timerId) {
            $this->offDelay($timerId);
        }
    }

    public function getTimerCount(): int
    {
        return count($this->timerEvents);
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offReadable($stream);
        $this->readEvents[$id] = Coroutine::run(function () use ($stream, $callback, $id): void {
            try {
                while (isset($this->readEvents[$id]) && is_resource($stream)) {
                    $events = stream_poll_one($stream, STREAM_POLLIN | STREAM_POLLHUP, 1000);
                    if (($events & STREAM_POLLIN) !== 0) {
                        $this->safeCall($callback, $stream);
                    }
                    if (($events & STREAM_POLLHUP) !== 0) {
                        break;
                    }
                }
            } finally {
                unset($this->readEvents[$id]);
            }
        });
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->readEvents[$id])) {
            return false;
        }
        $coroutine = $this->readEvents[$id];
        unset($this->readEvents[$id]);
        if ($coroutine !== Coroutine::getCurrent() && $coroutine->isAvailable()) {
            $coroutine->kill();
        }
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offWritable($stream);
        $this->writeEvents[$id] = Coroutine::run(function () use ($stream, $callback, $id): void {
            try {
                while (isset($this->writeEvents[$id]) && is_resource($stream)) {
                    $events = stream_poll_one($stream, STREAM_POLLOUT | STREAM_POLLHUP, 1000);
                    if (($events & STREAM_POLLOUT) !== 0) {
                        $this->safeCall($callback, $stream);
                    }
                    if (($events & STREAM_POLLHUP) !== 0) {
                        break;
                    }
                }
            } finally {
                unset($this->writeEvents[$id]);
            }
        });
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->writeEvents[$id])) {
            return false;
        }
        $coroutine = $this->writeEvents[$id];
        unset($this->writeEvents[$id]);
        if ($coroutine !== Coroutine::getCurrent() && $coroutine->isAvailable()) {
            $coroutine->kill();
        }
        return true;
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->offSignal($signal);
        $this->signalEvents[$signal] = Coroutine::run(function () use ($signal, $callback): void {
            while (isset($this->signalEvents[$signal])) {
                Signal::wait($signal);
                if (isset($this->signalEvents[$signal])) {
                    $this->safeCall($callback, $signal);
                }
            }
        });
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalEvents[$signal])) {
            return false;
        }
        $coroutine = $this->signalEvents[$signal];
        unset($this->signalEvents[$signal]);
        if ($coroutine !== Coroutine::getCurrent() && $coroutine->isAvailable()) {
            $coroutine->kill();
        }
        return true;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function sleep(float $delay): void
    {
        if ($delay > 0) {
            // ext-swow hooks usleep() and yields only the current coroutine.
            usleep((int)round($delay * 1_000_000));
        }
    }

    private function safeCall(callable $callback, mixed ...$args): void
    {
        Coroutine::run(function () use ($callback, $args): void {
            try {
                $callback(...$args);
            } catch (Throwable $e) {
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($e);
                    return;
                }
                throw $e;
            }
        });
    }
}
