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

namespace localzet\Server\Events\Linux\Driver;

use Closure;
use Error;
use localzet\Server\Events\Linux\Internal\{AbstractDriver,
    DriverCallback,
    SignalCallback,
    StreamCallback,
    StreamReadableCallback,
    StreamWritableCallback,
    TimerCallback};
use UV;
use UVLoop;
use function assert;
use function ceil;
use function extension_loaded;
use function is_resource;
use function max;
use function min;
use function uv_is_active;
use function uv_loop_new;
use function uv_now;
use function uv_poll_init_socket;
use function uv_poll_start;
use function uv_poll_stop;
use function uv_run;
use function uv_signal_init;
use function uv_signal_start;
use function uv_signal_stop;
use function uv_timer_init;
use function uv_timer_start;
use function uv_timer_stop;
use function uv_update_time;
use const PHP_INT_MAX;

/**
 * Класс UvDriver
 */
final class UvDriver extends AbstractDriver
{
    /** @var resource|UVLoop Ресурс uv_loop, созданный с помощью uv_loop_new() */
    private $handle;
    /** @var array<string, resource> Массив событий */
    private array $events = [];
    /** @var array<int, array<array-key, DriverCallback>> Массив обратных вызовов */
    private array $uvCallbacks = [];
    /** @var array<int, resource> Массив потоков */
    private array $streams = [];
    /**
     * @var Closure
     * Замыкание для обратного вызова ввода-вывода
     */
    private readonly Closure $ioCallback;
    /**
     * @var Closure
     * Замыкание для обратного вызова таймера
     */
    private readonly Closure $timerCallback;
    /**
     * @var Closure
     * Замыкание для обратного вызова сигнала
     */
    private readonly Closure $signalCallback;

    /**
     * Конструктор класса UvDriver
     */
    public function __construct()
    {
        parent::__construct();

        $this->handle = uv_loop_new();

        $this->ioCallback = function ($event, $status, $events, $resource): void {
            $callbacks = $this->uvCallbacks[(int)$event];

            // Вызываем обратный вызов при ошибках, так как это соответствует поведению с другими бэкендами цикла.
            // Включаем обратный вызов, так как libuv отключает обратный вызов при ненулевом статусе.
            if ($status !== 0) {
                $flags = 0;
                foreach ($callbacks as $callback) {
                    assert($callback instanceof StreamCallback);

                    $flags |= $callback->invokable ? $this->getStreamCallbackFlags($callback) : 0;
                }
                uv_poll_start($event, $flags, $this->ioCallback);
            }

            foreach ($callbacks as $callback) {
                assert($callback instanceof StreamCallback);

                // События объединяются с 4 для активации обратного вызова, если не указаны события (0) или при UV_DISCONNECT (4).
                // http://docs.libuv.org/en/v1.x/poll.html
                if (!($this->getStreamCallbackFlags($callback) & $events || ($events | 4) === 4)) {
                    continue;
                }

                $this->enqueueCallback($callback);
            }
        };

        $this->timerCallback = function ($event): void {
            $callback = $this->uvCallbacks[(int)$event][0];

            assert($callback instanceof TimerCallback);

            $this->enqueueCallback($callback);
        };

        $this->signalCallback = function ($event): void {
            $callback = $this->uvCallbacks[(int)$event][0];

            $this->enqueueCallback($callback);
        };
    }

    private function getStreamCallbackFlags(StreamCallback $callback): int
    {
        if ($callback instanceof StreamWritableCallback) {
            return UV::WRITABLE;
        }

        if ($callback instanceof StreamReadableCallback) {
            return UV::READABLE;
        }

        throw new Error('Invalid callback type');
    }

    public static function isSupported(): bool
    {
        return extension_loaded("uv");
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);

        if (!isset($this->events[$callbackId])) {
            return;
        }

        $event = $this->events[$callbackId];
        $eventId = (int)$event;

