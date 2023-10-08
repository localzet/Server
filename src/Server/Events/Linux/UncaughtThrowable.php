<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

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