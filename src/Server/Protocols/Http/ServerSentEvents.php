<?php

declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl AGPL-3.0 license
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace localzet\Server\Protocols\Http;

use Stringable;

/**
 * Класс ServerSentEvents
 * @package localzet\Server\Protocols\Http
 */
class ServerSentEvents implements Stringable
{
    /**
     * Данные.
     * @var array
     */
    protected array $data;

    /**
     * Конструктор ServerSentEvents.
     *
     * @param array $data Данные для создания объекта ServerSentEvents. Пример: ['event' => 'ping', 'data' => 'какие-то данные', 'id' => 1000, 'retry' => 5000]
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * __toString.
     *
     * Возвращает строковое представление объекта ServerSentEvents.
     *
     * @return string Строковое представление объекта ServerSentEvents.
     */
    public function __toString(): string
    {
        $buffer = '';
        $data = $this->data;
        if (isset($data[''])) {
            $buffer = ": {$data['']}\n";
        }
        if (isset($data['event'])) {
            $buffer .= "event: {$data['event']}\n";
        }
        if (isset($data['id'])) {
            $buffer .= "id: {$data['id']}\n";
        }
        if (isset($data['retry'])) {
            $buffer .= "retry: {$data['retry']}\n";
        }
        if (isset($data['data'])) {
            $buffer .= 'data: ' . str_replace("\n", "\ndata: ", $data['data']) . "\n";
        }
        return $buffer . "\n";
    }
}
