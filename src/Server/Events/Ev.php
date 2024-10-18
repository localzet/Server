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

use EvIo;
use EvSignal;
use EvTimer;
use Throwable;

/**
 * Класс Windows реализует интерфейс EventInterface и представляет select event loop.
 */
final class Ev implements EventInterface
{
    /**
     * Идентификатор таймера.
     *
     * @var int
     */
    private static int $timerId = 1;
    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, EvIo>
     */
    protected array $readEvents = [];
    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, EvIo>
     */
    protected array $writeEvents = [];
    /**
     * Массив всех обработчиков сигналов.
     *
     * @var array<int, EvSignal>
     */
    protected array $eventSignal = [];
    /**
     * Массив всех таймеров.
     *
     * @var array<int, EvTimer>
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
        $timerId = self::$timerId;
        $event = new EvTimer($delay, 0, function () use ($func, $args, $timerId): void {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, $args);
        });
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
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
        $event = new EvTimer($interval, $interval, fn() => $this->safeCall($func, $args));
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
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
            $this->eventTimer[$timerId]->stop();
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
        \Ev::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $event = new EvIo($stream, \Ev::READ, fn() => $this->safeCall($func, [$stream]));
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->stop();
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $event = new EvIo($stream, \Ev::WRITE, fn() => $this->safeCall($func, [$stream]));
        $this->writeEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->stop();
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        $event = new EvSignal($signal, fn() => $this->safeCall($func, [$signal]));
        $this->eventSignal[$signal] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        \Ev::run();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $event) {
            $event->stop();
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (isset($this->eventSignal[$signal])) {
            $this->eventSignal[$signal]->stop();
            unset($this->eventSignal[$signal]);
            return true;
        }
        return false;
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
