<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2026 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Gateway;

use localzet\Server\Proxy\UpstreamPool;

/**
 * Простое правило маршрутизации HTTP gateway.
 *
 * В 6.4 сознательно поддерживаются только host и path-prefix. Более сложный
 * DSL, service discovery и middleware providers отложены за границу 7.0.
 */
final class HttpRoute
{
    public function __construct(
        public readonly UpstreamPool $pool,
        public readonly string       $host = '*',
        public readonly string       $pathPrefix = '/',
        public readonly ?string      $rewritePrefix = null,
    )
    {
    }

    public function matches(string $host, string $path): bool
    {
        $hostMatches = $this->host === '*'
            || strcasecmp($this->hostWithoutPort($host), $this->hostWithoutPort($this->host)) === 0;

        return $hostMatches && str_starts_with($path, $this->pathPrefix);
    }

    public function rewriteTarget(string $target): string
    {
        if ($this->rewritePrefix === null || !str_starts_with($target, $this->pathPrefix)) {
            return $target;
        }

        $suffix = substr($target, strlen($this->pathPrefix));
        return rtrim($this->rewritePrefix, '/') . '/' . ltrim($suffix, '/');
    }

    private function hostWithoutPort(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strrpos($host, ':');
        return $colon === false ? $host : substr($host, 0, $colon);
    }
}
