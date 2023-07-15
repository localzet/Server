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
 * 
 * Класс Revolt представляет собой реализацию интерфейса EventInterface с использованием Revolt EventLoop.
 * Он предоставляет функциональность для управления обработчиками событий чтения, записи, таймеров и сигналов.
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
     *
     * Создает новый экземпляр класса Revolt и инициализирует драйвер EventLoop.
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
     * Запускает цикл обработки событий.
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * Останавливает цикл обработки событий.
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
     * Устанавливает отложенное событие выполнения функции.
     * @param float $delay Задержка перед выполнением в секундах.
     * @param callable $func Функция для выполнения.
     * @param array $args Аргументы функции (по умолчанию пустой массив).
     * @return int Идентификатор таймера.
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
     * Устанавливает повторяющееся событие выполнения функции.
     * @param float $interval Интервал между повторениями в секундах.
     * @param callable $func Функция для выполнения.
     * @param array $args Аргументы функции (по умолчанию пустой массив).
     * @return int Идентификатор таймера.
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
     * Устанавливает обработчик события чтения.
     * @param mixed $stream Поток для чтения.
     * @param callable $func Функция-обработчик.
     */
    public function onReadable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->readEvents);
        $this->readEvents[(int)$stream] = $this->driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * Удаляет обработчик события чтения.
     * @param mixed $stream Поток для чтения.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offReadable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->readEvents);
    }

    /**
     * Устанавливает обработчик события записи.
     * @param mixed $stream Поток для записи.
     * @param callable $func Функция-обработчик.
     */
    public function onWritable($stream, callable $func): void
    {
        $this->cancelAndUnset($stream, $this->writeEvents);
        $this->writeEvents[(int)$stream] = $this->driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * Удаляет обработчик события записи.
     * @param mixed $stream Поток для записи.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offWritable($stream): bool
    {
        return $this->cancelAndUnset($stream, $this->writeEvents);
    }

    /**
     * Устанавливает обработчик события сигнала.
     * @param int $signal Номер сигнала.
     * @param callable $func Функция-обработчик.
     */
    public function onSignal(int $signal, callable $func): void
    {
        $this->cancelAndUnset($signal, $this->eventSignal);
        $this->eventSignal[$signal] = $this->driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * Удаляет обработчик события сигнала.
     * @param int $signal Номер сигнала.
     * @return bool Возвращает true, если обработчик был успешно удален, иначе false.
     */
    public function offSignal(int $signal): bool
    {
        return $this->cancelAndUnset($signal, $this->eventSignal);
    }

    /**
     * Удаляет отложенное событие по идентификатору таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно удален, иначе false.
     */
    public function offDelay(int $timerId): bool
    {
        return $this->cancelAndUnset($timerId, $this->eventTimer);
    }

    /**
     * Удаляет повторяющееся событие по идентификатору таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно удален, иначе false.
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * Удаляет все таймеры.
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->eventTimer = [];
    }

    /**
     * Возвращает количество таймеров.
     * @return int Количество таймеров.
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * Устанавливает обработчик ошибок.
     * @param callable $errorHandler Функция-обработчик ошибок.
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * Возвращает текущий обработчик ошибок.
     * @return callable|null Обработчик ошибок или null, если обработчик не установлен.
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
