<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Events;

use localzet\Server\Events\Linux\CallbackType;
use localzet\Server\Events\Linux\Driver;
use localzet\Server\Events\Linux\DriverFactory;
use localzet\Server\Events\Linux\Internal\AbstractDriver;
use localzet\Server\Events\Linux\Internal\DriverCallback;
use localzet\Server\Events\Linux\InvalidCallbackError;
use localzet\Server\Events\Linux\Suspension;
use localzet\Server\Events\Linux\UnsupportedFeatureException;
use function count;
use function function_exists;
use function pcntl_signal;

/**
 * Linux eventloop
 *
 * Класс Linux представляет собой реализацию интерфейса EventInterface.
 * Он предоставляет функциональность для управления обработчиками событий чтения, записи, таймеров и сигналов.
 */
final class Linux implements EventInterface
{
    /**
     * @var Driver
     */
    protected Driver $driver;

    /**
     * Все обработчики события чтения.
     * @var array
     */
    protected array $readEvents = [];

    /**
     * Все обработчики события записи.
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * Обработчики событий сигналов.
     * @var array
     */
    protected array $eventSignal = [];

    /**
     * Обработчики событий таймеров.
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * Идентификатор таймера.
     * @var int
     */
    protected int $timerId = 1;

    /**
     * Конструктор.
     *
     * Создает новый экземпляр класса Linux и инициализирует драйвер EventLoop.
     */
    public function __construct()
    {
        $this->driver = $this->getDriver();
    }

    /**
     * Получить драйвер.
     * @return Driver Драйвер.
     */
    public function driver(): Driver
    {
        return $this->getDriver();
    }

    /**
     * Run the event loop.
     *
     * This function may only be called from {main}, that is, not within a fiber.
     *
     * Libraries should use the {@link Suspension} API instead of calling this method.
     *
     * This method will not return until the event loop does not contain any pending, referenced callbacks anymore.
     */
    public function run(): void
    {
        $this->getDriver()->run();
    }

