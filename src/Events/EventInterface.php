<?php

/**
 * @package     Triangle Server (WebCore)
 * @link        https://github.com/localzet/WebCore
 * @link        https://github.com/Triangle-org/Server
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Core\Events;

use Throwable;

interface EventInterface
{
    /**
     * Delay the execution of a callback.
     * @param float $delay
     * @param callable $func
     * @param array $args
     * @return int
     */
    public function delay(float $delay, callable $func, array $args = []): int;

    /**
     * Delete a delay timer.
     * @param int $timerId
     * @return bool
     */
    public function offDelay(int $timerId): bool;

    /**
     * Repeatedly execute a callback.
     * @param float $interval
     * @param callable $func
     * @param array $args
     * @return int
     */
    public function repeat(float $interval, callable $func, array $args = []): int;

    /**
     * Delete a repeat timer.
     * @param int $timerId
     * @return bool
     */
    public function offRepeat(int $timerId): bool;

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     * @param resource $stream
     * @param callable $func
     * @return void
     */
    public function onReadable($stream, callable $func);

    /**
     * Cancel a callback of stream readable.
     * @param resource $stream
     * @return bool
     */
    public function offReadable($stream): bool;

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     * @param resource $stream
     * @param callable $func
     * @return void
     */
    public function onWritable($stream, callable $func);

    /**
     * Cancel a callback of stream writable.
     * @param resource $stream
     * @return bool
     */
    public function offWritable($stream): bool;

    /**
     * Execute a callback when a signal is received.
     * @param int $signal
     * @param callable $func
     * @return void
     * @throws Throwable
     */
    public function onSignal(int $signal, callable $func);

    /**
     * Cancel a callback of signal.
     * @param int $signal
     * @return bool
     */
    public function offSignal(int $signal): bool;

    /**
     * Delete all timer.
     * @return void
     */
    public function deleteAllTimer();

    /**
     * Run the event loop.
     * @return void
     * @throws Throwable
     */
    public function run();

    /**
     * Stop event loop.
     * @return void
     */
    public function stop();

    /**
     * Get Timer count.
     * @return int
     */
    public function getTimerCount(): int;

    /**
     * Set error handler
     * @param callable $errorHandler
     * @return void
     */
    public function setErrorHandler(callable $errorHandler);

    /**
     * Get error handler
     * @return ?callable(Throwable)
     */
    public function getErrorHandler(): ?callable;
}
