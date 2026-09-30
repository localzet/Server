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
 * Native event loop на PECL ext-ev/libev.
 */
final class Ev implements EventInterface
{
    /** @var array<int,\EvIo> */
    private array $readEvents = [];

    /** @var array<int,\EvIo> */
    private array $writeEvents = [];

    /** @var array<int,\EvSignal> */
    private array $signalEvents = [];

    /** @var array<int,\EvTimer> */
    private array $timerEvents = [];

    private int $nextTimerId = 1;

    /** @var null|callable(Throwable):void */
    private $errorHandler = null;

    public function __construct()
    {
        if (!class_exists(\Ev::class)) {
            throw new RuntimeException('ext-ev is required for the Localzet ev backend.');
        }
    }

    public function run(): void
    {
        \Ev::run();
    }

    public function stop(): void
    {
        \Ev::stop();
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        if ($delay < 0) {
            throw new \InvalidArgumentException('Timer delay must be >= 0.');
        }

        $timerId = $this->nextTimerId++;
        $this->timerEvents[$timerId] = new \EvTimer(
            max($delay, 0.000001),
            0.0,
            function () use ($timerId, $callback, $args): void {
                unset($this->timerEvents[$timerId]);
                $this->safeCall($callback, ...$args);
            }
        );
        return $timerId;
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        if ($interval < 0) {
            throw new \InvalidArgumentException('Timer interval must be >= 0.');
        }

        $interval = max($interval, 0.000001);
        $timerId = $this->nextTimerId++;
        $this->timerEvents[$timerId] = new \EvTimer(
            $interval,
            $interval,
            fn() => $this->safeCall($callback, ...$args)
        );
        return $timerId;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timerEvents[$timerId])) {
            return false;
        }
        $this->timerEvents[$timerId]->stop();
        unset($this->timerEvents[$timerId]);
        return true;
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function deleteAllTimer(): void
    {
        foreach ($this->timerEvents as $event) {
            $event->stop();
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
        $this->offReadable($stream);
        $this->readEvents[$id] = new \EvIo(
            $stream,
            \Ev::READ,
            fn() => $this->safeCall($callback, $stream)
        );
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->readEvents[$id])) {
            return false;
        }
        $this->readEvents[$id]->stop();
        unset($this->readEvents[$id]);
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offWritable($stream);
        $this->writeEvents[$id] = new \EvIo(
            $stream,
            \Ev::WRITE,
            fn() => $this->safeCall($callback, $stream)
        );
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->writeEvents[$id])) {
            return false;
        }
        $this->writeEvents[$id]->stop();
        unset($this->writeEvents[$id]);
        return true;
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->offSignal($signal);
        $this->signalEvents[$signal] = new \EvSignal(
            $signal,
            fn() => $this->safeCall($callback, $signal)
        );
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalEvents[$signal])) {
            return false;
        }
        $this->signalEvents[$signal]->stop();
        unset($this->signalEvents[$signal]);
        return true;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    private function safeCall(callable $callback, mixed ...$args): void
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
