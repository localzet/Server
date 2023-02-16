<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 * 
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *              
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *              
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Events;

use Revolt\EventLoop;
use Revolt\EventLoop\Driver;

use function count;
use function function_exists;
use function pcntl_signal;

/**
 * Revolt eventloop
 */
class Revolt implements EventInterface
{
    /**
     * @var Driver
     */
    protected Driver $driver;

    /**
     * All listeners for read event.
     * @var array
     */
    protected array $readEvents = [];

    /**
     * All listeners for write event.
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * Event listeners of signal.
     * @var array
     */
    protected array $eventSignal = [];

    /**
     * Event listeners of timer.
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected int $timerId = 1;

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->driver = EventLoop::getDriver();
    }

    /**
     * Get driver
     *
     * @return Driver
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->driver->run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        foreach ($this->eventSignal as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->driver->stop();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...$args);
        };
        $cbId = $this->driver->delay($delay, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args) {
            $func(...$args);
        };
        $cbId = $this->driver->repeat($interval, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func)
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
        }

        $this->readEvents[$fdKey] = $this->driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func)
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
        }
        $this->writeEvents[$fdKey] = $this->driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func)
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
        }
        $this->eventSignal[$fdKey] = $this->driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            $this->driver->cancel($this->eventTimer[$timerId]);
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler)
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->driver->getErrorHandler();
    }
}
