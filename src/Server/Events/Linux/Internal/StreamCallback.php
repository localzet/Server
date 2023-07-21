<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

/** @internal */
abstract class StreamCallback extends DriverCallback
{
    /**
     * @param resource $stream
     */
    public function __construct(
        string $id,
        \Closure $closure,
        public readonly mixed $stream
    ) {
        parent::__construct($id, $closure);
    }
}
