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
use Exception;
use localzet\Server\Events\Linux\Internal\{AbstractDriver,
    DriverCallback,
    SignalCallback,
    StreamReadableCallback,
    StreamWritableCallback,
    TimerCallback,
    TimerQueue};
use localzet\Server\Events\Linux\UnsupportedFeatureException;
use SplQueue;
use Throwable;
use function assert;
use function extension_loaded;
use function function_exists;
use function hrtime;
use function is_resource;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function str_replace;
use function stream_select;
use function stripos;
use function usleep;
use const PHP_INT_MAX;
use const SIG_DFL;

/**
 *
 */
final class StreamSelectDriver extends AbstractDriver
{
    /** @var array<int, resource> */
    private array $readStreams = [];

    /** @var array<int, array<string, StreamReadableCallback>> */
    private array $readCallbacks = [];

    /** @var array<int, resource> */
    private array $writeStreams = [];

    /** @var array<int, array<string, StreamWritableCallback>> */
    private array $writeCallbacks = [];

    private readonly TimerQueue $timerQueue;

    /** @var array<int, array<string, SignalCallback>> */
    private array $signalCallbacks = [];

    /** @var SplQueue<int> */
    private readonly SplQueue $signalQueue;

    private readonly bool $signalHandling;

    private readonly Closure $streamSelectErrorHandler;

    private bool $streamSelectIgnoreResult = false;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        $this->signalQueue = new SplQueue();
        $this->timerQueue = new TimerQueue();
        $this->signalHandling = extension_loaded("pcntl")
            && function_exists('pcntl_signal_dispatch')
            && function_exists('pcntl_signal');