        if (isset($this->uvCallbacks[$eventId][0])) { // All except IO callbacks.
            unset($this->uvCallbacks[$eventId]);
        } elseif (isset($this->uvCallbacks[$eventId][$callbackId])) {
            $callback = $this->uvCallbacks[$eventId][$callbackId];
            unset($this->uvCallbacks[$eventId][$callbackId]);

            assert($callback instanceof StreamCallback);

            if (empty($this->uvCallbacks[$eventId])) {
                unset($this->uvCallbacks[$eventId], $this->streams[(int)$callback->stream]);
            }
        }

        unset($this->events[$callbackId]);
    }

    /**
     * @return UVLoop|resource
     */
    public function getHandle(): mixed
    {
        return $this->handle;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        /** @psalm-suppress TooManyArguments */
        uv_run($this->handle, $blocking ? UV::RUN_ONCE : UV::RUN_NOWAIT);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();

        foreach ($callbacks as $callback) {
            $id = $callback->id;

            if ($callback instanceof StreamCallback) {
                assert(is_resource($callback->stream));

                $streamId = (int)$callback->stream;

                if (isset($this->streams[$streamId])) {
                    $event = $this->streams[$streamId];
                } elseif (isset($this->events[$id])) {
                    $event = $this->streams[$streamId] = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->streams[$streamId] = uv_poll_init_socket($this->handle, $callback->stream);
                }

                $eventId = (int)$event;
                $this->events[$id] = $event;
                $this->uvCallbacks[$eventId][$id] = $callback;

                $flags = 0;
                foreach ($this->uvCallbacks[$eventId] as $w) {
                    assert($w instanceof StreamCallback);

                    $flags |= $w->enabled ? ($this->getStreamCallbackFlags($w)) : 0;
                }
                uv_poll_start($event, $flags, $this->ioCallback);
            } elseif ($callback instanceof TimerCallback) {
                $event = $this->events[$id] ?? ($this->events[$id] = uv_timer_init($this->handle));

                $this->uvCallbacks[(int)$event] = [$callback];

                uv_timer_start(
                    $event,
                    (int)min(max(0, ceil(($callback->expiration - $now) * 1000)), PHP_INT_MAX),
                    $callback->repeat ? (int)min(max(0, ceil($callback->interval * 1000)), PHP_INT_MAX) : 0,
                    $this->timerCallback
                );
            } elseif ($callback instanceof SignalCallback) {
                if (isset($this->events[$id])) {
                    $event = $this->events[$id];
                } else {
                    /** @psalm-suppress TooManyArguments */
                    $event = $this->events[$id] = uv_signal_init($this->handle);
                }

                $this->uvCallbacks[(int)$event] = [$callback];

                /** @psalm-suppress TooManyArguments */
                uv_signal_start($event, $this->signalCallback, $callback->signal);
            } else {
                // @codeCoverageIgnoreStart
                throw new Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    protected function now(): float
    {
        uv_update_time($this->handle);

        /** @psalm-suppress TooManyArguments */
        return uv_now($this->handle) / 1000;
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(DriverCallback $callback): void
    {
        $id = $callback->id;

        if (!isset($this->events[$id])) {
            return;
        }

        $event = $this->events[$id];

        if (!uv_is_active($event)) {
            return;
        }

        if ($callback instanceof StreamCallback) {
            $flags = 0;
            foreach ($this->uvCallbacks[(int)$event] as $w) {
                assert($w instanceof StreamCallback);

                $flags |= $w->invokable ? ($this->getStreamCallbackFlags($w)) : 0;
            }

            if ($flags) {
                uv_poll_start($event, $flags, $this->ioCallback);
            } else {
                uv_poll_stop($event);
            }
        } elseif ($callback instanceof TimerCallback) {
            uv_timer_stop($event);
        } elseif ($callback instanceof SignalCallback) {
            uv_signal_stop($event);
        } else {
            // @codeCoverageIgnoreStart
            throw new Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }
}
