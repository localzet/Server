<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2025 Localzet Group
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

/**
 * Класс ServerSentEvents
 * @package localzet\Server\Protocols\Http
 */
class ServerSentEvents implements Stringable
{
    /**
     * Конструктор ServerSentEvents.
     *
     * @param array $data Данные для создания объекта ServerSentEvents. Пример: ['event' => 'ping', 'data' => 'какие-то данные', 'id' => 1000, 'retry' => 5000]
     */
    public function __construct(
        /**
         * Данные.
         */
        protected array $data
    )
    {
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
        // Инициализируем буфер пустой строкой.
        $buffer = '';
        // Получаем данные из свойства data.
        $data = $this->data;
        // Если в данных есть пустой ключ, добавляем его значение в буфер.
        if (isset($data[''])) {
            $buffer = ": {$data['']}\n";
        }

        // Если в данных есть ключ 'event', добавляем его значение в буфер.
        if (isset($data['event'])) {
            $buffer .= "event: {$data['event']}\n";
        }

        // Если в данных есть ключ 'id', добавляем его значение в буфер.
        if (isset($data['id'])) {
            $buffer .= "id: {$data['id']}\n";
        }

        // Если в данных есть ключ 'retry', добавляем его значение в буфер.
        if (isset($data['retry'])) {
            $buffer .= "retry: {$data['retry']}\n";
        }

        // Если в данных есть ключ 'data', добавляем его значение в буфер, заменяя все переносы строк на "\ndata: ".
        if (isset($data['data'])) {
            $buffer .= 'data: ' . str_replace("\n", "\ndata: ", $data['data']) . "\n";
        }

        // Возвращаем буфер с дополнительным переносом строки на конце.
        return $buffer . "\n";
    }
}
