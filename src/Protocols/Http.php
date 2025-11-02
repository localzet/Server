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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;
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
use function ini_get;
use function is_object;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;

/**
 * Класс Http.
 * @package localzet\Server\Protocols
 */
class Http implements ProtocolInterface
{
    /**
     * Максимальная длина заголовка HTTP запроса до парсинга (16KB).
     */
    private const MAX_HEADER_SIZE = 16384;

    /**
     * Поддерживаемые HTTP методы.
     */
    private const SUPPORTED_METHODS = ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'];

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

    /** @inheritdoc */
    public static function input(string $buffer, TcpConnection|ConnectionInterface $connection): int
    {
        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            // Проверьте, не превышает ли длина пакета лимит заголовка.
            if (strlen($buffer) >= self::MAX_HEADER_SIZE) {
                $connection->close(format_http_response(413, 'Request Header Too Large'), true);
                return 0;
            }

            return 0;
        }

        $length = $crlfPos + 4;
        $header = substr($buffer, 0, $crlfPos);

        // Проверка поддерживаемых HTTP методов
        $methodValid = false;
        foreach (self::SUPPORTED_METHODS as $method) {
            if (str_starts_with($header, $method . ' ')) {
                $methodValid = true;
                break;
            }
        }

        if (!$methodValid) {
            $connection->close(format_http_response(400, 'Bad Request'), true);
            return 0;
        }

        // Парсинг Content-Length (игнорируем Transfer-Encoding chunked для простоты)
        if (preg_match('/\b(?:Transfer-Encoding\b.*)|(?:Content-Length:\s*(\d+)(?!.*\bTransfer-Encoding\b))/is', $header, $matches)) {
            if (!isset($matches[1])) {
                // Transfer-Encoding без chunked - не поддерживаем
                $connection->close(format_http_response(400, 'Bad Request'), true);
                return 0;
            }
            $contentLength = (int)$matches[1];
            if ($contentLength < 0) {
                $connection->close(format_http_response(400, 'Bad Request'), true);
                return 0;
            }
            $length += $contentLength;
        }

        if ($length > $connection->maxPackageSize) {
            $connection->close(format_http_response(413, 'Payload Too Large'), true);
            return 0;
        }

        return $length;
    }

    /** @inheritdoc */
    public static function encode(mixed $data, TcpConnection|ConnectionInterface $connection): string
    {
        if ($connection->request instanceof Request) {
            // Удаляем ссылки на запрос и соединение для предотвращения утечки памяти.
            $request = $connection->request;
            // Очищаем свойства запроса и соединения.
            $request->connection = $connection->request = null;
        }

        $data = is_object($data) ? $data : new Response(200, [], (string)$data);

        if ($connection->headers && method_exists($data, 'withHeaders')) {
            // Добавляем заголовки соединения в ответ.
            $data->withHeaders($connection->headers);
            // Очищаем заголовки после использования.
            $connection->headers = [];
        }

        if (!empty($data->file) && is_array($data->file)) {
            $file = $data->file['file'] ?? null;
            $offset = isset($data->file['offset']) ? (int)$data->file['offset'] : 0;
            $length = isset($data->file['length']) ? (int)$data->file['length'] : 0;

            if (!is_string($file) || !is_file($file)) {
                $connection->close(new Response(404, [], '404 File Not Found'));
                return '';
            }

            clearstatcache(true, $file);
            $fileSize = filesize($file);
            if ($fileSize === false) {
                $connection->close(new Response(500, [], '500 Internal Server Error'));
                return '';
            }

            $bodyLen = $length > 0 ? $length : ($fileSize - $offset);
            if ($bodyLen <= 0 || $offset < 0 || $offset >= $fileSize) {
                $connection->close(new Response(416, [], '416 Range Not Satisfiable'));
                return '';
            }

            $data->withHeaders([
                'Content-Length' => $bodyLen,
                'Accept-Ranges' => 'bytes',
            ]);
            if ($offset > 0 || $length > 0) {
                $offsetEnd = $offset + $bodyLen - 1;
                $data->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
            }

            // Для файлов меньше 2MB отправляем сразу
            if ($bodyLen < 2 * 1024 * 1024) {
                $fileContents = file_get_contents($file, false, null, $offset, $bodyLen);
                if ($fileContents === false) {
                    $connection->close(new Response(500, [], '500 Internal Server Error'));
                    return '';
                }
                $connection->send((string)$data . $fileContents, true);
                return '';
            }

            // Для больших файлов используем потоковую передачу
            $handler = fopen($file, 'rb');
            if ($handler === false) {
                $connection->close(new Response(403, [], '403 Forbidden'));
                return '';
            }

            $connection->send((string)$data, true);
            static::sendStream($connection, $handler, $offset, $length);
            return '';
        }

        return (string)$data;
    }

    /** @inheritdoc */
    public static function decode(string $buffer, TcpConnection|ConnectionInterface $connection): Request
    {
        static $requests = [];
        if (isset($requests[$buffer])) {
            $request = $requests[$buffer];
            $request->connection = $connection;
            $connection->request = $request;
            $request->destroy();
            return $request;
        }

        /** @var Request $request */
        $request = new static::$requestClass($buffer);
        if (!isset($buffer[TcpConnection::MAX_CACHE_STRING_LENGTH])) {
            $requests[$buffer] = $request;
            if (count($requests) > TcpConnection::MAX_CACHE_SIZE) {
                unset($requests[key($requests)]);
            }

            $request = clone $request;
        }

        $request->connection = $connection;
        $connection->request = $request;

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