    /**
     * Останавливает цикл обработки событий.
     */
    public function stop(): void
    {
        foreach ($this->eventSignal as $cbId) {
            $this->getDriver()->cancel($cbId);
        }
        $this->getDriver()->stop();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * Устанавливает отложенное событие выполнения функции.
     * @param float $delay Задержка перед выполнением в секундах.
     * @param callable $func Функция для выполнения.
     * @param array $args Аргументы функции (по умолчанию пустой массив).
     * @return int Идентификатор таймера.
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...$args);
        };
        $cbId = $this->getDriver()->delay($delay, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * Устанавливает повторяющееся событие выполнения функции.
     * @param float $interval Интервал между повторениями в секундах.
     * @param callable $func Функция для выполнения.
     * @param array $args Аргументы функции (по умолчанию пустой массив).
     * @return int Идентификатор таймера.
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args) {
            $func(...$args);
        };
        $cbId = $this->getDriver()->repeat($interval, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * Устанавливает обработчик события чтения.
     * @param mixed $stream Поток для чтения.
     * @param callable $func Функция-обработчик.
     */
    public function onReadable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->readEvents);
        $this->readEvents[(int)$stream] = $this->getDriver()->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * Удаляет обработчик события чтения.
     * @param mixed $stream Поток для чтения.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offReadable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->readEvents);
    }

    /**
     * Устанавливает обработчик события записи.
     * @param mixed $stream Поток для записи.
     * @param callable $func Функция-обработчик.
     */
    public function onWritable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->writeEvents);
        $this->writeEvents[(int)$stream] = $this->getDriver()->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * Удаляет обработчик события записи.
     * @param mixed $stream Поток для записи.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offWritable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->writeEvents);
    }

    /**
     * Устанавливает обработчик события сигнала.
     * @param int $signal Номер сигнала.
     * @param callable $func Функция-обработчик.
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signal, callable $func): void
    {
        $this->cancelAndUnset($signal, $this->eventSignal);
        $this->eventSignal[$signal] = $this->getDriver()->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * Удаляет обработчик события сигнала.
     * @param int $signal Номер сигнала.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offSignal(int $signal): bool
    {
        return $this->cancelAndUnset($signal, $this->eventSignal);
    }

    /**
     * Удаляет отложенное событие по идентификатору таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно удален, иначе false.
     */
    public function offDelay(int $timerId): bool
    {
        return $this->cancelAndUnset($timerId, $this->eventTimer);
    }

    /**
     * Удаляет повторяющееся событие по идентификатору таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно удален, иначе false.
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * Удаляет все таймеры.
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $cbId) {
            $this->getDriver()->cancel($cbId);
        }
        $this->eventTimer = [];
    }

    /**
     * Возвращает количество таймеров.
     * @return int Количество таймеров.
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * Устанавливает обработчик ошибок.
     * @param callable $errorHandler Функция-обработчик ошибок.
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->getDriver()->setErrorHandler($errorHandler);
    }

    /**
     * Возвращает текущий обработчик ошибок.
     * @return callable|null Обработчик ошибок или null, если обработчик не установлен.
     */
    public function getErrorHandler(): ?callable
    {
        return $this->getDriver()->getErrorHandler();
    }

    /**
     * Отменить регистрацию и удалить обработчик события.
     * @param mixed $key Ключ обработчика.
     * @param array $eventArray Массив событий.
     * @return bool Возвращает true, если обработчик был успешно отменен и удален, иначе false.
     */
    protected function cancelAndUnset($key, array &$events): bool
    {
        $fdKey = (int)$key;
        if (isset($events[$fdKey])) {
            $this->getDriver()->cancel($events[$fdKey]);
            unset($events[$fdKey]);
            return true;
        }
        return false;
    }

    /*********************************************NEW********************************************************/

    /**
     * Sets the driver to be used as the event loop.
     */
    public function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset($this->driver) && $this->driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        try {
            /** @psalm-suppress InternalClass */
            $this->driver = new class () extends AbstractDriver {
                protected function activate(array $callbacks): void
                {
                    throw new \Error("Can't activate callback during garbage collection.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                protected function deactivate(DriverCallback $callback): void
                {
                    // do nothing
                }

                public function getHandle(): mixed
                {
                    return null;
                }

                protected function now(): float
                {
                    return (float)\hrtime(true) / 1_000_000_000;
                }
            };

            \gc_collect_cycles();
        } finally {
            $this->driver = $driver;
        }
    }

    /**
     * Queue a microtask.
     *
     * The queued callback MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see EventLoop::defer()} if you need these features.
     *
     * @param \Closure(...):void $closure The callback to queue.
     * @param mixed ...$args The callback arguments.
     */
    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->getDriver()->queue($closure, ...$args);
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callback MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Deferred callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param \Closure(string):void $closure The callback to defer. The `$callbackId` will be
     *     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public function defer(\Closure $closure): string
    {
        return $this->getDriver()->defer($closure);
    }

    /**
     * Enable a callback to be active starting in the next tick.
     *
     * Callbacks MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right
     * before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public function enable(string $callbackId): string
    {
        return $this->getDriver()->enable($callbackId);
    }

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables another deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public function disable(string $callbackId): string
    {
        return $this->getDriver()->disable($callbackId);
    }

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public function cancel(string $callbackId): void
    {
        $this->getDriver()->cancel($callbackId);
    }

    /**
     * Reference a callback.
     *
     * This will keep the event loop alive whilst the event is still being monitored. Callbacks have this state by
     * default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidCallbackError If the callback identifier is invalid.
     */
    public function reference(string $callbackId): string
    {
        return $this->getDriver()->reference($callbackId);
    }

    /**
     * Unreference a callback.
     *
     * The event loop should exit the run method when only unreferenced callbacks are still being monitored. Callbacks
     * are all referenced by default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public function unreference(string $callbackId): string
    {
        return $this->getDriver()->unreference($callbackId);
    }

    /**
     * Returns all registered non-cancelled callback identifiers.
     *
     * @return string[] Callback identifiers.
     */
    public function getIdentifiers(): array
    {
        return $this->getDriver()->getIdentifiers();
    }

    /**
     * Returns the type of the callback identified by the given callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return CallbackType The callback type.
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->getDriver()->getType($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool {@code true} if the callback is currently enabled, otherwise {@code false}.
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->getDriver()->isEnabled($callbackId);
    }

    /**
     * Returns whether the callback identified by the given callback identifier is currently referenced.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return bool {@code true} if the callback is currently referenced, otherwise {@code false}.
     */
    public function isReferenced(string $callbackId): bool
    {
        return $this->getDriver()->isReferenced($callbackId);
    }

    /**
     * Retrieve the event loop driver that is in scope.
     *
     * @return Driver
     */
    public function getDriver(): Driver
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (!isset($this->driver)) {
            $this->setDriver((new DriverFactory())->create());
        }

        return $this->driver;
    }

    /**
     * Returns an object used to suspend and resume execution of the current fiber or {main}.
     *
     * Calls from the same fiber will return the same suspension object.
     *
     * @return Suspension
     */
    public static function getSuspension(): Suspension
    {
        return $this->getDriver()->getSuspension();
    }
}
