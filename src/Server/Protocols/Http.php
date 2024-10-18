<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <ivan@zorin.space>
 * @copyright   Copyright (c) 2016-2024 Zorin Projects
 * @license     GNU Affero General Public License, version 3
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as
 *              published by the Free Software Foundation, either version 3 of the
 *              License, or (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols;

use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\{Request, Response};
use Throwable;
use function clearstatcache;
use function count;
use function explode;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function in_array;
use function ini_get;
use function is_object;
use function key;
use function preg_match;
use function strlen;
use function strpos;
use function strstr;
use function substr;
use function sys_get_temp_dir;

/**
 * Класс Http.
 * @package localzet\Server\Protocols
 */
class Http
{
    /**
     * Имя класса Request.
     *
     * @var string
     */
    protected static string $requestClass = Request::class;

    /**
     * Временный каталог для загрузки.
     *
     * @var string
     */
    protected static string $uploadTmpDir = '';

    /**
     * Кэш.
     *
     * @var bool.
     */
    protected static bool $enableCache = true;

    /**
     * Получить или установить имя класса запроса.
     *
     * @param string|null $className
     */
    public static function requestClass(string $className = null): string
    {
        if ($className !== null) {
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    /**
     * Включить или отключить кэш.
     */
    public static function enableCache(bool $value): void
    {
        static::$enableCache = $value;
    }

    /**
     * Проверить целостность пакета.
     *
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        static $input = [];
        if (!isset($buffer[512]) && isset($input[$buffer])) {
            return $input[$buffer];
        }
        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            // Проверьте, не превышает ли длина пакета лимит.
            if (strlen($buffer) >= 16384) {
                $connection->close(format_http_response(413), true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        $firstLine = explode(" ", strstr($buffer, "\r\n", true), 3);

        if (!in_array($firstLine[0], ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close(format_http_response(400), true);
            return 0;
        }

        $header = substr($buffer, 0, $crlfPos);

        if (!str_contains($header, "\r\nHost: ") && $firstLine[2] === "HTTP/1.1") {
            $connection->close(format_http_response(400), true);
            return 0;
        }

        if ($pos = stripos($header, "\r\nContent-Length: ")) {
            $length += (int)substr($header, $pos + 18, 10);
            $hasContentLength = true;
        } else if (preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length += (int)$match[1];
            $hasContentLength = true;
        } else {
            $hasContentLength = false;
            if (str_contains($header, "\r\nTransfer-Encoding:")) {
                $connection->close(format_http_response(400), true);
                return 0;
            }
        }

        if ($hasContentLength && $length > $connection->maxPackageSize) {
            $connection->close(format_http_response(413), true);
            return 0;
        }

        if (!isset($buffer[512])) {
            $input[$buffer] = $length;
            if (count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * Декодирование Http.
     */
    public static function decode(string $buffer, TcpConnection $connection): Request
    {
        static $requests = [];
        $cacheable = static::$enableCache && !isset($buffer[512]);
        if (true === $cacheable && isset($requests[$buffer])) {
            $request = clone $requests[$buffer];
            $request->connection = $connection;
            $connection->request = $request;
            $request->properties = [];
            return $request;
        }

        /** @var Request $request */
        $request = new static::$requestClass($buffer);
        $request->connection = $connection;
        $connection->request = $request;
        if (true === $cacheable) {
            $requests[$buffer] = $request;
            if (count($requests) > 512) {
                unset($requests[key($requests)]);
            }
        }

        foreach ($request->header() as $name => $value) {
            $_SERVER[strtoupper((string) $name)] = $value;
        }

        $_GET = $request->get();
        $_POST = $request->post();
        $_COOKIE = $request->cookie();

        $_REQUEST = $_GET + $_POST + $_COOKIE;
        $_SESSION = $request->session();

        return $request;
    }

    /**
     * Кодирование Http.
     *
     * @param string|Response $response
     * @throws Throwable
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        if (isset($connection->request)) {
            // Удаляем ссылки на запрос и соединение для предотвращения утечки памяти.
            $request = $connection->request;
            // Очищаем свойства запроса и соединения.
            $request->session = $request->connection = $connection->request = null;
        }

        $response = is_object($response) ? $response : new Response(200, [], (string)$response);

        if ($connection->headers && method_exists($response, 'withHeaders')) {
            // Добавляем заголовки соединения в ответ.
            $response->withHeaders($connection->headers);
            // Очищаем заголовки после использования.
            $connection->headers = [];
        }

        if (isset($response->file)) {
            $file = $response->file['file'];
            $offset = $response->file['offset'];
            $length = $response->file['length'];
            clearstatcache();
            $fileSize = (int)filesize($file);
            $bodyLen = $length > 0 ? $length : $fileSize - $offset;
            $response->withHeaders([
                'Content-Length' => $bodyLen,
                'Accept-Ranges' => 'bytes',
            ]);
            if ($offset || $length) {
                $offsetEnd = $offset + $bodyLen - 1;
                $response->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
            }
            if ($bodyLen < 2 * 1024 * 1024) {
                $connection->send($response . file_get_contents($file, false, null, $offset, $bodyLen), true);
                return '';
            }
            $handler = fopen($file, 'r');
            if (false === $handler) {
                $connection->close(new Response(403, [], '403 Forbidden'));
                return '';
            }
            $connection->send((string)$response, true);
            static::sendStream($connection, $handler, $offset, $length);
            return '';
        }

        return (string)$response;
    }

    /**
     * Отправить остаток потока клиенту.
     *
     * @param resource $handler
     * @throws Throwable
     */
    protected static function sendStream(TcpConnection $connection, $handler, int $offset = 0, int $length = 0): void
    {
        // Устанавливаем флаги состояния буфера и потока.
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;
        // Если смещение не равно нулю, перемещаемся на это смещение в файле.
        if ($offset !== 0) {
            fseek($handler, $offset);
        }
        // Конечное смещение.
        $offsetEnd = $offset + $length;
        // Читаем содержимое файла с диска по частям и отправляем клиенту.
        $doWrite = function () use ($connection, $handler, $length, $offsetEnd): void {

            while ($connection->context->bufferFull === false) {

                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = ftell($handler);
                    $remainSize = $offsetEnd - $tell;
                    if ($remainSize <= 0) {
                        fclose($handler);
                        $connection->onBufferDrain = null;
                        return;
                    }
                    $size = min($remainSize, $size);
                }

                $buffer = fread($handler, $size);

                if ($buffer === '' || $buffer === false) {
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    $connection->context->streamSending = false;
                    return;
                }
                $connection->send($buffer, true);
            }
        };

        $connection->onBufferFull = function ($connection): void {
            $connection->context->bufferFull = true;
        };

        $connection->onBufferDrain = function ($connection) use ($doWrite): void {
            $connection->context->bufferFull = false;
            $doWrite();
        };
        $doWrite();
    }

    /**
     * Установить или получить uploadTmpDir.
     */
    public static function uploadTmpDir(string|null $dir = null): string
    {
        if (null !== $dir) {
            static::$uploadTmpDir = $dir;
        }
        if (static::$uploadTmpDir === '') {
            if ($uploadTmpDir = ini_get('upload_tmp_dir')) {
                static::$uploadTmpDir = $uploadTmpDir;
            } else if ($uploadTmpDir = sys_get_temp_dir()) {
                static::$uploadTmpDir = $uploadTmpDir;
            }
        }
        return static::$uploadTmpDir;
    }
}
