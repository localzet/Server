<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Events;

use Closure;
use Error;
use localzet\Server\Events\Linux\{CallbackType,
    Driver,
    DriverFactory,
    Internal\AbstractDriver,
    Internal\DriverCallback,
    InvalidCallbackError,
    Suspension,
    UnsupportedFeatureException};
use function count;
use function function_exists;
use function gc_collect_cycles;
use function hrtime;
use function pcntl_signal;

/**
 * Linux eventloop
 *
 * Класс Linux представляет собой реализацию интерфейса EventInterface.
 * Он предоставляет функциональность для управления обработчиками событий чтения, записи, таймеров и сигналов.
 */
final class Linux implements EventInterface
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
     * Создает новый экземпляр класса Linux и инициализирует драйвер EventLoop.
     */
    public function __construct()
    {
        $this->driver = $this->getDriver();
    }

    /**
     * Получить драйвер цикла событий, который находится в области видимости.
     *
     * @return Driver
     */
    public function getDriver(): Driver
    {
        // Если драйвер не установлен, создать новый драйвер
        if (!isset($this->driver)) {
            $this->setDriver((new DriverFactory())->create());
        }

        // Вернуть драйвер
        return $this->driver;
    }

    /**
     * Установить драйвер для использования в качестве цикла событий.
     */
    public function setDriver(Driver $driver): void
    {
        // Если драйвер установлен и работает, выбросить исключение
        if (isset($this->driver) && $this->driver->isRunning()) {
            throw new Error("Невозможно заменить драйвер цикла событий во время его работы");
        }

        try {
            // Установить временный драйвер, который выбрасывает исключения при активации обратного вызова или отправке
            $this->driver = new class () extends AbstractDriver {
                protected function activate(array $callbacks): void
                {
                    throw new Error("Невозможно активировать обратный вызов во время сборки мусора.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new Error("Невозможно отправить во время сборки мусора.");
                }

                protected function deactivate(DriverCallback $callback): void
                {
                    // ничего не делать
                }

                public function getHandle(): null
                {
                    return null;
                }

                protected function now(): float
                {
                    return (float)hrtime(true) / 1_000_000_000;
                }
            };

            // Выполнить сборку мусора
            gc_collect_cycles();
        } finally {
            // Установить переданный драйвер
            $this->driver = $driver;
        }
    }

    /**
     * Возвращает объект, используемый для приостановки и возобновления выполнения текущего волокна или {main}.
     *
     * Вызовы из одного и того же волокна вернут один и тот же объект приостановки.
     *
     * @return Suspension
     */
    public function getSuspension(): Suspension
    {
        return $this->getDriver()->getSuspension();
    }

    /**
     * Получить драйвер.
     * @return Driver Драйвер.
     */
    public function driver(): Driver
    {
        return $this->getDriver();
    }

    /**
     * Запустить цикл обработки событий.
     *
     * Эту функцию можно вызывать только из {main}, то есть не внутри Fiber'а.
     *
     * Библиотеки должны использовать API {@link Suspension} вместо вызова этого метода.
     *
     * Этот метод не вернет управление до тех пор, пока цикл обработки событий не будет содержать каких-либо ожидающих, ссылочных обратных вызовов.
     */
    public function run(): void
    {
        $this->getDriver()->run();
    }

    /**
     * Останавливает цикл обработки событий.
     */
    public function stop(): void
    {
        foreach ($this->eventSignal as $cbId) {
            $this->getDriver()->cancel($cbId);
        }
        $this->getDriver()->stop();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * Отменить обратный вызов.
     *
     * Это отключит цикл обработки событий от всех ресурсов, которые связаны с обратным вызовом. После этой операции
     * обратный вызов становится постоянно недействительным. Вызов этой функции НЕ ДОЛЖЕН завершиться неудачей,
     * даже если передан недействительный идентификатор.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     */
    public function cancel(string $callbackId): void
    {
        $this->getDriver()->cancel($callbackId);
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
        $cbId = $this->getDriver()->delay($delay, $closure);
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
        $cbId = $this->getDriver()->repeat($interval, $closure);
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
        $this->readEvents[(int)$stream] = $this->getDriver()->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * Отменить регистрацию и удалить обработчик события.
     * @param mixed $key Ключ обработчика.
     * @param array $events
     * @return bool Возвращает true, если обработчик был успешно отменен и удален, иначе false.
     */
    protected function cancelAndUnset(mixed $key, array &$events): bool
    {
        $fdKey = (int)$key;
        if (isset($events[$fdKey])) {
            $this->getDriver()->cancel($events[$fdKey]);
            unset($events[$fdKey]);
            return true;
        }
        return false;
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
        $this->writeEvents[(int)$stream] = $this->getDriver()->onWritable($stream, function () use ($stream, $func) {
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
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public function onSignal(int $signal, callable $func): void
    {
        $this->cancelAndUnset($signal, $this->eventSignal);
        $this->eventSignal[$signal] = $this->getDriver()->onSignal($signal, function () use ($signal, $func) {
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
     * Удаляет повторяющееся событие по идентификатору таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно удален, иначе false.
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
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

    /*********************************************NEW********************************************************/

    /**
     * Удаляет все таймеры.
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $cbId) {
            $this->getDriver()->cancel($cbId);
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
        $this->getDriver()->setErrorHandler($errorHandler);
    }

    /**
     * Возвращает текущий обработчик ошибок.
     * @return callable|null Обработчик ошибок или null, если обработчик не установлен.
     */
    public function getErrorHandler(): ?callable
    {
        return $this->getDriver()->getErrorHandler();
    }

    /**
     * Поставить в очередь микрозадачу.
     *
     * Поставленный в очередь обратный вызов ДОЛЖЕН быть выполнен сразу, как только цикл событий получит управление. Порядок постановки в очередь ДОЛЖЕН быть
     * сохранен при выполнении обратных вызовов. Рекурсивное планирование может привести к бесконечным циклам, используйте с осторожностью.
     *
     * НЕ создает обратный вызов события, поэтому НЕ МОЖЕТ быть помечен как отключенный или непривязанный.
     * Используйте {@see EventLoop::defer()} если вам нужны эти функции.
     *
     * @param Closure $closure (...):void $closure Обратный вызов для постановки в очередь.
     * @param mixed ...$args Аргументы обратного вызова.
     */
    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->getDriver()->queue($closure, ...$args);
    }

    /**
     * Отложить выполнение обратного вызова.
     *
     * Отложенный обратный вызов ДОЛЖЕН быть выполнен до любого другого типа обратного вызова в тике. Порядок включения ДОЛЖЕН быть
     * сохранен при выполнении обратных вызовов.
     *
     * Созданный обратный вызов ДОЛЖЕН немедленно быть помечен как включенный, но только быть активирован (т.е. обратный вызов может быть вызван)
     * прямо перед следующим тиком. Отложенные обратные вызовы НЕ ДОЛЖНЫ вызываться в тике, в котором они были включены.
     *
     * @param Closure(string):void $closure Обратный вызов для отложения. `$callbackId` будет
     *     аннулирован перед вызовом обратного вызова.
     *
     * @return string Уникальный идентификатор, который можно использовать для отмены, включения или отключения обратного вызова.
     */
    public function defer(Closure $closure): string
    {
        return $this->getDriver()->defer($closure);
    }

    /**
     * Включить обратный вызов для активации, начиная со следующего тика.
     *
     * Обратные вызовы ДОЛЖНЫ немедленно быть помечены как включенные, но только быть активированы (т.е. обратные вызовы могут быть вызваны) прямо
     * перед следующим тиком. Обратные вызовы НЕ ДОЛЖНЫ вызываться в тике, в котором они были включены.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return string Идентификатор обратного вызова.
     *
     * @throws InvalidCallbackError Если идентификатор обратного вызова недействителен.
     */
    public function enable(string $callbackId): string
    {
        return $this->getDriver()->enable($callbackId);
    }

    /**
     * Немедленно отключить обратный вызов.
     *
     * Обратный вызов ДОЛЖЕН быть отключен немедленно, например, если отложенный обратный вызов отключает другой отложенный обратный вызов,
     * второй отложенный обратный вызов не выполняется в этом тике.
     *
     * Отключение обратного вызова НЕ ДОЛЖНО аннулировать обратный вызов. Вызов этой функции НЕ ДОЛЖЕН завершиться неудачей, даже если передан
     * недействительный идентификатор обратного вызова.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return string Идентификатор обратного вызова.
     */
    public function disable(string $callbackId): string
    {
        return $this->getDriver()->disable($callbackId);
    }

    /**
     * Сделать ссылку на обратный вызов.
     *
     * Это будет поддерживать цикл обработки событий в активном состоянии, пока событие все еще контролируется. Обратные вызовы имеют это состояние по
     * умолчанию.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return string Идентификатор обратного вызова.
     *
     * @throws InvalidCallbackError Если идентификатор обратного вызова недействителен.
     */
    public function reference(string $callbackId): string
    {
        return $this->getDriver()->reference($callbackId);
    }

    /**
     * Удалить ссылку на обратный вызов.
     *
     * Цикл обработки событий должен выйти из метода run, когда только непривязанные обратные вызовы все еще контролируются. Обратные вызовы
     * все привязаны по умолчанию.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return string Идентификатор обратного вызова.
     */
    public function unreference(string $callbackId): string
    {
        return $this->getDriver()->unreference($callbackId);
    }

    /**
     * Возвращает все зарегистрированные идентификаторы обратных вызовов, которые не были отменены.
     *
     * @return string[] Идентификаторы обратных вызовов.
     */
    public function getIdentifiers(): array
    {
        return $this->getDriver()->getIdentifiers();
    }

    /**
     * Возвращает тип обратного вызова, определенный данным идентификатором обратного вызова.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return CallbackType Тип обратного вызова.
     */
    public function getType(string $callbackId): CallbackType
    {
        return $this->getDriver()->getType($callbackId);
    }

    /**
     * Возвращает, включен ли в настоящее время обратный вызов, определенный данным идентификатором обратного вызова.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return bool {@code true}, если обратный вызов в настоящее время включен, в противном случае {@code false}.
     */
    public function isEnabled(string $callbackId): bool
    {
        return $this->getDriver()->isEnabled($callbackId);
    }

    /**
     * Возвращает, имеет ли в настоящее время обратный вызов, определенный данным идентификатором обратного вызова, ссылку.
     *
     * @param string $callbackId Идентификатор обратного вызова.
     *
     * @return bool {@code true}, если обратный вызов в настоящее время имеет ссылку, в противном случае {@code false}.
     */
    public function isReferenced(string $callbackId): bool
    {
        return $this->getDriver()->isReferenced($callbackId);
    }
}
