<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Localzet Group
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

namespace localzet\Server\Protocols\Http;

use Stringable;
use function dechex;
use function strlen;

/**
 * Класс Chunk
 * @package localzet\Server\Protocols\Http
 */
class Chunk implements Stringable
{
    /**
     * Буфер чанка.
     *
     * @var string
     */
    protected string $buffer;

    /**
     * Конструктор Chunk.
     *
     * @param string $buffer Буфер, передаваемый в чанк.
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * __toString
     *
     * Возвращает строковое представление чанка.
     *
     * @return string Строковое представление чанка.
     */
    public function __toString(): string
    {
        return dechex(strlen($this->buffer)) . "\r\n$this->buffer\r\n";
    }
}
