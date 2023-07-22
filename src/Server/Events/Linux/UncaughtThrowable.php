<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux;

use Closure;
use Error;
use localzet\Server\Events\Linux\Internal\ClosureHelper;
use Throwable;
use function get_class;
use function sprintf;
use function str_replace;

/**
 *
 */
final class UncaughtThrowable extends Error
{
    /**
     * @param string $message
     * @param Closure $closure
     * @param Throwable $previous
     */
    private function __construct(string $message, Closure $closure, Throwable $previous)
    {
        parent::__construct(sprintf(
            $message,
            str_replace("\0", '@', get_class($previous)), // replace NUL-byte in anonymous class name
            ClosureHelper::getDescription($closure),
            $previous->getMessage() !== '' ? ': ' . $previous->getMessage() : ''
        ), 0, $previous);
    }

    /**
     * @param Closure $closure
     * @param Throwable $previous
     * @return self
     */
    public static function throwingCallback(Closure $closure, Throwable $previous): self
    {
        return new self(
            "Uncaught %s thrown in event loop callback %s; use localzet\Server\Events\Linux::setErrorHandler() to gracefully handle such exceptions%s",
            $closure,
            $previous
        );
    }

    /**
     * @param Closure $closure
     * @param Throwable $previous
     * @return self
     */
    public static function throwingErrorHandler(Closure $closure, Throwable $previous): self
    {
        return new self("Uncaught %s thrown in event loop error handler %s%s", $closure, $previous);
    }
}