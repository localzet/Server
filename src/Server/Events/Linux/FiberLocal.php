<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux;

use Closure;
use Fiber;
use WeakMap;

/**
 * Fiber local storage.
 *
 * Each instance stores data separately for each fiber. Usage examples include contextual logging data.
 *
 * @template T
 */
final class FiberLocal
{
    /** @var Fiber|null Dummy fiber for {main} */
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
     * @param T $value
     */
    public function set(mixed $value): void
    {
        self::getFiberStorage()[$this] = [$value];
    }

    /**
     * @return WeakMap
     */
    private static function getFiberStorage(): WeakMap
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $fiber = self::$mainFiber ??= new Fiber(static function (): void {
                // dummy fiber for main, as we need some object for the WeakMap
            });
        }

        $localStorage = self::$localStorage ??= new WeakMap();
        return $localStorage[$fiber] ??= new WeakMap();
    }

    /**
     * @return void
     */
    public function unset(): void
    {
        unset(self::getFiberStorage()[$this]);
    }

    /**
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