<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
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

use Throwable;

/**
 * Класс предоставляет интерфейс для работы с событиями в сервере Localzet.
 * Он позволяет отложить выполнение колбэка, повторно выполнять колбэк, регистрировать обратные вызовы при чтении/записи потоков
 * и обрабатывать сигналы.
 */
interface EventInterface
{
    /**
     * Задержать выполнение колбэка на указанное время.
     * @param float $delay Задержка в секундах.
     * @param callable $func Колбэк, который нужно выполнить.
     * @param array $args Аргументы, передаваемые в колбэк.
     * @return int Идентификатор таймера.
     */
    public function delay(float $delay, callable $func, array $args = []): int;

    /**
     * Отменить таймер задержки.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно отменен, иначе false.
     */
    public function offDelay(int $timerId): bool;

    /**
     * Повторно выполнять колбэк через указанный интервал времени.
     * @param float $interval Интервал в секундах.
     * @param callable $func Колбэк, который нужно выполнить.
     * @param array $args Аргументы, передаваемые в колбэк.
     * @return int Идентификатор таймера.
     */
    public function repeat(float $interval, callable $func, array $args = []): int;

    /**
     * Отменить повторение таймера.
     * @param int $timerId Идентификатор таймера.
     * @return bool Возвращает true, если таймер был успешно отменен, иначе false.
     */
    public function offRepeat(int $timerId): bool;

    /**
     * Зарегистрировать колбэк для выполнения при возможности чтения или закрытия потока для чтения.
     * @param resource $stream Поток, для которого нужно зарегистрировать колбэк.
     * @param callable $func Колбэк, который нужно выполнить.
     * @return void
     */
    public function onReadable($stream, callable $func): void;

    /**
     * Отменить регистрацию колбэка для чтения потока.
     * @param resource $stream Поток, для которого нужно отменить регистрацию колбэка.
     * @return bool Возвращает true, если колбэк был успешно отменен, иначе false.
     */
    public function offReadable($stream): bool;

    /**
     * Зарегистрировать колбэк для выполнения при возможности записи или закрытия потока для записи.
     * @param resource $stream Поток, для которого нужно зарегистрировать колбэк.
     * @param callable $func Колбэк, который нужно выполнить.
     * @return void
     */
    public function onWritable($stream, callable $func): void;

    /**
     * Отменить регистрацию колбэка для записи потока.
     * @param resource $stream Поток, для которого нужно отменить регистрацию колбэка.
     * @return bool Возвращает true, если колбэк был успешно отменен, иначе false.
     */
    public function offWritable($stream): bool;

    /**
     * Зарегистрировать колбэк для выполнения при получении сигнала.
     * @param int $signal Номер сигнала.
     * @param callable $func Колбэк, который нужно выполнить.
     * @return void
     * @throws Throwable
     */
    public function onSignal(int $signal, callable $func): void;

    /**
     * Отменить регистрацию колбэка для сигнала.
     * @param int $signal Номер сигнала.
     * @return bool Возвращает true, если колбэк был успешно отменен, иначе false.
     */
    public function offSignal(int $signal): bool;

    /**
     * Запустить цикл обработки событий.
     *
     * Эту функцию можно вызывать только из {main}, то есть не внутри Fiber'а.
     *
     * Библиотеки должны использовать API {@link Suspension} вместо вызова этого метода.
     *
     * Этот метод не вернет управление до тех пор, пока цикл обработки событий не будет содержать каких-либо ожидающих, ссылочных обратных вызовов.
     *
     * @return void
     * @throws Throwable
     */
    public function run(): void;

    /**
     * Остановить цикл событий.
     * @return void
     */
    public function stop(): void;

    /**
     * Удалить все таймеры.
     * @return void
     */
    public function deleteAllTimer(): void;

    /**
     * Получить количество таймеров.
     * @return int Количество таймеров.
     */
    public function getTimerCount(): int;

    /**
     * Установить обработчик ошибок.
     * @param callable $errorHandler Обработчик ошибок.
     * @return void
     */
    public function setErrorHandler(callable $errorHandler): void;

//    /**
//     * Получить обработчик ошибок.
//     * @return callable|null Обработчик ошибок или null, если обработчик не установлен.
//     */
//    public function getErrorHandler(): ?callable;
}
