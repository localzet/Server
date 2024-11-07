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

namespace localzet\Events\Linux\Driver;

use Closure;
use Error;
use Event;
use EventBase;
use localzet\Events\Linux\Internal\{StreamReadableCallback};
use localzet\Events\Linux\Internal\AbstractDriver;
use localzet\Events\Linux\Internal\DriverCallback;
use localzet\Events\Linux\Internal\SignalCallback;
use localzet\Events\Linux\Internal\StreamCallback;
use localzet\Events\Linux\Internal\StreamWritableCallback;
use localzet\Events\Linux\Internal\TimerCallback;
use function assert;
use function extension_loaded;
use function hrtime;
use function is_resource;
use function max;
use function min;
use const PHP_INT_MAX;

/**
 *
 */
final class EventDriver extends AbstractDriver
{
    /** @var array<string, Event>|null */
    private static ?array $activeSignals = null;

    private readonly EventBase $eventBase;

    /** @var array<string, Event> */
    private array $events = [];

    private readonly Closure $ioCallback;

    private readonly Closure $timerCallback;

    private readonly Closure $signalCallback;

    /** @var array<string, Event> */
    private array $signals = [];

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
        $this->eventBase = new EventBase();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function ($resource, $what, StreamCallback $streamCallback): void {
            $this->enqueueCallback($streamCallback);
        };

        $this->timerCallback = function ($resource, $what, TimerCallback $timerCallback): void {
            $this->enqueueCallback($timerCallback);
        };

        $this->signalCallback = function ($signo, $what, SignalCallback $signalCallback): void {
            $this->enqueueCallback($signalCallback);
        };
    }

    public static function isSupported(): bool
    {
        return extension_loaded("event");
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);

        if (isset($this->events[$callbackId])) {
            $this->events[$callbackId]->free();
            unset($this->events[$callbackId]);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            $event?->free();
        }

        // Unset here, otherwise $event->del() fails with a warning, because __destruct order isn't defined.
        // See https://github.com/amphp/amp/issues/159.
        $this->events = [];

        // Manually free the loop handle to fully release loop resources.
        // See https://github.com/amphp/amp/issues/177.
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (property_exists($this, 'eventBase') && $this->eventBase instanceof EventBase) {
            $this->eventBase->free();
            unset($this->eventBase);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        assert($active !== null);

        foreach ($active as $event) {
            $event->del();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
            $event->add();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->del();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $event->add();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->eventBase->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): EventBase
    {
        return $this->eventBase;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        $this->eventBase->loop($blocking ? EventBase::LOOP_ONCE : EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();

        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof StreamReadableCallback) {
                    assert(is_resource($callback->stream));

                    $this->events[$id] = new Event(
                        $this->eventBase,
                        $callback->stream,
                        Event::READ | Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof StreamWritableCallback) {
                    assert(is_resource($callback->stream));

                    $this->events[$id] = new Event(
                        $this->eventBase,
                        $callback->stream,
                        Event::WRITE | Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof TimerCallback) {
                    $this->events[$id] = new Event(
                        $this->eventBase,
                        -1,
                        Event::TIMEOUT,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = new Event(
                        $this->eventBase,
                        $callback->signal,
                        Event::SIGNAL | Event::PERSIST,
                        $this->signalCallback,
                        $callback
                    );
                } else {
                    // @codeCoverageIgnoreStart
                    throw new Error("Unknown callback type");
                    // @codeCoverageIgnoreEnd
                }
            }

            if ($callback instanceof TimerCallback) {
                $interval = min(max(0, $callback->expiration - $now), PHP_INT_MAX / 2);
                $this->events[$id]->add(max($interval, 0));
            } elseif ($callback instanceof SignalCallback) {
                $this->signals[$id] = $this->events[$id];
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            } else {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            }
        }
    }

    protected function now(): float
    {
        return (float)hrtime(true) / 1_000_000_000;
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(DriverCallback $driverCallback): void
    {
        if (isset($this->events[$id = $driverCallback->id])) {
            $this->events[$id]->del();

            if ($driverCallback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
