<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux;

// @codeCoverageIgnoreStart
use localzet\Server\Events\Linux\Driver\EvDriver;
use localzet\Server\Events\Linux\Driver\EventDriver;
use localzet\Server\Events\Linux\Driver\StreamSelectDriver;
use localzet\Server\Events\Linux\Driver\TracingDriver;
use localzet\Server\Events\Linux\Driver\UvDriver;

final class DriverFactory
{
    /**
     * Creates a new loop instance and chooses the best available driver.
     *
     * @return Driver
     *
     * @throws \Error If an invalid class has been specified via REVOLT_LOOP_DRIVER
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

        if (\getenv("REVOLT_DRIVER_DEBUG_TRACE")) {
            return new TracingDriver($driver);
        }

        return $driver;
    }

    /**
     * @return Driver|null
     */
    private function createDriverFromEnv(): ?Driver
    {
        $driver = \getenv("REVOLT_DRIVER");

        if (!$driver) {
            return null;
        }

        if (!\class_exists($driver)) {
            throw new \Error(\sprintf(
                "Driver '%s' does not exist.",
                $driver
            ));
        }

        if (!\is_subclass_of($driver, Driver::class)) {
            throw new \Error(\sprintf(
                "Driver '%s' is not a subclass of '%s'.",
                $driver,
                Driver::class
            ));
        }

        return new $driver();
    }
}
// @codeCoverageIgnoreEnd