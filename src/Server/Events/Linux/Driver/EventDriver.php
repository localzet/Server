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
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Events\Linux\Driver;

use Closure;
use Error;
use Event;
use EventBase;
use localzet\Server\Events\Linux\Internal\{AbstractDriver,
    DriverCallback,
    SignalCallback,
    StreamCallback,
    StreamReadableCallback,
    StreamWritableCallback,
    TimerCallback};
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
    /**
     * @var EventBase
     */
    private EventBase $handle;
    /** @var array<string, Event> */
    private array $events = [];
    /**
     * @var Closure
     */
    private readonly Closure $ioCallback;
    /**
     * @var Closure
     */
    private readonly Closure $timerCallback;
    /**
     * @var Closure
     */
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
        $this->handle = new EventBase();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function ($resource, $what, StreamCallback $callback): void {
            $this->enqueueCallback($callback);
        };

        $this->timerCallback = function ($resource, $what, TimerCallback $callback): void {
            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function ($signo, $what, SignalCallback $callback): void {
            $this->enqueueCallback($callback);
        };
    }

    /**
     * @return bool
     */
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
        if (isset($this->handle)) {
            $this->handle->free();
            unset($this->handle);
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
        $this->handle->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): EventBase
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        $this->handle->loop($blocking ? EventBase::LOOP_ONCE : EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK);
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
                        $this->handle,
                        $callback->stream,
                        Event::READ | Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof StreamWritableCallback) {
                    assert(is_resource($callback->stream));

                    $this->events[$id] = new Event(
                        $this->handle,
                        $callback->stream,
                        Event::WRITE | Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof TimerCallback) {
                    $this->events[$id] = new Event(
                        $this->handle,
                        -1,
                        Event::TIMEOUT,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = new Event(
                        $this->handle,
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

    /**
     * @return float
     */
    protected function now(): float
    {
        return (float)hrtime(true) / 1_000_000_000;
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(DriverCallback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->del();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
