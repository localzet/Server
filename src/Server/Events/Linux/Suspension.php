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
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Events\Linux;

use Throwable;

/**
 * Должен использоваться для запуска и приостановки цикла обработки событий вместо прямого взаимодействия с Fiber'ами.
 *
 * **Пример**
 *
 * ```php
 * $suspension = EventLoop::getSuspension();
 *
 * $promise->then(
 *     fn (mixed $value) => $suspension->resume($value),
 *     fn (Throwable $error) => $suspension->throw($error)
 * );
 *
 * $suspension->suspend();
 * ```
 *
 * @template T
 */
interface Suspension
{
    /**
     * @param T $value Значение, которое следует вернуть из вызова {@see suspend()}.
     */
    public function resume(mixed $value = null): void;

    /**
     * Возвращает значение, предоставленное {@see resume()}, или выбрасывает исключение, предоставленное {@see throw()}.
     *
     * @return T
     */
    public function suspend(): mixed;

    /**
     * Выбрасывает данное исключение из вызова {@see suspend()}.
     */
    public function throw(Throwable $throwable): void;
}
