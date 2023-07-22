<?php

declare(strict_types=1);

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
        throw new Error("Unknown property '{$property}'");
    }

    /**
     * @param string $property
     * @param mixed $value
     */
    public function __set(string $property, mixed $value): never
    {
        throw new Error("Unknown property '{$property}'");
    }
}
