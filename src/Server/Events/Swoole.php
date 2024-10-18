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

namespace localzet\Server\Events;

use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Throwable;

/**
 * Класс Windows реализует интерфейс EventInterface и представляет select event loop.
 */
final class Swoole implements EventInterface
{
    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, resource>
     */
    protected array $readEvents = [];
    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, resource>
     */
    protected array $writeEvents = [];
    /**
     * Массив всех таймеров.
     *
     * @var array<int, int>
     */
    protected array $eventTimer = [];
    /**
     * Обработчик ошибок.
     *
     * @var ?callable
     */
    protected $errorHandler = null;

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $t = (int)($delay * 1000);
        $t = max($t, 1);
        $timerId = Timer::after($t, function () use ($func, $args, &$timerId): void {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, $args);
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $e) {
            if ($this->errorHandler === null) {
                echo $e;
            } else {
                ($this->errorHandler)($e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $t = (int)($interval * 1000);
        $t = max($t, 1);
        $timerId = Timer::tick($t, function () use ($func, $args): void {
            $this->safeCall($func, $args);
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
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
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            Timer::clear($timerId);
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        Event::exit();
        posix_kill(posix_getpid(), SIGINT);
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, fn() => $this->safeCall($func, [$stream]), null, SWOOLE_EVENT_READ);
        } elseif (isset($this->writeEvents[$fd])) {
            Event::set($stream, fn() => $this->safeCall($func, [$stream]), null, SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE);
        } else {
            Event::set($stream, fn() => $this->safeCall($func, [$stream]), null, SWOOLE_EVENT_READ);
        }

        $this->readEvents[$fd] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd])) {
            return false;
        }
        unset($this->readEvents[$fd]);
        if (!isset($this->writeEvents[$fd])) {
            Event::del($stream);
            return true;
        }
        Event::set($stream, null, null, SWOOLE_EVENT_WRITE);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, null, fn() => $this->safeCall($func, [$stream]), SWOOLE_EVENT_WRITE);
        } elseif (isset($this->readEvents[$fd])) {
            Event::set($stream, null, fn() => $this->safeCall($func, [$stream]), SWOOLE_EVENT_WRITE | SWOOLE_EVENT_READ);
        } else {
            Event::set($stream, null, fn() => $this->safeCall($func, [$stream]), SWOOLE_EVENT_WRITE);
        }

        $this->writeEvents[$fd] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->writeEvents[$fd])) {
            return false;
        }
        unset($this->writeEvents[$fd]);
        if (!isset($this->readEvents[$fd])) {
            Event::del($stream);
            return true;
        }
        Event::set($stream, null, null, SWOOLE_EVENT_READ);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        Process::signal($signal, fn() => $this->safeCall($func, [$signal]));
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        // Avoid process exit due to no listening
        Timer::tick(100000000, static fn() => null);
        Event::wait();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $timerId) {
            Timer::clear($timerId);
        }
    }

    /**
     * @see https://wiki.swoole.com/#/process/process?id=signal
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        return Process::signal($signal, null);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }
}
