<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

use Closure;

/** @internal */
final class SignalCallback extends DriverCallback
{
    /**
     * @param string $id
     * @param Closure $closure
     * @param int $signal
     */
    public function __construct(
        string              $id,
        Closure             $closure,
        public readonly int $signal
    )
    {
        parent::__construct($id, $closure);
    }
}
