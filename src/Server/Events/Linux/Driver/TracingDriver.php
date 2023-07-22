<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Driver;

use Closure;
use localzet\Server\Events\Linux\CallbackType;
use localzet\Server\Events\Linux\Driver;
use localzet\Server\Events\Linux\InvalidCallbackError;
use localzet\Server\Events\Linux\Suspension;
use localzet\Server\Events\Linux\UnsupportedFeatureException;
use function array_keys;
use function array_map;
use function debug_backtrace;
use function implode;
use function rtrim;
use const DEBUG_BACKTRACE_IGNORE_ARGS;

/**
 *
 */
final class TracingDriver implements Driver
{
    /**
     * @var Driver
     */
    private readonly Driver $driver;

    /** @var array<string, true> */
    private array $enabledCallbacks = [];

    /** @var array<string, true> */
    private array $unreferencedCallbacks = [];

    /** @var array<string, string> */
    private array $creationTraces = [];

    /** @var array<string, string> */
    private array $cancelTraces = [];

    /**
     * @param Driver $driver
     */
    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        $this->driver->stop();
    }

    /**
     * @return Suspension
     */
    public function getSuspension(): Suspension
    {
        return $this->driver->getSuspension();
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    /**
     * @param Closure $closure
     * @return string
     */
    public function defer(Closure $closure): string
    {
        $id = $this->driver->defer(function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param string $callbackId
     * @return void
     */
    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);

        if (!isset($this->cancelTraces[$callbackId])) {
            $this->cancelTraces[$callbackId] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledCallbacks[$callbackId], $this->unreferencedCallbacks[$callbackId]);
    }

    /**
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param array<array{file?: string, line: int, type?: string, class?: class-string, function: string}> $trace
     *     Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return implode("\n", array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, array_keys($trace)));
    }

    /**
     * @param float $delay
     * @param Closure $closure
     * @return string
     */
    public function delay(float $delay, Closure $closure): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($closure) {
            $this->cancel($args[0]);
            return $closure(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param float $interval
     * @param Closure $closure
     * @return string
     */
    public function repeat(float $interval, Closure $closure): string
    {
        $id = $this->driver->repeat($interval, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param mixed $stream
     * @param Closure $closure
     * @return string
     */
    public function onReadable(mixed $stream, Closure $closure): string
    {
        $id = $this->driver->onReadable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param mixed $stream
     * @param Closure $closure
     * @return string
     */
    public function onWritable(mixed $stream, Closure $closure): string
    {
        $id = $this->driver->onWritable($stream, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param int $signal
     * @param Closure $closure
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        $id = $this->driver->onSignal($signal, $closure);

        $this->creationTraces[$id] = $this->formatStacktrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    /**
     * @param string $callbackId
     * @return string
     */
    public function enable(string $callbackId): string
    {
        try {
            $this->driver->enable($callbackId);
            $this->enabledCallbacks[$callbackId] = true;
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * @param string $callbackId
     * @return string
     */
    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    /**
     * @param string $callbackId
     * @return string
     */
    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * @param string $callbackId
     * @return string
     */
    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    /**
     * @param string $callbackId
     * @return string
     */
    public function reference(string $callbackId): string
    {
        try {
            $this->driver->reference($callbackId);
            unset($this->unreferencedCallbacks[$callbackId]);
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    /**
     * @param string $callbackId
     * @return string
     */
    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    /**
     * @param Closure|null $errorHandler
     * @return void
     */
    public function setErrorHandler(?Closure $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * @return Closure|null
     */
    public function getErrorHandler(): ?Closure
    {
        return $this->driver->getErrorHandler();
    }

    /** @inheritdoc */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    /**
     * @return string
     */
    public function dump(): string
    {
        $dump = "Enabled, referenced callbacks keeping the loop running: ";

        foreach ($this->enabledCallbacks as $callbackId => $_) {
            if (isset($this->unreferencedCallbacks[$callbackId])) {
                continue;
            }

            $dump .= "Callback identifier: " . $callbackId . "\r\n";
            $dump .= $this->getCreationTrace($callbackId);
            $dump .= "\r\n\r\n";
        }

        return rtrim($dump);
    }

    /**
     * @return array|string[]
     */
    public function getIdentifiers(): array
    {
        return $this->driver->getIdentifiers();
    }

    /**
     * @param string $callbackId
     * @return CallbackType
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    /**
     * @param string $callbackId
     * @return bool
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    /**
     * @param string $callbackId
     * @return bool
     */
    public function isReferenced(string $callbackId): bool
    {
        return $this->driver->isReferenced($callbackId);
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    /**
     * @param Closure $closure
     * @param mixed ...$args
     * @return void
     */
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }
}
