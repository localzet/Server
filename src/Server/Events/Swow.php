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

use RuntimeException;
use Swow\Coroutine;
use Swow\Signal;
use Swow\SignalException;
use Throwable;
use function Swow\Sync\waitAll;

class Swow implements EventInterface
{
    /**
     * All listeners for read timer
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * All listeners for read event.
     * @var array<Coroutine>
     */
    protected array $readEvents = [];

    /**
     * All listeners for write event.
     * @var array<Coroutine>
     */
    protected array $writeEvents = [];

    /**
     * All listeners for signal.
     * @var array<Coroutine>
     */
    protected array $signalListener = [];

    /**
     * @var ?callable
     */
    protected $errorHandler = null;

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     * @throws Throwable
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $t = (int)($delay * 1000);
        $t = max($t, 1);
        $that = $this;
        $coroutine = Coroutine::run(function () use ($t, $func, $args, $that): void {
            msleep($t);
            unset($this->eventTimer[Coroutine::getCurrent()->getId()]);
            try {
                $func(...$args);
            } catch (Throwable $e) {
                $that->error($e);
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     * @throws Throwable
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $t = (int)($interval * 1000);
        $t = max($t, 1);
        $that = $this;
        $coroutine = Coroutine::run(static function () use ($t, $func, $args, $that): void {
            // @phpstan-ignore-next-line While loop condition is always true.
            while (true) {
                msleep($t);
                try {
                    $func(...$args);
                } catch (Throwable $e) {
                    $that->error($e);
                }
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            try {
                (Coroutine::getAll()[$timerId])->kill();
                return true;
            } finally {
                unset($this->eventTimer[$timerId]);
            }
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $timerId) {
            $this->offDelay($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (isset($this->readEvents[$fd])) {
            $this->offReadable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->readEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    if (!is_resource($stream)) {
                        $this->offReadable($stream);
                        break;
                    }
                    $rEvent = stream_poll_one($stream, STREAM_POLLIN | STREAM_POLLHUP);
                    if (!isset($this->readEvents[$fd]) || $this->readEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLIN) {
                        $this->offReadable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offReadable($stream);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        // 在当前协程执行 $coroutine->kill() 会导致不可预知问题，所以没有使用$coroutine->kill()
        $fd = (int)$stream;
        if (isset($this->readEvents[$fd])) {
            unset($this->readEvents[$fd]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (isset($this->writeEvents[$fd])) {
            $this->offWritable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->writeEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    $rEvent = stream_poll_one($stream, STREAM_POLLOUT | STREAM_POLLHUP);
                    if (!isset($this->writeEvents[$fd]) || $this->writeEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLOUT) {
                        $this->offWritable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offWritable($stream);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (isset($this->writeEvents[$fd])) {
            unset($this->writeEvents[$fd]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        Coroutine::run(function () use ($signal, $func): void {
            $this->signalListener[$signal] = Coroutine::getCurrent();
            while (1) {
                try {
                    Signal::wait($signal);
                    if (!isset($this->signalListener[$signal]) ||
                        $this->signalListener[$signal] !== Coroutine::getCurrent()) {
                        break;
                    }
                    $func($signal);
                } catch (SignalException) {
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalListener[$signal])) {
            return false;
        }
        unset($this->signalListener[$signal]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        waitAll();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop(): void
    {
        Coroutine::killAll();
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->errorHandler;
    }

    /**
     * @param Throwable $e
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $e): void
    {
        if (!$this->errorHandler) {
            throw new $e;
        }
        ($this->errorHandler)($e);
    }
}