<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

/**
 * @internal
 */
abstract class DriverCallback
{
    public bool $invokable = false;

    public bool $enabled = true;

    public bool $referenced = true;

    public function __construct(
        public readonly string $id,
        public readonly \Closure $closure
    ) {
    }

    /**
     * @param string $property
     */
    public function __get(string $property): never
    {
        throw new \Error("Unknown property '{$property}'");
    }

    /**
     * @param string $property
     * @param mixed  $value
     */
    public function __set(string $property, mixed $value): never
    {
        throw new \Error("Unknown property '{$property}'");
    }
}
