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
 * Native event loop на PECL ext-event/libevent.
 *
 * В отличие от старой modernized-заглушки этот класс действительно работает
 * через EventBase/Event. Явный выбор `event` поэтому больше не маскируется под
 * Revolt: отсутствие extension приводит к fail-fast ещё в EventLoopFactory.
 */
final class Event implements EventInterface
{
    private \EventBase $eventBase;

    /** @var array<int,\Event> */
    private array $readEvents = [];

    /** @var array<int,\Event> */
    private array $writeEvents = [];

    /** @var array<int,\Event> */
    private array $signalEvents = [];

    /** @var array<int,\Event> */
    private array $timerEvents = [];

    private int $nextTimerId = 1;

    /** @var null|callable(Throwable):void */
    private $errorHandler = null;

    public function __construct()
    {
        if (!class_exists(\EventBase::class) || !class_exists(\Event::class)) {
            throw new RuntimeException('ext-event is required for the Localzet event backend.');
        }
        $this->eventBase = new \EventBase();
    }

    public function run(): void
    {
        $this->eventBase->loop();
    }

    public function stop(): void
    {
        $this->eventBase->exit();
    }

    public function delay(float $delay, callable $callback, array $args = []): int
    {
        return $this->addTimer($delay, false, $callback, $args);
    }

    public function repeat(float $interval, callable $callback, array $args = []): int
    {
        return $this->addTimer($interval, true, $callback, $args);
    }

    private function addTimer(float $interval, bool $repeat, callable $callback, array $args): int
    {
        if ($interval < 0) {
            throw new \InvalidArgumentException('Timer interval must be >= 0.');
        }

        $timerId = $this->nextTimerId++;
        $flags = \Event::TIMEOUT | ($repeat ? \Event::PERSIST : 0);
        $event = new \Event(
            $this->eventBase,
            -1,
            $flags,
            function () use ($timerId, $repeat, $callback, $args): void {
                if (!$repeat) {
                    unset($this->timerEvents[$timerId]);
                }
                $this->safeCall($callback, ...$args);
            }
        );

        if (!$event->addTimer(max($interval, 0.000001))) {
            throw new RuntimeException('Unable to register ext-event timer.');
        }

        $this->timerEvents[$timerId] = $event;
        return $timerId;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->timerEvents[$timerId])) {
            return false;
        }
        $this->timerEvents[$timerId]->del();
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
            $event->del();
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

        $event = new \Event(
            $this->eventBase,
            $stream,
            \Event::READ | \Event::PERSIST,
            fn() => $this->safeCall($callback, $stream)
        );
        if (!$event->add()) {
            throw new RuntimeException('Unable to register ext-event readable watcher.');
        }
        $this->readEvents[$id] = $event;
    }

    public function offReadable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->readEvents[$id])) {
            return false;
        }
        $this->readEvents[$id]->del();
        unset($this->readEvents[$id]);
        return true;
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int)$stream;
        $this->offWritable($stream);

        $event = new \Event(
            $this->eventBase,
            $stream,
            \Event::WRITE | \Event::PERSIST,
            fn() => $this->safeCall($callback, $stream)
        );
        if (!$event->add()) {
            throw new RuntimeException('Unable to register ext-event writable watcher.');
        }
        $this->writeEvents[$id] = $event;
    }

    public function offWritable($stream): bool
    {
        $id = (int)$stream;
        if (!isset($this->writeEvents[$id])) {
            return false;
        }
        $this->writeEvents[$id]->del();
        unset($this->writeEvents[$id]);
        return true;
    }

    public function onSignal(int $signal, callable $callback): void
    {
        $this->offSignal($signal);
        $event = \Event::signal(
            $this->eventBase,
            $signal,
            fn() => $this->safeCall($callback, $signal)
        );
        if (!$event->add()) {
            throw new RuntimeException("Unable to register ext-event signal {$signal}.");
        }
        $this->signalEvents[$signal] = $event;
    }

    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalEvents[$signal])) {
            return false;
        }
        $this->signalEvents[$signal]->del();
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
