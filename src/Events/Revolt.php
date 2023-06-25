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

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use function count;
use function function_exists;
use function pcntl_signal;

/**
 * Revolt eventloop
 */
class Revolt implements EventInterface
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
     */
    public function __construct()
    {
        $this->driver = EventLoop::getDriver();
    }

    /**
     * Получить драйвер.
     * @return Driver Драйвер.
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        foreach ($this->eventSignal as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->driver->stop();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...$args);
        };
        $cbId = $this->driver->delay($delay, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args) {
            $func(...$args);
        };
        $cbId = $this->driver->repeat($interval, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->readEvents);
        $this->readEvents[(int)$stream] = $this->driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->readEvents);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->writeEvents);
        $this->writeEvents[(int)$stream] = $this->driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->writeEvents);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        $this->cancelAndUnset($signal, $this->eventSignal);
        $this->eventSignal[$signal] = $this->driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        return $this->cancelAndUnset($signal, $this->eventSignal);
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        return $this->cancelAndUnset($timerId, $this->eventTimer);
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
        foreach ($this->eventTimer as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->eventTimer = [];
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
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->driver->getErrorHandler();
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
            $this->driver->cancel($events[$fdKey]);
            unset($events[$fdKey]);
            return true;
        }
        return false;
    }
}
