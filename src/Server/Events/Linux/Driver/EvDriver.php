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
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

/** @noinspection PhpComposerExtensionStubsInspection */

namespace localzet\Server\Events\Linux\Driver;

use Closure;
use Error;
use Ev;
use EvIo;
use EvLoop;
use EvSignal;
use EvTimer;
use EvWatcher;
use localzet\Server\Events\Linux\Internal\{AbstractDriver,
    DriverCallback,
    SignalCallback,
    StreamCallback,
    StreamReadableCallback,
    StreamWritableCallback,
    TimerCallback};
use function assert;
use function extension_loaded;
use function get_class;
use function hrtime;
use function is_resource;
use function max;

/**
 *
 */
final class EvDriver extends AbstractDriver
{
    /** @var array<string, EvSignal>|null */
    private static ?array $activeSignals = null;
    /**
     * @var EvLoop
     */
    private EvLoop $handle;
    /** @var array<string, EvWatcher> */
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
    /** @var array<string, EvSignal> */
    private array $signals = [];

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        $this->handle = new EvLoop();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function (EvIo $event): void {
            /** @var StreamCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->timerCallback = function (EvTimer $event): void {
            /** @var TimerCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function (EvSignal $event): void {
            /** @var SignalCallback $callback */
            $callback = $event->data;

            $this->enqueueCallback($callback);
        };
    }

    /**
     * @return bool
     */
    public static function isSupported(): bool
    {
        return extension_loaded("ev");
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);
        unset($this->events[$callbackId]);
    }

    /**
     *
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            /** @psalm-suppress all */
            $event?->stop();
        }

        // We need to clear all references to events manually, see
        // https://bitbucket.org/osmanov/pecl-ev/issues/31/segfault-in-ev_timer_stop
        $this->events = [];
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
    public function getHandle(): EvLoop
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        $this->handle->run($blocking ? Ev::RUN_ONCE : Ev::RUN_ONCE | Ev::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        assert($active !== null);

        foreach ($active as $event) {
            $event->stop();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            $event->start();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->stop();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                $event->start();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $this->handle->nowUpdate();
        $now = $this->now();

        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof StreamReadableCallback) {
                    assert(is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io($callback->stream, Ev::READ, $this->ioCallback, $callback);
                } elseif ($callback instanceof StreamWritableCallback) {
                    assert(is_resource($callback->stream));

                    $this->events[$id] = $this->handle->io(
                        $callback->stream,
                        Ev::WRITE,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof TimerCallback) {
                    $interval = $callback->interval;
                    $this->events[$id] = $this->handle->timer(
                        max(0, ($callback->expiration - $now)),
                        $callback->repeat ? $interval : 0,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = $this->handle->signal($callback->signal, $this->signalCallback, $callback);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new Error("Unknown callback type: " . get_class($callback));
                    // @codeCoverageIgnoreEnd
                }
            } else {
                $this->events[$id]->start();
            }

            if ($callback instanceof SignalCallback) {
                /** @psalm-suppress PropertyTypeCoercion */
                $this->signals[$id] = $this->events[$id];
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
     * @param DriverCallback $callback
     * @return void
     */
    protected function deactivate(DriverCallback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->stop();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
