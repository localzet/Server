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

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use Error;
use Fiber;
use localzet\Server\Events\Linux\{Driver};
use localzet\Server\Events\Linux\CallbackType;
use localzet\Server\Events\Linux\FiberLocal;
use localzet\Server\Events\Linux\InvalidCallbackError;
use localzet\Server\Events\Linux\Suspension;
use localzet\Server\Events\Linux\UncaughtThrowable;
use SplQueue;
use stdClass;
use Throwable;
use WeakMap;
use WeakReference;
use function array_keys;
use function array_map;
use function assert;
use function getenv;
use function sprintf;
use const PHP_VERSION_ID;

/**
 * Драйвер цикла событий, который реализует все основные операции для обеспечения взаимодействия.
 *
 * Обратные вызовы (включенные или новые обратные вызовы) ДОЛЖНЫ немедленно быть помечены как включенные, но активироваться (т.е. обратные вызовы могут
 * быть вызваны) непосредственно перед следующим тиком. Обратные вызовы НЕ ДОЛЖНЫ вызываться в тике, в котором они были включены.
 *
 * Все зарегистрированные обратные вызовы НЕ ДОЛЖНЫ вызываться из файла с включенными строгими типами (`declare(strict_types=1)`).
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Следующий идентификатор обратного вызова. */
    private string $nextId = "a";

    private Fiber $fiber;

    private Fiber $callbackFiber;

    private Closure $errorCallback;

    /** @var array<string, DriverCallback> */
    private array $callbacks = [];

    /** @var array<string, DriverCallback> */
    private array $enableQueue = [];

    /** @var array<string, DriverCallback> */
    private array $enableDeferQueue = [];

    /** @var null|Closure(Throwable):void */
    private ?Closure $errorHandler = null;

    /** @var null|Closure():mixed */
    private ?Closure $interrupt = null;

    private readonly Closure $interruptCallback;

    private readonly Closure $queueCallback;

    private readonly Closure $runCallback;

    private readonly stdClass $internalSuspensionMarker;

    /** @var SplQueue<array{Closure, array}> */
    private readonly SplQueue $microtaskQueue;

    /** @var SplQueue<DriverCallback> */
    private readonly SplQueue $callbackQueue;

    private bool $idle = false;

    private bool $stopped = false;

    private WeakMap $weakMap;

    /**
     *
     */
    public function __construct()
    {
        // PHP GC is broken on early 8.1 and 8.2 versions, see https://github.com/php/php-src/issues/10496
        if ((PHP_VERSION_ID < 80117 || PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80204) && !getenv('LCZ_DRIVER_SUPPRESS_ISSUE_10496')) {
            throw new Error('Your version of PHP is affected by serious garbage collector bugs related to fibers. Please upgrade to a newer version of PHP, i.e. >= 8.1.17 or => 8.2.4');
        }

        /** @psalm-suppress InvalidArgument */
        // Создание нового экземпляра WeakMap для приостановок.
        $this->weakMap = new WeakMap();

        // Создание нового экземпляра stdClass для внутреннего маркера приостановки.
        $this->internalSuspensionMarker = new stdClass();

        // Создание новых экземпляров SplQueue для очередей микрозадач и обратных вызовов.
        $this->microtaskQueue = new SplQueue();
        $this->callbackQueue = new SplQueue();

        // Создание нового Fiber'а для цикла событий.
        $this->createLoopFiber();

        // Создание нового Fiber'а для обратных вызовов.
        $this->createCallbackFiber();

        // Создание нового обратного вызова для обработки ошибок.
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        // Установка обратного вызова прерывания.
        $this->interruptCallback = $this->setInterrupt(...);

        // Установка обратного вызова очереди.
        $this->queueCallback = $this->queue(...);

        // Установка обратного вызова запуска.
        $this->runCallback = function () {
            // Если Fiber уже завершен, создаем новый Fiber цикла событий.
            if ($this->fiber->isTerminated()) {
                $this->createLoopFiber();
            }

            // Если Fiber уже запущен, возобновляем его. В противном случае запускаем его.
            return $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        };
    }

    private function createLoopFiber(): void
    {

        // Создание нового Fiber'а для цикла событий.
        $this->fiber = new Fiber(function (): void {
            $this->stopped = false;

            $this->invokeCallbacks();

            // Если цикл событий остановлен.
            while (!$this->stopped) {
                // Если есть обратный вызов прерывания, вызываем его.
                if ($this->interrupt instanceof Closure) {
                    $this->invokeInterrupt();
                }

                // Если все очереди пусты, возвращаемся.
                if ($this->isEmpty()) {
                    return;
                }

                // Если цикл событий неактивен, устанавливаем его в активное состояние и вызываем обратные вызовы.
                $previousIdle = $this->idle;
                $this->idle = true;

                // Если цикл событий остановлен, завершаем выполнение Fiber'а.
                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    /**
     * Вызывает обратные вызовы
     * @throws Throwable
     */
    private function invokeCallbacks(): void
    {
        while (!$this->microtaskQueue->isEmpty() || !$this->callbackQueue->isEmpty()) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callbackFiber->isStarted()
                ? $this->callbackFiber->resume()
                : $this->callbackFiber->start();

            if ($yielded !== $this->internalSuspensionMarker) {
                $this->createCallbackFiber();
            }

            if ($this->interrupt instanceof Closure) {
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * Проверяет, остались ли в цикле включенные и ссылочные обратные вызовы.
     * @return bool True, если в цикле не осталось включенных и ссылочных обратных вызовов.
     */
    private function isEmpty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * Создает обратный вызов Fiber
     */
    private function createCallbackFiber(): void
    {
        $this->callbackFiber = new Fiber(function (): void {
            do {
                $this->invokeMicrotasks();

                while (!$this->callbackQueue->isEmpty()) {
                    /** @var DriverCallback $callback */
                    $callback = $this->callbackQueue->dequeue();

                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }

                    if ($callback instanceof DeferCallback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // Отключаем и снова включаем, чтобы он не выполнялся несколько раз за один тик
                            // См. https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }

                    try {
                        $result = match (true) {
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            default => ($callback->closure)($callback->id),
                        };

                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        FiberLocal::clear();
                    }

                    unset($callback);

                    if ($this->interrupt instanceof Closure) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        Fiber::suspend($this->internalSuspensionMarker);
                    }

                    $this->invokeMicrotasks();
                }

                /** @noinspection PhpUnhandledExceptionInspection */
                Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    /**
     * Вызывает микрозадачи
     * @throws Throwable
     */
    private function invokeMicrotasks(): void
    {
        while (!$this->microtaskQueue->isEmpty()) {
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                // Очистка $args для сборки мусора
                $callback(...$args, ...($args = []));
            } catch (Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                FiberLocal::clear();
            }

            unset($callback, $args);

            if ($this->interrupt instanceof Closure) {
                /** @noinspection PhpUnhandledExceptionInspection */
                Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     * Вызывает обработчик ошибок с указанным исключением.
     *
     * @param Throwable $throwable Исключение, выброшенное из обратного вызова события.
     * @throws Throwable
     */
    final protected function error(Closure $closure, Throwable $throwable): void
    {
        if (!$this->errorHandler instanceof Closure) {
            // Явно переопределяем предыдущее прерывание, если оно существует в этом случае, скрытие исключения хуже
            $this->interrupt = static fn() => $throwable instanceof UncaughtThrowable
                ? throw $throwable
                : throw UncaughtThrowable::throwingCallback($closure, $throwable);
            return;
        }

        $fiber = new Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $throwable);
    }

    /**
     * Отменяет обратный вызов по указанному идентификатору.
     */
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    /**
     * Отключает обратный вызов по указанному идентификатору.
     */
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Обратный вызов уже отключен.
        }

        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // Обратный вызов был только поставлен в очередь для включения.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Обратный вызов был только поставлен в очередь для включения.
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    /**
     * Деактивирует (отключает) указанный обратный вызов.
     */
    abstract protected function deactivate(DriverCallback $driverCallback): void;

    /**
     * Включает обратный вызов по указанному идентификатору.
     */
    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Обратный вызов уже включен.
        }

        $callback->enabled = true;

        if ($callback instanceof DeferCallback) {
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    /**
     * Возвращает текущее время цикла событий в секундах.
     *
     * Обратите внимание, что это значение не обязательно коррелирует со временем по стенному часу, скорее, возвращаемое значение предназначено для использования
     * в относительных сравнениях с предыдущими значениями, возвращаемыми этим методом (интервалы, расчеты истечения срока и т.д.).
     */
    abstract protected function now(): float;

    /**
     * Вызывает прерывание
     * @throws Throwable
     */
    private function invokeInterrupt(): void
    {
        assert($this->interrupt instanceof Closure);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        Fiber::suspend($interrupt);
    }

    /**
     * Выполняет один тик цикла событий.
     * @throws Throwable
     */
    private function tick(bool $previousIdle): void
    {
        $this->activate($this->enableQueue);

        foreach ($this->enableQueue as $callback) {
            $callback->invokable = true;
        }

        $this->enableQueue = [];

        foreach ($this->enableDeferQueue as $callback) {
            $callback->invokable = true;
            $this->enqueueCallback($callback);
        }

        $this->enableDeferQueue = [];

        $blocking = $previousIdle
            && !$this->stopped
            && !$this->isEmpty();

        if ($blocking) {
            $this->invokeCallbacks();

            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enableDeferQueue) || !empty($this->enableQueue)) {
                $blocking = false;
            }
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }

    /**
     * Активирует (включает) все указанные обратные вызовы.
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * Добавляет обратный вызов в очередь.
     */
    final protected function enqueueCallback(DriverCallback $driverCallback): void
    {
        $this->callbackQueue->enqueue($driverCallback);
        $this->idle = false;
    }

    /**
     * Отправляет все ожидающие события чтения/записи, таймеры и сигналы.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * Создает обратный вызов для ошибок.
     */
    private function createErrorCallback(): void
    {
        $this->errorCallback = function (Closure $errorHandler, Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (Throwable $throwable) {
                $this->interrupt = static fn() => $throwable instanceof UncaughtThrowable
                    ? throw $throwable
                    : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $throwable);
            }
        };
    }

    /**
     * Устанавливает прерывание.
     *
     * @param Closure():mixed $interrupt
     */
    private function setInterrupt(Closure $interrupt): void
    {
        assert(!$this->interrupt instanceof Closure);

        $this->interrupt = $interrupt;
    }

    /**
     * Запускает цикл событий.
     *
     * @throws Throwable
     */
    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new Error("Цикл событий уже запущен");
        }

        if (Fiber::getCurrent() instanceof Fiber) {
            throw new Error(sprintf("Нельзя вызывать %s() внутри Fiber'а (т.е. вне {main})", __METHOD__));
        }

        if ($this->fiber->isTerminated()) {
            $this->createLoopFiber();
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $lambda = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();

        if ($lambda) {
            $lambda();

            throw new Error('Прерывание из цикла событий должно вызвать исключение: ' . ClosureHelper::getDescription($lambda));
        }
    }

    /**
     * Проверяет, запущен ли цикл событий.
     */
    public function isRunning(): bool
    {
        if ($this->fiber->isRunning()) {
            return true;
        }

        return $this->fiber->isSuspended();
    }

    /**
     * Останавливает цикл событий.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Добавляет задачу в очередь.
     */
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    /**
     * Откладывает выполнение задачи.
     */
    public function defer(Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    /**
     * Задерживает выполнение задачи.
     */
    public function delay(float $delay, Closure $closure): string
    {
        if ($delay < 0) {
            throw new Error("Задержка должна быть больше или равна нулю");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * Повторяет выполнение задачи с указанным интервалом.
     */
    public function repeat(float $interval, Closure $closure): string
    {
        if ($interval < 0) {
            throw new Error("Интервал должен быть больше или равен нулю");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * Выполняет задачу при возникновении события чтения.
     */
    public function onReadable(mixed $stream, Closure $closure): string
    {
        $streamReadableCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamReadableCallback->id] = $streamReadableCallback;
        $this->enableQueue[$streamReadableCallback->id] = $streamReadableCallback;

        return $streamReadableCallback->id;
    }

    /**
     * Выполняет задачу при возникновении события записи.
     *
     * @param $stream
     */
    public function onWritable($stream, Closure $closure): string
    {
        $streamWritableCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamWritableCallback->id] = $streamWritableCallback;
        $this->enableQueue[$streamWritableCallback->id] = $streamWritableCallback;

        return $streamWritableCallback->id;
    }

    /**
     * Выполняет задачу при получении сигнала.
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

    /**
     * Добавляет ссылку на обратный вызов.
     */
    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
    }

    /**
     * Удаляет ссылку на обратный вызов.
     */
    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    /**
     * Возвращает текущую приостановку.
     */
    public function getSuspension(): Suspension
    {
        $fiber = Fiber::getCurrent();

        // Пользовательские обратные вызовы всегда выполняются вне Fiber'а цикла событий, поэтому это всегда должно быть false.
        assert($fiber !== $this->fiber);

        // Use queue closure in case of {main}, which can be unset by DriverSuspension after an uncaught exception.
        $key = $fiber ?? $this->queueCallback;

        // Используем текущий объект в случае {main}
        $suspension = ($this->weakMap[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }

        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->weakMap,
        );

        $this->weakMap[$key] = WeakReference::create($suspension);

        return $suspension;
    }

    /**
     * Возвращает обработчик ошибок.
     */
    public function getErrorHandler(): ?Closure
    {
        return $this->errorHandler;
    }

    /**
     * Устанавливает обработчик ошибок.
     */
    public function setErrorHandler(?Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * Возвращает информацию для отладки.
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return array_map(fn(DriverCallback $driverCallback): array => [
            'type' => $this->getType($driverCallback->id),
            'enabled' => $driverCallback->enabled,
            'referenced' => $driverCallback->referenced,
        ], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Возвращает тип обратного вызова.
     */
    public function getType(string $callbackId): CallbackType
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return match ($callback::class) {
            DeferCallback::class => CallbackType::Defer,
            TimerCallback::class => $callback->repeat ? CallbackType::Repeat : CallbackType::Delay,
            StreamReadableCallback::class => CallbackType::Readable,
            StreamWritableCallback::class => CallbackType::Writable,
            SignalCallback::class => CallbackType::Signal,
        };
    }

    /**
     * Возвращает идентификаторы обратных вызовов.
     *
     * @return array|string[]
     */
    public function getIdentifiers(): array
    {
        return array_keys($this->callbacks);
    }

    /**
     * Проверяет, включен ли обратный вызов.
     */
    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    /**
     * Проверяет, имеет ли обратный вызов ссылку.
     */
    public function isReferenced(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }
}