        $this->streamSelectErrorHandler = function (int $errno, string $message): void {
            // Casing changed in PHP 8 from 'unable' to 'Unable'
            if (stripos($message, "stream_select(): unable to select [4]: ") === 0) { // EINTR
                $this->streamSelectIgnoreResult = true;

                return;
            }

            if (str_contains($message, 'FD_SETSIZE')) {
                $message = str_replace(["\r\n", "\n", "\r"], " ", $message);
                $pattern = '(stream_select\(\): You MUST recompile PHP with a larger value of FD_SETSIZE. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)\.)';

                if (preg_match($pattern, $message, $match)) {
                    $helpLink = 'https://revolt.run/extensions';

                    $message = 'You have reached the limits of stream_select(). It has a FD_SETSIZE of ' . $match[1]
                        . ', but you have file descriptors numbered at least as high as ' . $match[2] . '. '
                        . "You can install one of the extensions listed on $helpLink to support a higher number of "
                        . "concurrent file descriptors. If a large number of open file descriptors is unexpected, you "
                        . "might be leaking file descriptors that aren't closed correctly.";
                }
            }

            throw new Exception($message, $errno);
        };
    }

    /**
     *
     */
    public function __destruct()
    {
        foreach ($this->signalCallbacks as $signalCallbacks) {
            foreach ($signalCallbacks as $signalCallback) {
                $this->deactivate($signalCallback);
            }
        }
    }

    protected function deactivate(DriverCallback $callback): void
    {
        if ($callback instanceof StreamReadableCallback) {
            $streamId = (int)$callback->stream;
            unset($this->readCallbacks[$streamId][$callback->id]);
            if (empty($this->readCallbacks[$streamId])) {
                unset($this->readCallbacks[$streamId], $this->readStreams[$streamId]);
            }
        } elseif ($callback instanceof StreamWritableCallback) {
            $streamId = (int)$callback->stream;
            unset($this->writeCallbacks[$streamId][$callback->id]);
            if (empty($this->writeCallbacks[$streamId])) {
                unset($this->writeCallbacks[$streamId], $this->writeStreams[$streamId]);
            }
        } elseif ($callback instanceof TimerCallback) {
            $this->timerQueue->remove($callback);
        } elseif ($callback instanceof SignalCallback) {
            if (isset($this->signalCallbacks[$callback->signal])) {
                unset($this->signalCallbacks[$callback->signal][$callback->id]);

                if (empty($this->signalCallbacks[$callback->signal])) {
                    unset($this->signalCallbacks[$callback->signal]);
                    set_error_handler(static fn(): bool => true);
                    try {
                        pcntl_signal($callback->signal, SIG_DFL);
                    } finally {
                        restore_error_handler();
                    }
                }
            }
        } else {
            // @codeCoverageIgnoreStart
            throw new Error("Unknown callback type");
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @throws UnsupportedFeatureException If the pcntl extension is not available.
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        if (!$this->signalHandling) {
            throw new UnsupportedFeatureException("Signal handling requires the pcntl extension");
        }

        return parent::onSignal($signal, $closure);
    }

    public function getHandle(): mixed
    {
        return null;
    }

    /**
     * @throws Throwable
     */
    protected function dispatch(bool $blocking): void
    {
        if ($this->signalHandling) {
            pcntl_signal_dispatch();

            while (!$this->signalQueue->isEmpty()) {
                $signal = $this->signalQueue->dequeue();

                foreach ($this->signalCallbacks[$signal] as $callback) {
                    $this->enqueueCallback($callback);
                }

                $blocking = false;
            }
        }

        $this->selectStreams(
            $this->readStreams,
            $this->writeStreams,
            $blocking ? $this->getTimeout() : 0.0
        );

        $now = $this->now();

        while ($callback = $this->timerQueue->extract($now)) {
            $this->enqueueCallback($callback);
        }
    }

    /**
     * @param array<int, resource> $read
     * @param array<int, resource> $write
     * @throws Exception
     */
    private function selectStreams(array $read, array $write, float $timeout): void
    {
        if (!empty($read) || !empty($write)) { // Use stream_select() if there are any streams in the loop.
            if ($timeout >= 0) {
                $seconds = (int)$timeout;
                $microseconds = (int)(($timeout - $seconds) * 1_000_000);
            } else {
                $seconds = null;
                $microseconds = null;
            }

            // Failed connection attempts are indicated via except on Windows
            // @link https://github.com/reactphp/event-loop/blob/8bd064ce23c26c4decf186c2a5a818c9a8209eb0/src/StreamSelectLoop.php#L279-L287
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (!is_unix()) {
                $except = $write;
            }

            set_error_handler($this->streamSelectErrorHandler);

            try {
                /** @psalm-suppress InvalidArgument */
                $result = stream_select($read, $write, $except, $seconds, $microseconds);
            } finally {
                restore_error_handler();
            }

            if ($this->streamSelectIgnoreResult || $result === 0) {
                $this->streamSelectIgnoreResult = false;
                return;
            }

            if (!$result) {
                throw new Exception('Unknown error during stream_select');
            }

            foreach ($read as $stream) {
                $streamId = (int)$stream;
                if (!isset($this->readCallbacks[$streamId])) {
                    continue; // All read callbacks disabled.
                }

                foreach ($this->readCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            /** @var array<int, resource>|null $except */
            if ($except) {
                foreach ($except as $key => $socket) {
                    $write[$key] = $socket;
                }
            }

            foreach ($write as $stream) {
                $streamId = (int)$stream;
                if (!isset($this->writeCallbacks[$streamId])) {
                    continue; // All write callbacks disabled.
                }

                foreach ($this->writeCallbacks[$streamId] as $callback) {
                    $this->enqueueCallback($callback);
                }
            }

            return;
        }

        if ($timeout < 0) { // Only signal callbacks are enabled, so sleep indefinitely.
            /** @psalm-suppress ArgumentTypeCoercion */
            usleep(PHP_INT_MAX);
            return;
        }

        if ($timeout > 0) { // Sleep until next timer expires.
            /** @psalm-var positive-int $timeout */
            usleep((int)($timeout * 1_000_000));
        }
    }

    /**
     * @return float Seconds until next timer expires or -1 if there are no pending timers.
     */
    private function getTimeout(): float
    {
        $expiration = $this->timerQueue->peek();

        if ($expiration === null) {
            return -1;
        }

        $expiration -= $this->now();

        return $expiration > 0 ? $expiration : 0.0;
    }

    protected function now(): float
    {
        return (float)hrtime(true) / 1_000_000_000;
    }

    /**
     * @throws UnsupportedFeatureException
     */
    protected function activate(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            if ($callback instanceof StreamReadableCallback) {
                assert(is_resource($callback->stream));

                $streamId = (int)$callback->stream;
                $this->readCallbacks[$streamId][$callback->id] = $callback;
                $this->readStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof StreamWritableCallback) {
                assert(is_resource($callback->stream));

                $streamId = (int)$callback->stream;
                $this->writeCallbacks[$streamId][$callback->id] = $callback;
                $this->writeStreams[$streamId] = $callback->stream;
            } elseif ($callback instanceof TimerCallback) {
                $this->timerQueue->insert($callback);
            } elseif ($callback instanceof SignalCallback) {
                if (!isset($this->signalCallbacks[$callback->signal])) {
                    set_error_handler(static function (int $errno, string $errstr): bool {
                        throw new UnsupportedFeatureException(
                            sprintf("Failed to register signal handler; Errno: %d; %s", $errno, $errstr)
                        );
                    });

                    // Avoid bug in Psalm handling of first-class callables by assigning to a temp variable.
                    $handler = $this->handleSignal(...);

                    try {
                        pcntl_signal($callback->signal, $handler);
                    } finally {
                        restore_error_handler();
                    }
                }

                $this->signalCallbacks[$callback->signal][$callback->id] = $callback;
            } else {
                // @codeCoverageIgnoreStart
                throw new Error("Unknown callback type");
                // @codeCoverageIgnoreEnd
            }
        }
    }

    private function handleSignal(int $signal): void
    {
        // Queue signals, so we don't suspend inside pcntl_signal_dispatch, which disables signals while it runs
        $this->signalQueue->enqueue($signal);
    }
}
