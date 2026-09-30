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

namespace localzet\Server\Protocols\Http\Session;

use localzet\Server\Protocols\Http\Session;

/**
 * Файловое session storage.
 *
 * Путь хранится статически ради совместимости со старым Localzet API
 * FileSessionHandler::sessionSavePath(). Запись выполняется через временный файл
 * и atomic rename, поэтому reader не увидит частично записанную сессию.
 */
class FileSessionHandler implements SessionHandlerInterface
{
    protected static string $sessionSavePath = '';
    protected static string $sessionFilePrefix = 'session_';

    /** @param array|string $config Строка пути либо ['save_path' => '/path']. */
    public function __construct(array|string $config = [])
    {
        if (static::$sessionSavePath === '') {
            static::init();
        }
        $path = is_string($config) ? $config : (string)($config['save_path'] ?? '');
        if ($path !== '') {
            static::sessionSavePath($path);
        }
    }

    public static function init(): void
    {
        $savePath = @session_save_path();
        if (!$savePath || str_starts_with($savePath, 'tcp://')) {
            $savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'localzet-sessions';
        }
        static::sessionSavePath($savePath);
    }

    /** Возвращает или меняет директорию хранения сессий. */
    public static function sessionSavePath(string $path = ''): string
    {
        if ($path === '') {
            if (static::$sessionSavePath === '') {
                static::init();
            }
            return static::$sessionSavePath;
        }

        $path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create session directory {$path}");
        }
        static::$sessionSavePath = $path;
        return static::$sessionSavePath;
    }

    public function open(string $savePath, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string|false
    {
        $file = static::sessionFile($sessionId);
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return false;
        }

        $mtime = @filemtime($file);
        if ($mtime !== false && time() - $mtime > Session::$lifetime) {
            @unlink($file);
            return false;
        }

        $data = @file_get_contents($file);
        return $data === false || $data === '' ? false : $data;
    }

    public function write(string $sessionId, string $sessionData): bool
    {
        $tmp = static::$sessionSavePath . '.tmp-' . bin2hex(random_bytes(12));
        if (@file_put_contents($tmp, $sessionData, LOCK_EX) === false) {
            return false;
        }
        if (@rename($tmp, static::sessionFile($sessionId))) {
            return true;
        }
        @unlink($tmp);
        return false;
    }

    public function updateTimestamp(string $sessionId, string $data = ''): bool
    {
        $file = static::sessionFile($sessionId);
        if (!is_file($file)) {
            return false;
        }
        $result = @touch($file);
        clearstatcache(true, $file);
        return $result;
    }

    public function destroy(string $sessionId): bool
    {
        $file = static::sessionFile($sessionId);
        return !is_file($file) || @unlink($file);
    }

    public function gc(int $maxLifetime): bool
    {
        $now = time();
        foreach (glob(static::$sessionSavePath . static::$sessionFilePrefix . '*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime > $maxLifetime) {
                @unlink($file);
            }
        }
        return true;
    }

    protected static function sessionFile(string $sessionId): string
    {
        // Session ID валидируется Request, но storage также не позволяет path traversal.
        if (!preg_match('/^[A-Za-z0-9,-]{16,256}$/D', $sessionId)) {
            throw new \InvalidArgumentException('Invalid session id.');
        }
        if (static::$sessionSavePath === '') {
            static::init();
        }
        return static::$sessionSavePath . static::$sessionFilePrefix . $sessionId;
    }
}

FileSessionHandler::init();
