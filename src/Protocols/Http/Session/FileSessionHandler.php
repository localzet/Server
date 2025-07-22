<?php

declare(strict_types=1);

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

namespace localzet\Server\Protocols\Http\Session;

use DateTime;
use Exception;
use localzet\Server\Protocols\Http\Session;

use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function session_save_path;
use function strlen;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/**
 * Class FileSessionHandler
 * @package localzet\Server\Protocols\Http\Session
 */
class FileSessionHandler implements SessionHandlerInterface
{
    /**
     * Путь для сохранения сессий.
     */
    protected static string $sessionSavePath;

    /**
     * Префикс имени файла сессии.
     */
    protected static string $sessionFilePrefix = 'session_';

    /**
     * Конструктор FileSessionHandler.
     */
    public function __construct(array $config = [])
    {
        // Если указан параметр "save_path" в конфигурации, устанавливаем путь для сохранения сессий
        if (isset($config['save_path'])) {
            static::sessionSavePath($config['save_path']);
        }
    }

    /**
     * Получение или установка пути для сохранения сессий.
     *
     * @param string $path Путь для сохранения сессий.
     */
    public static function sessionSavePath(string $path): string
    {
        // Если путь указан
        if ($path) {
            // Если в конце пути отсутствует разделитель директорий, добавляем его
            if ($path[strlen($path) - 1] !== DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }

            // Устанавливаем путь для сохранения сессий
            static::$sessionSavePath = $path;
            // Если директория не существует, создаем ее с правами 0777
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        return $path;
    }

    /**
     * Инициализация.
     */
    public static function init(): void
    {
        // Получаем путь для сохранения сессий
        $savePath = @session_save_path();
        // Если путь не указан или начинается с "tcp://", используем временную директорию
        if (!$savePath || str_starts_with($savePath, 'tcp://')) {
            $savePath = sys_get_temp_dir();
        }

        // Устанавливаем путь для сохранения сессий
        static::sessionSavePath($savePath);
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $savePath, string $name): bool
    {
        // Всегда возвращаем true, так как открытие сессии происходит при инициализации класса
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $sessionId): string
    {
        // Получаем путь к файлу сессии
        $sessionFile = static::sessionFile($sessionId);
        // Очищаем кэш информации о файле
        clearstatcache();
        // Если файл существует
        if (is_file($sessionFile)) {
            // Проверяем, не истекло ли время жизни сессии
            if ((new DateTime())->getTimestamp() - filemtime($sessionFile) > Session::$lifetime) {
                // Если истекло, удаляем файл и возвращаем пустую строку
                unlink($sessionFile);
                return '';
            }

            // Читаем данные из файла и возвращаем их (или пустую строку, если чтение не удалось)
            $data = file_get_contents($sessionFile);
            return $data ?: '';
        }

        // Если файл не существует, возвращаем пустую строку
        return '';
    }

    /**
     * Получение пути к файлу сессии.
     *
     * @param string $sessionId Идентификатор сессии.
     */
    protected static function sessionFile(string $sessionId): string
    {
        return static::$sessionSavePath . static::$sessionFilePrefix . $sessionId;
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        // Создаем временный файл для записи сессии
        $tempFile = static::$sessionSavePath . uniqid(bin2hex(random_bytes(8)), true);
        // Записываем данные сессии во временный файл
        if (!file_put_contents($tempFile, $sessionData)) {
            return false;
        }

        // Переименовываем временный файл в финальное имя файла сессии
        return rename($tempFile, static::sessionFile($sessionId));
    }

    /**
     * Обновление времени последнего изменения сессии.
     *
     * @see https://www.php.net/manual/ru/class.sessionupdatetimestamphandlerinterface.php
     * @see https://www.php.net/manual/ru/function.touch.php
     *
     * @param string $sessionId Идентификатор сессии.
     * @param string $data Данные сессии.
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        // Получаем путь к файлу сессии
        $sessionFile = static::sessionFile($sessionId);
        // Если файл не существует, возвращаем false
        if (!file_exists($sessionFile)) {
            return false;
        }

        // Устанавливаем время последнего изменения файла в текущее время
        $setModifyTime = touch($sessionFile);
        // Очищаем кэш информации о файле
        clearstatcache();
        return $setModifyTime;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        // Всегда возвращаем true, так как закрытие сессии происходит при уничтожении объекта
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $sessionId): bool
    {
        // Получаем путь к файлу сессии
        $sessionFile = static::sessionFile($sessionId);
        // Если файл существует, удаляем его
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): bool
    {
        // Получаем текущее время
        $timeNow = (new DateTime())->getTimestamp();
        // Проходимся по всем файлам сессий в папке сохранения сессий
        foreach (glob(static::$sessionSavePath . static::$sessionFilePrefix . '*') as $file) {
            // Если файл является обычным файлом и время последнего изменения файла превышает время жизни сессии,
            // удаляем файл
            if (is_file($file) && $timeNow - filemtime($file) > $maxLifetime) {
                unlink($file);
            }
        }

        return true;
    }
}

FileSessionHandler::init();
