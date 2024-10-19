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

namespace localzet\Server\Events\Linux;

// @codeCoverageIgnoreStart
use Error;
use localzet\Server\Events\Linux\Driver\{EvDriver, EventDriver, StreamSelectDriver, TracingDriver, UvDriver};
use function class_exists;
use function getenv;
use function is_subclass_of;
use function sprintf;

/**
 * Финальный класс для создания драйвера цикла обработки событий.
 */
final class DriverFactory
{
    /**
     * Создает новый экземпляр цикла и выбирает наилучший доступный драйвер.
     *
     *
     * @throws Error Если недействительный класс был указан через LOCALZET_LOOP_DRIVER
     */
    public function create(): Driver
    {
        $driver = (function (): \localzet\Server\Events\Linux\Driver|\localzet\Server\Events\Linux\Driver\UvDriver|\localzet\Server\Events\Linux\Driver\EvDriver|\localzet\Server\Events\Linux\Driver\EventDriver|\localzet\Server\Events\Linux\Driver\StreamSelectDriver {
            if (($driver = $this->createDriverFromEnv()) instanceof \localzet\Server\Events\Linux\Driver) {
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

        if (getenv("LOCALZET_DRIVER_DEBUG_TRACE")) {
            return new TracingDriver($driver);
        }

        return $driver;
    }

    /**
     * Создает драйвер из переменной окружения.
     */
    private function createDriverFromEnv(): ?Driver
    {
        $driver = getenv("LOCALZET_DRIVER");

        if (!$driver) {
            return null;
        }

        if (!class_exists($driver)) {
            throw new Error(sprintf(
                "Драйвер '%s' не существует.",
                $driver
            ));
        }

        if (!is_subclass_of($driver, Driver::class)) {
            throw new Error(sprintf(
                "Драйвер '%s' не является подклассом '%s'.",
                $driver,
                Driver::class
            ));
        }

        return new $driver();
    }
}

// @codeCoverageIgnoreEnd
