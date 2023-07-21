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

namespace localzet\Server\Protocols;

use localzet\Server;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Ja3;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
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
use function is_array;
use function is_object;
use function key;
use function preg_match;
use function strlen;
use function strpos;
use function strstr;
use function substr;
use function sys_get_temp_dir;

/**
 * Class Http.
 * @package localzet\Server\Protocols
 */
class Http
{
    /**
     * Request class name.
     *
     * @var string
     */
    protected static string $requestClass = Request::class;

    /**
     * Upload tmp dir.
     *
     * @var string
     */
    protected static string $uploadTmpDir = '';

    /**
     * Cache.
     *
     * @var bool.
     */
    protected static bool $enableCache = true;

    /**
     * Get or set the request class name.
     *
     * @param string|null $className
     * @return string
     */
    public static function requestClass(string $className = null): string
    {
        if ($className) {
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    /**
     * Enable or disable Cache.
     *
     * @param bool $value
     */
    public static function enableCache(bool $value): void
    {
        static::$enableCache = $value;
    }

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
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
            // Judge whether the package length exceeds the limit.
            if (strlen($buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        $firstLine = explode(" ", strstr($buffer, "\r\n", true), 3);

        if (!in_array($firstLine[0], ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", true);
            return 0;
        }

        $header = substr($buffer, 0, $crlfPos);
        $hostHeaderPosition = stripos($header, "\r\nHost: ");

        if (false === $hostHeaderPosition && $firstLine[2] === "HTTP/1.1") {
            $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", true);
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
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", true);
                return 0;
            }
        }

        if ($hasContentLength && $length > $connection->maxPackageSize) {
            $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
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
     * Http decode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return Request
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
        $request = new static::$requestClass($buffer);
        $request->connection = $connection;
        $connection->request = $request;
        if (true === $cacheable) {
            $requests[$buffer] = $request;
            if (count($requests) > 512) {
                unset($requests[key($requests)]);
            }
        }

        // Experimental :)
        try {
            $request->ja3 = Ja3::get($buffer);
        } catch (Throwable) {
        }

        return $request;
    }

    /**
     * Http encode.
     *
     * @param string|Response $response
     * @param TcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        if (isset($connection->request)) {
            $request = $connection->request;
            $request->session = $request->connection = $connection->request = null;
        }
        if (!is_object($response)) {
            $extHeader = '';
            if ($connection->headers) {
                foreach ($connection->headers as $name => $value) {
                    if (is_array($value)) {
                        foreach ($value as $item) {
                            $extHeader .= "$name: $item\r\n";
                        }
                    } else {
                        $extHeader .= "$name: $value\r\n";
                    }
                }
                $connection->headers = [];
            }
            $response = (string)$response;
            $bodyLen = strlen($response);
            return "HTTP/1.1 200 OK\r\nServer: Localzet Server " . Server::getVersion() . "\r\n{$extHeader}Connection: keep-alive\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $bodyLen\r\n\r\n$response";
        }

        if ($connection->headers) {
            $response->withHeaders($connection->headers);
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
     * Send remainder of a stream to client.
     *
     * @param TcpConnection $connection
     * @param resource $handler
     * @param int $offset
     * @param int $length
     * @throws Throwable
     */
    protected static function sendStream(TcpConnection $connection, $handler, int $offset = 0, int $length = 0): void
    {
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;
        if ($offset !== 0) {
            fseek($handler, $offset);
        }
        $offsetEnd = $offset + $length;
        // Read file content from disk piece by piece and send to client.
        $doWrite = function () use ($connection, $handler, $length, $offsetEnd) {
            // Send buffer not full.
            while ($connection->context->bufferFull === false) {
                // Read from disk.
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
                // Read eof.
                if ($buffer === '' || $buffer === false) {
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    $connection->context->streamSending = false;
                    return;
                }
                $connection->send($buffer, true);
            }
        };
        // Send buffer full.
        $connection->onBufferFull = function ($connection) {
            $connection->context->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function ($connection) use ($doWrite) {
            $connection->context->bufferFull = false;
            $doWrite();
        };
        $doWrite();
    }

    /**
     * Set or get uploadTmpDir.
     *
     * @param string|null $dir
     * @return string
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
