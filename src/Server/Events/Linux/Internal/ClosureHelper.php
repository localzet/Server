<?php

declare(strict_types=1);

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use ReflectionException;
use ReflectionFunction;

/** @internal */
final class ClosureHelper
{
    /**
     * @param Closure $closure
     * @return string
     */
    public static function getDescription(Closure $closure): string
    {
        try {
            $reflection = new ReflectionFunction($closure);

            $description = $reflection->name;

            if ($scopeClass = $reflection->getClosureScopeClass()) {
                $description = $scopeClass->name . '::' . $description;
            }

            if ($reflection->getFileName() && $reflection->getStartLine()) {
                $description .= " defined in " . $reflection->getFileName() . ':' . $reflection->getStartLine();
            }

            return $description;
        } catch (ReflectionException) {
            return '???';
        }
    }
}
