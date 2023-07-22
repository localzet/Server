<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use Error;
use Fiber;
use localzet\Server\Events\Linux\CallbackType;
use localzet\Server\Events\Linux\Driver;
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
use function sprintf;

/**
 * Event loop driver which implements all basic operations to allow interoperability.
 *
 * Callbacks (enabled or new callbacks) MUST immediately be marked as enabled, but only be activated (i.e. callbacks can
 * be called) right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
 *
 * All registered callbacks MUST NOT be called from a file with strict types enabled (`declare(strict_types=1)`).
 *
 * @internal
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next callback identifier. */
    private string $nextId = "a";

    /**
     * @var Fiber
     */
    private Fiber $fiber;

    /**
     * @var Fiber
     */
    private Fiber $callbackFiber;
    /**
     * @var Closure
     */
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

    /**
     * @var Closure
     */
    private readonly Closure $interruptCallback;
    /**
     * @var Closure
     */
    private readonly Closure $queueCallback;
    /**
     * @var Closure
     */
    private readonly Closure $runCallback;

    /**
     * @var stdClass
     */
    private readonly stdClass $internalSuspensionMarker;

    /** @var SplQueue<array{Closure, array}> */
    private readonly SplQueue $microtaskQueue;

    /** @var SplQueue<DriverCallback> */
    private readonly SplQueue $callbackQueue;

    /**
     * @var bool
     */
    private bool $idle = false;
    /**
     * @var bool
     */
    private bool $stopped = false;

    /**
     * @var WeakMap
     */
    private WeakMap $suspensions;

    /**
     *
     */
    public function __construct()
    {
        $this->suspensions = new WeakMap();

        $this->internalSuspensionMarker = new stdClass();
        $this->microtaskQueue = new SplQueue();
        $this->callbackQueue = new SplQueue();

        $this->createLoopFiber();
        $this->createCallbackFiber();
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        $this->interruptCallback = $this->setInterrupt(...);
        $this->queueCallback = $this->queue(...);
        $this->runCallback = function () {
            if ($this->fiber->isTerminated()) {
                $this->createLoopFiber();
            }

            return $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        };
    }

    /**
     * @return void
     */
    private function createLoopFiber(): void
    {
        $this->fiber = new Fiber(function (): void {
            $this->stopped = false;

            // Invoke microtasks if we have some
            $this->invokeCallbacks();

            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                if ($this->isEmpty()) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;

                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    /**
     * @return void
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

            if ($this->interrupt) {
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * @return bool True if no enabled and referenced callbacks remain in the loop.
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
     * @return void
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
                            // Disable and re-enable, so it's not executed repeatedly in the same tick
                            // See https://github.com/amphp/amp/issues/131
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

                    if ($this->interrupt) {
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
     * @return void
     * @throws Throwable
     */
    private function invokeMicrotasks(): void
    {
        while (!$this->microtaskQueue->isEmpty()) {
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                // Clear $args to allow garbage collection
                $callback(...$args, ...($args = []));
            } catch (Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                FiberLocal::clear();
            }

            unset($callback, $args);

            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     * Invokes the error handler with the given exception.
     *
     * @param Closure $closure
     * @param Throwable $exception The exception thrown from an event callback.
     * @throws Throwable
     */
    final protected function error(Closure $closure, Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn() => $exception instanceof UncaughtThrowable
                ? throw $exception
                : throw UncaughtThrowable::throwingCallback($closure, $exception);
            return;
        }

        $fiber = new Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * @param string $callbackId
     * @return void
     */
    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    /**
     * @param string $callbackId
     * @return string
     */
    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // Callback already disabled.
        }

        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // Callback was only queued to be enabled.
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // Callback was only queued to be enabled.
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    /**
     * Deactivates (disables) the given callback.
     */
    abstract protected function deactivate(DriverCallback $callback): void;

    /**
     * @param string $callbackId
     * @return string
     */
    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
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
     * Returns the current event loop time in second increments.
     *
     * Note this value does not necessarily correlate to wall-clock time, rather the value returned is meant to be used
     * in relative comparisons to prior values returned by this method (intervals, expiration calculations, etc.).
     */
    abstract protected function now(): float;

    /**
     * @return void
     * @throws Throwable
     */
    private function invokeInterrupt(): void
    {
        assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        Fiber::suspend($interrupt);
    }

    /**
     * Executes a single tick of the event loop.
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
     * Activates (enables) all the given callbacks.
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * @param DriverCallback $callback
     * @return void
     */
    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue->enqueue($callback);
        $this->idle = false;
    }

    /**
     * Dispatches any pending read/write, timer, and signal events.
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * @return void
     */
    private function createErrorCallback(): void
    {
        $this->errorCallback = function (Closure $errorHandler, Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (Throwable $exception) {
                $this->setInterrupt(
                    static fn() => $exception instanceof UncaughtThrowable
                        ? throw $exception
                        : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $exception)
                );
            }
        };
    }

    /**
     * @param Closure():mixed $interrupt
     */
    private function setInterrupt(Closure $interrupt): void
    {
        assert($this->interrupt === null);

        $this->interrupt = $interrupt;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new Error("The event loop is already running");
        }

        if (Fiber::getCurrent()) {
            throw new Error(sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        if ($this->fiber->isTerminated()) {
            $this->createLoopFiber();
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $lambda = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();

        if ($lambda) {
            $lambda();

            throw new Error('Interrupt from event loop must throw an exception: ' . ClosureHelper::getDescription($lambda));
        }
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * @param Closure $closure
     * @param mixed ...$args
     * @return void
     */
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    /**
     * @param Closure $closure
     * @return string
     */
    public function defer(Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    /**
     * @param float $delay
     * @param Closure $closure
     * @return string
     */
    public function delay(float $delay, Closure $closure): string
    {
        if ($delay < 0) {
            throw new Error("Delay must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * @param float $interval
     * @param Closure $closure
     * @return string
     */
    public function repeat(float $interval, Closure $closure): string
    {
        if ($interval < 0) {
            throw new Error("Interval must be greater than or equal to zero");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    /**
     * @param mixed $stream
     * @param Closure $closure
     * @return string
     */
    public function onReadable(mixed $stream, Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * @param $stream
     * @param Closure $closure
     * @return string
     */
    public function onWritable($stream, Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    /**
     * @param int $signal
     * @param Closure $closure
     * @return string
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

    /**
     * @param string $callbackId
     * @return string
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
     * @param string $callbackId
     * @return string
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
     * @return Suspension
     */
    public function getSuspension(): Suspension
    {
        $fiber = Fiber::getCurrent();

        // User callbacks are always executed outside the event loop fiber, so this should always be false.
        assert($fiber !== $this->fiber);

        // Use current object in case of {main}
        $suspension = ($this->suspensions[$fiber ?? $this] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }

        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->suspensions,
        );

        $this->suspensions[$fiber ?? $this] = WeakReference::create($suspension);

        return $suspension;
    }

    /**
     * @return Closure|null
     */
    public function getErrorHandler(): ?Closure
    {
        return $this->errorHandler;
    }

    /**
     * @param Closure|null $errorHandler
     * @return void
     */
    public function setErrorHandler(?Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        // @codeCoverageIgnoreStart
        return array_map(fn(DriverCallback $callback) => [
            'type' => $this->getType($callback->id),
            'enabled' => $callback->enabled,
            'referenced' => $callback->referenced,
        ], $this->callbacks);
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param string $callbackId
     * @return CallbackType
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
     * @return array|string[]
     */
    public function getIdentifiers(): array
    {
        return array_keys($this->callbacks);
    }

    /**
     * @param string $callbackId
     * @return bool
     */
    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    /**
     * @param string $callbackId
     * @return bool
     */
    public function isReferenced(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }
}
