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

use Event as LibEvent;
use EventBase;
use RuntimeException;
use Throwable;
use function class_exists;
use function count;

/**
 * Класс Windows реализует интерфейс EventInterface и представляет select event loop.
 */
final class Event implements EventInterface
{
    /**
     * Массив всех обработчиков событий чтения.
     *
     * @var array<int, LibEvent>
     */
    private array $readEvents = [];
    /**
     * Массив всех обработчиков событий записи.
     *
     * @var array<int, LibEvent>
     */
    private array $writeEvents = [];
    /**
     * Массив всех обработчиков сигналов.
     *
     * @var array<int, LibEvent>
     */
    private array $eventSignal = [];
    /**
     * Массив всех таймеров.
     *
     * @var array<int, LibEvent>
     */
    private array $eventTimer = [];
    /**
     * Идентификатор таймера.
     */
    private int $timerId = 0;
    /**
     * Обработчик ошибок.
     *
     * @var ?callable
     */
    private $errorHandler = null;
    private readonly EventBase $eventBase;
    private string $eventClassName = '';

    /**
     * Конструктор.
     */
    public function __construct()
    {
        if (class_exists('\\\\Event', false)) {
            $className = '\\\\Event';
        } else {
            $className = '\Event';
        }
        $this->eventClassName = $className;
        if (class_exists('\\\\EventBase', false)) {
            $className = '\\\\EventBase';
        } else {
            $className = '\EventBase';
        }
        $this->eventBase = new $className();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT, function () use ($func, $args, $timerId): void {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, $args);
        });
        if (!$event->addTimer($delay)) {
            throw new RuntimeException("Event::addTimer($delay) failed");
        }
        $this->eventTimer[$timerId] = $event;
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
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT | $className::PERSIST, fn() => $this->safeCall($func, $args));
        if (!$event->addTimer($interval)) {
            throw new RuntimeException("Event::addTimer($interval) failed");
        }
        $this->eventTimer[$timerId] = $event;
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
            $this->eventTimer[$timerId]->del();
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $className($this->eventBase, $stream, $className::READ | $className::PERSIST, fn() => $this->safeCall($func, [$stream]));
        if ($event->add()) {
            $this->readEvents[$fdKey] = $event;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->del();
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
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $className($this->eventBase, $stream, $className::WRITE | $className::PERSIST, fn() => $this->safeCall($func, [$stream]));
        if ($event->add()) {
            $this->writeEvents[$fdKey] = $event;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->del();
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
        $className = $this->eventClassName;
        $fdKey = $signal;
        $event = $className::signal($this->eventBase, $signal, fn() => $this->safeCall($func, [$signal]));
        if ($event->add()) {
            $this->eventSignal[$fdKey] = $event;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->eventBase->loop();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->eventBase->exit();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $event) {
            $event->del();
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->eventSignal[$fdKey]->del();
            unset($this->eventSignal[$fdKey]);
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
