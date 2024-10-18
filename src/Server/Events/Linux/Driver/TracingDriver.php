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
use localzet\Server\Events\Linux\{CallbackType, Driver, InvalidCallbackError, Suspension, UnsupportedFeatureException};
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
    /** @var array<string, true> */
    private array $enabledCallbacks = [];

    /** @var array<string, true> */
    private array $unreferencedCallbacks = [];

    /** @var array<string, string> */
    private array $creationTraces = [];

    /** @var array<string, string> */
    private array $cancelTraces = [];

    public function __construct(private readonly Driver $driver)
    {
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
     * @param list<array{
     *     args?: list<mixed>,
     *     class?: class-string,
     *     file?: string,
     *     function: string,
     *     line?: int,
     *     object?: object,
     *     type?: string
     * }> $trace Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return implode("\n", array_map(static function (array $e, int|string $i): string {
            $line = "#$i ";

            if (isset($e["file"], $e['line'])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, array_keys($trace)));
    }

    /**
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
     * @return string
     */
    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    /**
     * @return string
     */
    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * @return string
     */
    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    /**
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
     * @return string
     */
    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    /**
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
     * @return CallbackType
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->driver->getType($callbackId);
    }

    /**
     * @return bool
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->driver->isEnabled($callbackId);
    }

    /**
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
     * @return void
     */
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->driver->queue($closure, ...$args);
    }
}
