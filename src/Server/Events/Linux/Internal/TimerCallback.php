<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

use Closure;

/** @internal */
final class TimerCallback extends DriverCallback
{
    /**
     * @param string $id
     * @param float $interval
     * @param Closure $callback
     * @param float $expiration
     * @param bool $repeat
     */
    public function __construct(
        string                $id,
        public readonly float $interval,
        Closure               $callback,
        public float          $expiration,
        public readonly bool  $repeat = false
    )
    {
        parent::__construct($id, $callback);
    }
}
