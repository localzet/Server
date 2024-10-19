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

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use ReflectionException;
use ReflectionFunction;

/** @internal */
final class ClosureHelper
{
    public static function getDescription(Closure $closure): string
    {
        try {
            // Создаем объект ReflectionFunction для замыкания.
            $reflectionFunction = new ReflectionFunction($closure);

            // Получаем имя замыкания.
            $description = $reflectionFunction->name;

            // Если у замыкания есть класс области видимости, добавляем его к описанию.
            if ($scopeClass = $reflectionFunction->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            // Если у замыкания есть имя файла и номер строки начала, добавляем их к описанию.
            if ($reflectionFunction->getFileName() && $reflectionFunction->getStartLine()) {
                $description .= " определено в " . $reflectionFunction->getFileName() . ':' . $reflectionFunction->getStartLine();
            }

            return $description;
        } catch (ReflectionException) {
            // В случае ошибки возвращаем неопределенное значение.
            return '???';
        }
    }
}
