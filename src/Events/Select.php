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

use Throwable;

/**
 * Portable event loop на stream_select().
 *
 * Это fallback без внешних расширений. На Linux/macOS при установленном Revolt
 * Server по умолчанию предпочитает Fiber/Revolt backend.
 */
class Select implements EventInterface
{
    /** @var array<int, array{0: resource, 1: callable}> */
    protected array $read = [];

    /** @var array<int, array{0: resource, 1: callable}> */
    protected array $write = [];

    /** @var array<int, callable> */
    protected array $signals = [];

    /** @var array<int, array{at: float, interval: float, repeat: bool, callback: callable, args: array}> */
    protected array $timers = [];

    protected int $nextTimerId = 1;
    protected bool $running = false;
    protected $errorHandler = null;

    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $this->dispatchSignals();
            $timeout = $this->dispatchTimers();

            $readStreams = array_column($this->read, 0);
            $writeStreams = array_column($this->write, 0);

            if (!$readStreams && !$writeStreams) {
                if (!$this->timers) {
                    break;
                }
                usleep((int)max(1_000, min(1_000_000, $timeout * 1_000_000)));
                continue;
            }

            $except = null;
            $seconds = (int)floor($timeout);
            $microseconds = (int)(($timeout - $seconds) * 1_000_000);

            set_error_handler(static fn() => true);
            $ready = stream_select($readStreams, $writeStreams, $except, $seconds, $microseconds);
            restore_error_handler();

            if ($ready === false) {
                continue;
            }

            foreach ($readStreams as $stream) {
                $id = (int)$stream;
                if (isset($this->read[$id])) {
                    $this->safeCall($this->read[$id][1], $stream);
                }
            }

            foreach ($writeStreams as $stream) {
                $id = (int)$stream;
                if (isset($this->write[$id])) {
                    $this->safeCall($this->write[$id][1], $stream);
                }
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        return $this->addTimer($delay, false, $callback, $args);
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        return $this->addTimer($interval, true, $callback, $args);
    }

    protected function addTimer(float $interval, bool $repeat, callable $callback, array $args): int
    {
        if ($interval < 0) {
            throw new \InvalidArgumentException('Timer interval must be >= 0.');
        }

        $id = $this->nextTimerId++;
        $this->timers[$id] = [
            'at' => microtime(true) + $interval,
            'interval' => $interval,
            'repeat' => $repeat,
            'callback' => $callback,
            'args' => $args,
        ];
        return $id;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timers[$timerId])) {
            return false;
        }
        unset($this->timers[$timerId]);
        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function deleteAllTimer(): void
    {
        $this->timers = [];
    }

    public function getTimerCount(): int
    {
        return count($this->timers);
    }

    public function onReadable($stream, callable $callback): void
    {
        $this->read[(int)$stream] = [$stream, $callback];
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->read[$id])) {
            return false;
        }
        unset($this->read[$id]);
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $this->write[(int)$stream] = [$stream, $callback];
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->write[$id])) {
            return false;
        }
        unset($this->write[$id]);
        return true;
    }

    public function onSignal(int $signal, callable $callback): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        $this->signals[$signal] = $callback;
        pcntl_signal($signal, function (int $received): void {
            if (isset($this->signals[$received])) {
                $this->safeCall($this->signals[$received], $received);
            }
        });
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signals[$signal])) {
            return false;
        }
        unset($this->signals[$signal]);
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, SIG_DFL);
        }
        return true;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    protected function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /** Возвращает timeout до ближайшего таймера в секундах. */
    protected function dispatchTimers(): float
    {
        if (!$this->timers) {
            return 1.0;
        }

        $now = microtime(true);
        $nextAt = null;

        foreach (array_keys($this->timers) as $id) {
            if (!isset($this->timers[$id])) {
                continue;
            }
            $timer = $this->timers[$id];
            if ($timer['at'] <= $now) {
                if ($timer['repeat']) {
                    // Не пытаемся "догнать" пропущенные тики: следующий отсчёт идёт от now.
                    $this->timers[$id]['at'] = $now + max($timer['interval'], 0.000001);
                } else {
                    unset($this->timers[$id]);
                }
                $this->safeCall($timer['callback'], ...$timer['args']);
                if (!isset($this->timers[$id])) {
                    continue;
                }
                $timer = $this->timers[$id];
            }
            $nextAt = $nextAt === null ? $timer['at'] : min($nextAt, $timer['at']);
        }

        return max(0.0, min(1.0, ($nextAt ?? ($now + 1.0)) - microtime(true)));
    }

    protected function safeCall(callable $callback, mixed ...$args): void
    {
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
