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

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use ReflectionException;
use ReflectionFunction;

/** @internal */
final class ClosureHelper
{
    /**
     * @param Closure $closure
     * @return string
     */
    public static function getDescription(Closure $closure): string
    {
        try {
            // Создаем объект ReflectionFunction для замыкания.
            $reflection = new ReflectionFunction($closure);

            // Получаем имя замыкания.
            $description = $reflection->name;

            // Если у замыкания есть класс области видимости, добавляем его к описанию.
            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            // Если у замыкания есть имя файла и номер строки начала, добавляем их к описанию.
            if ($reflection->getFileName() && $reflection->getStartLine()) {
                $description .= " определено в " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (ReflectionException) {
            // В случае ошибки возвращаем неопределенное значение.
            return '???';
        }
    }
}
