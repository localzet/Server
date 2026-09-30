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

namespace localzet\Server\Protocols\Http;

use Stringable;

/** Одно Server-Sent Event сообщение. */
final class ServerSentEvents implements Stringable
{
    public function __construct(
        public string  $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int    $retry = null,
    )
    {
    }

    public function __toString(): string
    {
        $out = '';
        if ($this->event !== null) $out .= 'event: ' . $this->sanitize($this->event) . "\n";
        if ($this->id !== null) $out .= 'id: ' . $this->sanitize($this->id) . "\n";
        if ($this->retry !== null) $out .= 'retry: ' . max(0, $this->retry) . "\n";
        foreach (preg_split('/\R/', $this->data) ?: [''] as $line) {
            $out .= 'data: ' . $line . "\n";
        }
        return $out . "\n";
    }

    protected function sanitize(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
