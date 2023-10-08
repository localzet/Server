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

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use Error;

/**
 * @internal
 */
abstract class DriverCallback
{
    /**
     * @var bool
     */
    public bool $invokable = false;

    /**
     * @var bool
     */
    public bool $enabled = true;

    /**
     * @var bool
     */
    public bool $referenced = true;

    /**
     * @param string $id
     * @param Closure $closure
     */
    public function __construct(
        public readonly string  $id,
        public readonly Closure $closure
    )
    {
    }

    /**
     * @param string $property
     */
    public function __get(string $property): never
    {
        throw new Error("Unknown property '$property'");
    }

    /**
     * @param string $property
     * @param mixed $value
     */
    public function __set(string $property, mixed $value): never
    {
        throw new Error("Unknown property '$property'");
    }
}
