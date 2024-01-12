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

namespace localzet\Server\Protocols\Http\Session;

/**
 * Интерфейс SessionHandlerInterface
 */
interface SessionHandlerInterface
{
    /**
     * Закрывает сессию.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.close.php
     *
     * @return bool <p>
     * Возвращает результат выполнения (чаще всего TRUE в случае успеха, FALSE в случае ошибки).
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function close(): bool;

    /**
     * Уничтожает сессию.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.destroy.php
     *
     * @param string $sessionId Идентификатор уничтожаемой сессии.
     *
     * @return bool <p>
     * Возвращает результат выполнения (чаще всего TRUE в случае успеха, FALSE в случае ошибки).
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function destroy(string $sessionId): bool;

    /**
     * Очищает старые сессии.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.gc.php
     *
     * @param int $maxLifetime <p>
     * Время жизни в секундах. Сессии, не обновлявшиеся в течение
     * последних maxlifetime секунд, будут удалены.
     * </p>
     *
     * @return bool <p>
     * Возвращает результат выполнения (чаще всего TRUE в случае успеха, FALSE в случае ошибки).
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function gc(int $maxLifetime): bool;

    /**
     * Инициализирует сессию.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.open.php
     *
     * @param string $savePath Путь для хранения/извлечения сессии.
     * @param string $name Имя сессии.
     *
     * @return bool <p>
     * Возвращает результат выполнения (чаще всего TRUE в случае успеха, FALSE в случае ошибки).
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function open(string $savePath, string $name): bool;


    /**
     * Читает данные сессии.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.read.php
     *
     * @param string $sessionId Идентификатор сессии для чтения данных.
     *
     * @return string <p>
     * Возвращает закодированную строку с прочитанными данными.
     * Если данные отсутствуют, должна вернуться пустая строка.
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function read(string $sessionId): string;

    /**
     * Записывает данные сессии.
     *
     * @link http://php.net/manual/ru/sessionhandlerinterface.write.php
     *
     * @param string $sessionId Идентификатор сессии.
     * @param string $sessionData <p>
     * Закодированные данные сессии. Данные представляют собой
     * результат внутренней сериализации суперглобальной переменной $SESSION
     * в виде сериализованной строки.
     * Обратите внимание, что сессии используют альтернативный метод сериализации.
     * </p>
     *
     * @return bool <p>
     * Возвращает результат выполнения (чаще всего TRUE в случае успеха, FALSE в случае ошибки).
     * Обратите внимание, что эта значение возвращается внутренней частью PHP для обработки.
     * </p>
     *
     * @since 5.4.0
     */
    public function write(string $sessionId, string $sessionData): bool;

    /**
     * Обновляет метку времени модификации сессии.
     *
     * @see https://www.php.net/manual/ru/class.sessionupdatetimestamphandlerinterface.php
     *
     * @param string $sessionId Идентификатор сессии.
     * @param string $data Данные сессии.
     *
     * @return bool
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool;
}
