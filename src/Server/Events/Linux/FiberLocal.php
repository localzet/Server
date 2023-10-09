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

namespace localzet\Server\Events\Linux;

use Closure;
use Fiber;
use WeakMap;

/**
 * Локальное хранилище Fiber.
 *
 * Каждый экземпляр хранит данные отдельно для каждого Fiber'а. Примеры использования включают контекстные данные для ведения журнала.
 *
 * @template T
 */
final class FiberLocal
{
    /** @var Fiber|null Фиктивный Fiber для {main} */
    private static ?Fiber $mainFiber = null;

    /**
     * @var WeakMap|null
     */
    private static ?WeakMap $localStorage = null;

    /**
     * @param Closure():T $initializer
     */
    public function __construct(private readonly Closure $initializer)
    {
    }

    /**
     * Очистить локальное хранилище.
     *
     * @return void
     */
    public static function clear(): void
    {
        if (self::$localStorage === null) {
            return;
        }

        $fiber = Fiber::getCurrent() ?? self::$mainFiber;

        if ($fiber === null) {
            return;
        }

        unset(self::$localStorage[$fiber]);
    }

    /**
     * Установить значение в локальное хранилище.
     *
     * @param T $value
     */
    public function set(mixed $value): void
    {
        self::getFiberStorage()[$this] = [$value];
    }

    /**
     * Получить локальное хранилище для текущего Fiber'а.
     *
     * @return WeakMap
     */
    private static function getFiberStorage(): WeakMap
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $fiber = self::$mainFiber ??= new Fiber(static function (): void {
                // фиктивный Fiber для main, так как нам нужен некоторый объект для WeakMap
            });
        }

        $localStorage = self::$localStorage ??= new WeakMap();
        return $localStorage[$fiber] ??= new WeakMap();
    }

    /**
     * Удалить значение из локального хранилища.
     *
     * @return void
     */
    public function unset(): void
    {
        unset(self::getFiberStorage()[$this]);
    }

    /**
     * Получить значение из локального хранилища.
     *
     * @return T
     */
    public function get(): mixed
    {
        $fiberStorage = self::getFiberStorage();

        if (!isset($fiberStorage[$this])) {
            $fiberStorage[$this] = [($this->initializer)()];
        }

        return $fiberStorage[$this][0];
    }
}
