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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
use localzet\Server\Protocols\Http\ServerSentEvents;
use Throwable;
use function clearstatcache;
use function count;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function in_array;
use function ini_get;
use function is_object;
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
     */
    protected static string $requestClass = Request::class;

    /**
     * Временный каталог для загрузки.
     */
    protected static string $uploadTmpDir = '';

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
     * Проверить целостность пакета.
     *
     * @throws Throwable
     */
    public static function input(string $buffer, TcpConnection $tcpConnection): int
    {
        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            // Проверьте, не превышает ли длина пакета лимит.
            if (strlen($buffer) >= 16384) {
                $tcpConnection->close(format_http_response(413), true);
            }

            return 0;
        }

        $length = $crlfPos + 4;
        $method = strstr($buffer, ' ', true);
        if (!in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $tcpConnection->close(format_http_response(400), true);
            return 0;
        }

        $header = substr($buffer, 0, $crlfPos);

        if ($pos = stripos($header, "\r\nContent-Length: ")) {
            $length += (int)substr($header, $pos + 18, 10);
            $hasContentLength = true;
        } elseif (preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length += (int)$match[1];
            $hasContentLength = true;
        } else {
            $hasContentLength = false;
            if (str_contains($header, "\r\nTransfer-Encoding:")) {
                $tcpConnection->close(format_http_response(400), true);
                return 0;
            }
        }

        if ($hasContentLength && $length > $tcpConnection->maxPackageSize) {
            $tcpConnection->close(format_http_response(413), true);
            return 0;
        }

        return $length;
    }

    /**
     * Декодирование Http.
     */
    public static function decode(string $buffer, TcpConnection $tcpConnection): Request
    {
        static $requests = [];
        if (isset($requests[$buffer])) {
            $request = $requests[$buffer];
            $request->connection = $tcpConnection;
            $tcpConnection->request = $request;
            $request->destroy();
            return $request;
        }

        /** @var Request $request */
        $request = new static::$requestClass($buffer);
        if (!isset($buffer[512])) {
            $requests[$buffer] = $request;
            if (count($requests) > 512) {
                unset($requests[key($requests)]);
            }

            $request = clone $request;
        }

        $request->connection = $tcpConnection;
        $tcpConnection->request = $request;

        foreach ($request->header() as $name => $value) {
            $_SERVER[strtoupper((string)$name)] = $value;
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
     * @param string|Response|ServerSentEvents $response
     * @throws Throwable
     */
    public static function encode(mixed $response, TcpConnection $tcpConnection): string
    {
        if ($tcpConnection->request instanceof Request) {
            // Удаляем ссылки на запрос и соединение для предотвращения утечки памяти.
            $request = $tcpConnection->request;
            // Очищаем свойства запроса и соединения.
            $request->connection = $tcpConnection->request = null;
        }

        $response = is_object($response) ? $response : new Response(200, [], (string)$response);

        if ($tcpConnection->headers && method_exists($response, 'withHeaders')) {
            // Добавляем заголовки соединения в ответ.
            $response->withHeaders($tcpConnection->headers);
            // Очищаем заголовки после использования.
            $tcpConnection->headers = [];
        }

        if (!empty($response->file)) {
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
                $tcpConnection->send($response . file_get_contents($file, false, null, $offset, $bodyLen), true);
                return '';
            }

            $handler = fopen($file, 'r');
            if (false === $handler) {
                $tcpConnection->close(new Response(403, [], '403 Forbidden'));
                return '';
            }

            $tcpConnection->send((string)$response, true);
            static::sendStream($tcpConnection, $handler, $offset, $length);
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
    protected static function sendStream(TcpConnection $tcpConnection, $handler, int $offset = 0, int $length = 0): void
    {
        // Устанавливаем флаги состояния буфера и потока.
        $tcpConnection->context->bufferFull = false;
        $tcpConnection->context->streamSending = true;
        // Если смещение не равно нулю, перемещаемся на это смещение в файле.
        if ($offset !== 0) {
            fseek($handler, $offset);
        }

        // Конечное смещение.
        $offsetEnd = $offset + $length;
        // Читаем содержимое файла с диска по частям и отправляем клиенту.
        $doWrite = function () use ($tcpConnection, $handler, $length, $offsetEnd): void {

            while ($tcpConnection->context->bufferFull === false) {

                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = ftell($handler);
                    $remainSize = $offsetEnd - $tell;
                    if ($remainSize <= 0) {
                        fclose($handler);
                        $tcpConnection->onBufferDrain = null;
                        return;
                    }

                    $size = min($remainSize, $size);
                }

                $buffer = fread($handler, $size);

                if ($buffer === '' || $buffer === false) {
                    fclose($handler);
                    $tcpConnection->onBufferDrain = null;
                    $tcpConnection->context->streamSending = false;
                    return;
                }

                $tcpConnection->send($buffer, true);
            }
        };

        $tcpConnection->onBufferFull = function ($connection): void {
            $connection->context->bufferFull = true;
        };

        $tcpConnection->onBufferDrain = function ($connection) use ($doWrite): void {
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
            } elseif ($uploadTmpDir = sys_get_temp_dir()) {
                static::$uploadTmpDir = $uploadTmpDir;
            }
        }

        return static::$uploadTmpDir;
    }
}
