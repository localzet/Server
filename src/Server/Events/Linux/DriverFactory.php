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

// @codeCoverageIgnoreStart
use Error;
use localzet\Server\Events\Linux\Driver\{EvDriver, EventDriver, StreamSelectDriver, TracingDriver, UvDriver};
use function class_exists;
use function getenv;
use function is_subclass_of;
use function sprintf;

/**
 *
 */
final class DriverFactory
{
    /**
     * Creates a new loop instance and chooses the best available driver.
     *
     * @return Driver
     *
     * @throws Error If an invalid class has been specified via REVOLT_LOOP_DRIVER
     */
    public function create(): Driver
    {
        $driver = (function () {
            if ($driver = $this->createDriverFromEnv()) {
                return $driver;
            }

            if (UvDriver::isSupported()) {
                return new UvDriver();
            }

            if (EvDriver::isSupported()) {
                return new EvDriver();
            }

            if (EventDriver::isSupported()) {
                return new EventDriver();
            }

            return new StreamSelectDriver();
        })();

        if (getenv("REVOLT_DRIVER_DEBUG_TRACE")) {
            return new TracingDriver($driver);
        }

        return $driver;
    }

    /**
     * @return Driver|null
     */
    private function createDriverFromEnv(): ?Driver
    {
        $driver = getenv("REVOLT_DRIVER");

        if (!$driver) {
            return null;
        }

        if (!class_exists($driver)) {
            throw new Error(sprintf(
                "Driver '%s' does not exist.",
                $driver
            ));
        }

        if (!is_subclass_of($driver, Driver::class)) {
            throw new Error(sprintf(
                "Driver '%s' is not a subclass of '%s'.",
                $driver,
                Driver::class
            ));
        }

        return new $driver();
    }
}
// @codeCoverageIgnoreEnd