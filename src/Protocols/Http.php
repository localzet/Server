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

namespace localzet\Server\Protocols;

use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http\Request;
use localzet\Server\Protocols\Http\Response;
use localzet\Server\Protocols\Http\Chunk;

/**
 * HTTP/1.0 + HTTP/1.1 protocol codec.
 *
 * Основной принцип framing: до передачи запроса приложению транспорт должен
 * однозначно определить границы сообщения. Неоднозначные CL/TE комбинации и
 * синтаксически неверные заголовки закрываются до decode().
 */
class Http implements ProtocolInterface
{
    protected static string $requestClass = Request::class;
    protected static string $uploadTmpDir = '';
    protected static int $maxHeaderLength = 16 * 1024;
    protected static int $maxHeaderCount = 100;

    protected const HTTP_400 = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
    protected const HTTP_417 = "HTTP/1.1 417 Expectation Failed\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
    protected const HTTP_413 = "HTTP/1.1 413 Payload Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
    protected const HTTP_431 = "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";

    public static function requestClass(?string $className = null): string
    {
        if ($className !== null) {
            if (!is_a($className, Request::class, true)) {
                throw new \InvalidArgumentException('HTTP request class must extend ' . Request::class);
            }
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    public static function uploadTmpDir(?string $dir = null): string
    {
        if ($dir !== null) {
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new \InvalidArgumentException('Upload tmp dir must exist and be writable.');
            }
            static::$uploadTmpDir = rtrim($dir, '/\\');
        }
        return static::$uploadTmpDir ?: sys_get_temp_dir();
    }

    public static function maxHeaderLength(?int $bytes = null): int
    {
        if ($bytes !== null) {
            if ($bytes < 1024) {
                throw new \InvalidArgumentException('HTTP max header length must be >= 1024 bytes.');
            }
            static::$maxHeaderLength = $bytes;
        }
        return static::$maxHeaderLength;
    }

    public static function maxHeaderCount(?int $count = null): int
    {
        if ($count !== null) {
            if ($count < 1) {
                throw new \InvalidArgumentException('HTTP max header count must be >= 1.');
            }
            static::$maxHeaderCount = $count;
        }
        return static::$maxHeaderCount;
    }

    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('HTTP requires TcpConnection.');
        }

        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($buffer) >= static::$maxHeaderLength) {
                static::reject($connection, static::HTTP_431);
            }
            return 0;
        }

        if ($headerEnd >= static::$maxHeaderLength) {
            static::reject($connection, static::HTTP_431);
            return 0;
        }

        $headerLength = $headerEnd + 4;
        $rawHead = substr($buffer, 0, $headerEnd);
        $firstLineEnd = strpos($rawHead, "\r\n");
        // HTTP/1.0 may contain only request-line + the terminating empty
        // header section. No synthetic header line is required.
        $requestLine = $firstLineEnd === false ? $rawHead : substr($rawHead, 0, $firstLineEnd);
        // HTTP method — token, а не закрытый список известных verbs. Это важно
        // для WebDAV, RPC/gateway extensions и пользовательских HTTP methods.
        if (!preg_match(
            '/^(?<method>[!#$%&\x27*+.^_`|~0-9A-Za-z-]+) ([^\x00-\x20\x7f]+) HTTP\/1\.(?<minor>[01])$/D',
            $requestLine,
            $matches
        )) {
            static::reject($connection, static::HTTP_400);
            return 0;
        }

        $headers = static::parseFramingHeaders(
            $firstLineEnd === false ? '' : substr($rawHead, $firstLineEnd + 2)
        );
        if ($headers === null) {
            static::reject($connection, static::HTTP_400);
            return 0;
        }

        // HTTP/1.1 требует ровно один Host; duplicate Host запрещён и в 1.0.
        $host = $headers['host'] ?? [];
        if (count($host) > 1 || ((int)$matches['minor'] === 1 && count($host) !== 1)) {
            static::reject($connection, static::HTTP_400);
            return 0;
        }
        if ($host && !static::validHost($host[0])) {
            static::reject($connection, static::HTTP_400);
            return 0;
        }

        $transferEncoding = $headers['transfer-encoding'] ?? [];
        $contentLength = $headers['content-length'] ?? [];

        $expect = $headers['expect'] ?? [];
        if ($expect) {
            // Expect semantics were introduced for HTTP/1.1. Не пытаемся делать
            // ambiguous downgrade behavior для HTTP/1.0 peers.
            if ((int)$matches['minor'] !== 1
                || count($expect) !== 1
                || strtolower(trim($expect[0])) !== '100-continue') {
                static::reject($connection, static::HTTP_417);
                return 0;
            }
        }

        // RFC 9112: request с TE и CL одновременно считается подозрительным и отклоняется.
        if ($transferEncoding) {
            if ($contentLength || count($transferEncoding) !== 1) {
                static::reject($connection, static::HTTP_400);
                return 0;
            }
            $codings = array_map('trim', explode(',', strtolower($transferEncoding[0])));
            if ($codings !== ['chunked']) {
                static::reject($connection, static::HTTP_400);
                return 0;
            }
            if ($expect) {
                static::sendContinueOnce($connection);
            }
            return static::inputChunked($buffer, $connection, $headerLength);
        }

        if ($contentLength) {
            if (count($contentLength) !== 1 || !ctype_digit($contentLength[0])) {
                static::reject($connection, static::HTTP_400);
                return 0;
            }
            $bodyLength = (int)$contentLength[0];
            if ($bodyLength > $connection->maxPackageSize - $headerLength) {
                static::reject($connection, static::HTTP_413);
                return 0;
            }
            $total = $headerLength + $bodyLength;
            if ($expect && $bodyLength > 0 && strlen($buffer) < $total) {
                static::sendContinueOnce($connection);
            }
            return $total <= $connection->maxPackageSize ? $total : 0;
        }

        return $headerLength;
    }

    public static function decode(string $buffer, ConnectionInterface $connection): mixed
    {
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('HTTP requires TcpConnection.');
        }

        unset($connection->context->httpContinueSent);
        $trailers = [];
        if (($connection->context->httpChunked ?? false) === true) {
            unset($connection->context->httpChunked);
            [$buffer, $trailers] = static::decodeChunked($buffer);
        }

        $class = static::$requestClass;
        /** @var Request $request */
        $request = new $class($buffer);
        $request->connection = $connection;
        if ($trailers) {
            $request->setChunkTrailers($trailers);
        }

        // Keep-alive belongs to the HTTP transport lifecycle, not to every
        // application callback. HTTP/1.1 persists by default, HTTP/1.0 closes
        // unless the peer explicitly negotiated keep-alive.
        $connectionTokens = array_filter(array_map(
            'trim',
            explode(',', strtolower((string)$request->header('connection', '')))
        ));
        $version = $request->protocolVersion();
        $connection->context->httpRequestVersion = $version;
        $connection->context->httpShouldCloseAfterResponse = $version === '1.1'
            ? in_array('close', $connectionTokens, true)
            : !in_array('keep-alive', $connectionTokens, true);

        return $request;
    }

    public static function encode(mixed $data, ConnectionInterface $connection): string
    {
        if (!$connection instanceof TcpConnection) {
            throw new \InvalidArgumentException('HTTP requires TcpConnection.');
        }

        // Chunk objects sent through the protocol (raw=false) let HTTP own the
        // end-of-stream lifecycle. This is preferable to raw writes because the
        // final zero chunk can honor a pending `Connection: close` request.
        if ($data instanceof Chunk) {
            if (($connection->context->httpChunkedResponseOpen ?? false) !== true) {
                throw new \RuntimeException('HTTP Chunk sent without an active chunked response.');
            }

            $encodedChunk = (string)$data;
            if ($data->buffer === '') {
                unset($connection->context->httpChunkedResponseOpen);
                if (($connection->context->httpCloseAfterChunkedResponse ?? false) === true) {
                    unset($connection->context->httpCloseAfterChunkedResponse);
                    $connection->context->closeAfterProtocolSend = true;
                }
            }
            return $encodedChunk;
        }

        if (!$data instanceof Response) {
            $body = (string)$data;
            $response = new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body);
        } else {
            $response = $data;
        }

        if ($connection->headers) {
            $response->withHeaders($connection->headers);
            $connection->headers = [];
        }

        $isFinalResponse = $response->getStatusCode() >= 200;
        if ($isFinalResponse) {
            $requestVersion = (string)($connection->context->httpRequestVersion ?? '1.1');
            if (in_array($requestVersion, ['1.0', '1.1'], true)) {
                $response->withProtocolVersion($requestVersion);
            }

            $responseConnection = $response->getHeader('Connection');
            $responseTokens = array_filter(array_map(
                'trim',
                explode(',', strtolower(implode(',', (array)($responseConnection ?? ''))))
            ));
            $shouldClose = (bool)($connection->context->httpShouldCloseAfterResponse ?? false)
                || in_array('close', $responseTokens, true);

            if ($shouldClose) {
                $response->withHeader('Connection', 'close');

                $transferEncoding = strtolower(implode(',', (array)($response->getHeader('Transfer-Encoding') ?? '')));
                $isChunkedStream = in_array('chunked', array_filter(array_map('trim', explode(',', $transferEncoding))), true);
                if ($isChunkedStream) {
                    // Header block is only the beginning of the response. Closing
                    // here would discard subsequent Chunk objects. Defer the
                    // transport close until the terminating zero chunk.
                    $connection->context->httpChunkedResponseOpen = true;
                    $connection->context->httpCloseAfterChunkedResponse = true;
                } else {
                    // TcpConnection::send() переводит socket в graceful ENDING
                    // только после попадания encoded response в send path.
                    $connection->context->closeAfterProtocolSend = true;
                }
            } elseif ($requestVersion === '1.0' && $responseConnection === null) {
                $response->withHeader('Connection', 'keep-alive');
            }

            if (!$shouldClose) {
                $transferEncoding = strtolower(implode(',', (array)($response->getHeader('Transfer-Encoding') ?? '')));
                if (in_array('chunked', array_filter(array_map('trim', explode(',', $transferEncoding))), true)) {
                    $connection->context->httpChunkedResponseOpen = true;
                    unset($connection->context->httpCloseAfterChunkedResponse);
                }
            }

            unset(
                $connection->context->httpRequestVersion,
                $connection->context->httpShouldCloseAfterResponse
            );
        }

        if ($response->file !== null) {
            return static::encodeFile($response, $connection);
        }

        return (string)$response;
    }

    /** @return array<string,list<string>>|null */
    protected static function parseFramingHeaders(string $head): ?array
    {
        $headers = [];
        if ($head === '') {
            return $headers;
        }

        $lines = explode("\r\n", $head);
        if (count($lines) > static::$maxHeaderCount) {
            return null;
        }
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
                // obs-fold больше не поддерживается: он создаёт неоднозначность parser'ов.
                return null;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                return null;
            }
            $name = $parts[0];
            if (!preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name)) {
                return null;
            }
            $value = trim($parts[1], " \t");
            if (str_contains($value, "\0")) {
                return null;
            }
            $headers[strtolower($name)][] = $value;
        }
        return $headers;
    }

    protected static function sendContinueOnce(TcpConnection $connection): void
    {
        if (($connection->context->httpContinueSent ?? false) === true) {
            return;
        }
        $connection->context->httpContinueSent = true;
        $connection->send("HTTP/1.1 100 Continue\r\n\r\n", true);
    }

    protected static function validHost(string $host): bool
    {
        if ($host === '' || preg_match('/\s/', $host) || str_contains($host, '/') || str_contains($host, '@') || str_contains($host, '\\')) {
            return false;
        }

        // IPv6 literal: [::1] или [::1]:8080. Проверяем сам IPv6 через PHP,
        // а port — отдельно, чтобы `:99999` не проходил только из-за пяти цифр.
        if ($host[0] === '[') {
            if (!preg_match('/^\[([^]]+)](?::([0-9]{1,5}))?$/D', $host, $match)) {
                return false;
            }
            if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }
            return !isset($match[2]) || static::validPort($match[2]);
        }

        if (!preg_match('/^([A-Za-z0-9._-]+)(?::([0-9]{1,5}))?$/D', $host, $match)) {
            return false;
        }
        return !isset($match[2]) || static::validPort($match[2]);
    }

    protected static function validPort(string $port): bool
    {
        return $port !== '' && ctype_digit($port) && (int)$port <= 65535;
    }

    protected static function inputChunked(string $buffer, TcpConnection $connection, int $headerLength): int
    {
        $connection->context->httpChunked = true;
        $pos = $headerLength;
        $bufferLength = strlen($buffer);

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                return 0;
            }

            $sizeLine = substr($buffer, $pos, $lineEnd - $pos);
            $semicolon = strpos($sizeLine, ';');
            $hex = $semicolon === false ? $sizeLine : substr($sizeLine, 0, $semicolon);
            if ($hex === '' || strlen($hex) > 16 || !ctype_xdigit($hex)) {
                static::reject($connection, static::HTTP_400);
                return 0;
            }

            $size = hexdec($hex);
            if (!is_int($size)) {
                static::reject($connection, static::HTTP_400);
                return 0;
            }
            $pos = $lineEnd + 2;

            if ($size === 0) {
                // После zero chunk допускаются trailers, заканчивающиеся пустой строкой.
                // Они входят в те же защитные limits, что и обычные headers.
                $trailerCount = 0;
                $trailerBytes = 0;
                while (true) {
                    $trailerEnd = strpos($buffer, "\r\n", $pos);
                    if ($trailerEnd === false) {
                        return 0;
                    }
                    if ($trailerEnd === $pos) {
                        $total = $pos + 2;
                        if ($total > $connection->maxPackageSize) {
                            static::reject($connection, static::HTTP_413);
                            return 0;
                        }
                        return $total;
                    }
                    $trailerLine = substr($buffer, $pos, $trailerEnd - $pos);
                    $trailerCount++;
                    $trailerBytes += strlen($trailerLine) + 2;
                    if ($trailerCount > static::$maxHeaderCount || $trailerBytes > static::$maxHeaderLength) {
                        static::reject($connection, static::HTTP_431);
                        return 0;
                    }
                    if (!preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+:[^\r\n]*$/D", $trailerLine)) {
                        static::reject($connection, static::HTTP_400);
                        return 0;
                    }
                    [$trailerName] = explode(':', $trailerLine, 2);
                    if (static::forbiddenTrailerField(strtolower($trailerName))) {
                        static::reject($connection, static::HTTP_400);
                        return 0;
                    }
                    $pos = $trailerEnd + 2;
                }
            }

            if ($size > $connection->maxPackageSize || $pos + $size + 2 > $connection->maxPackageSize) {
                static::reject($connection, static::HTTP_413);
                return 0;
            }
            if ($pos + $size + 2 > $bufferLength) {
                return 0;
            }
            if (substr($buffer, $pos + $size, 2) !== "\r\n") {
                static::reject($connection, static::HTTP_400);
                return 0;
            }
            $pos += $size + 2;
        }
    }

    /**
     * Fields that affect framing/routing/connection semantics cannot arrive late
     * in trailers after the server has already made those decisions.
     */
    protected static function forbiddenTrailerField(string $name): bool
    {
        return in_array($name, [
            'content-length',
            'transfer-encoding',
            'host',
            'connection',
            'trailer',
            'upgrade',
            'te',
            'expect',
        ], true);
    }

    /** @return array{0:string,1:array<string,string>} */
    protected static function decodeChunked(string $buffer): array
    {
        $headEnd = strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            throw new \RuntimeException('Chunked request has no complete header.');
        }

        $head = substr($buffer, 0, $headEnd);
        // Удаляем TE и нормализуем body в обычный Content-Length request.
        $head = preg_replace('/\r\nTransfer-Encoding\s*:[^\r\n]*/i', '', "\r\n" . $head);
        $head = ltrim((string)$head, "\r\n");

        $pos = $headEnd + 4;
        $body = '';
        $trailers = [];

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                break;
            }
            $sizeLine = substr($buffer, $pos, $lineEnd - $pos);
            $semicolon = strpos($sizeLine, ';');
            $hex = $semicolon === false ? $sizeLine : substr($sizeLine, 0, $semicolon);
            $size = hexdec($hex);
            $pos = $lineEnd + 2;

            if ($size === 0) {
                while (($trailerEnd = strpos($buffer, "\r\n", $pos)) !== false && $trailerEnd !== $pos) {
                    $line = substr($buffer, $pos, $trailerEnd - $pos);
                    [$name, $value] = explode(':', $line, 2);
                    $trailers[strtolower($name)] = trim($value, " \t");
                    $pos = $trailerEnd + 2;
                }
                break;
            }

            $body .= substr($buffer, $pos, $size);
            $pos += $size + 2;
        }

        $head = preg_replace('/\r\nContent-Length\s*:[^\r\n]*/i', '', "\r\n" . $head);
        $head = ltrim((string)$head, "\r\n");
        return [$head . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, $trailers];
    }

    protected static function encodeFile(Response $response, TcpConnection $connection): string
    {
        $info = $response->file;
        $file = $info['file'];
        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false) {
            return (string)(new Response(404, [], '404 Not Found'));
        }

        $offset = max(0, (int)($info['offset'] ?? 0));
        $length = max(0, (int)($info['length'] ?? 0));
        if ($offset > $size) {
            return (string)(new Response(416, ['Content-Range' => 'bytes */' . $size]));
        }

        $bodyLength = $length > 0 ? min($length, $size - $offset) : $size - $offset;
        $response->withHeader('Accept-Ranges', 'bytes')->withHeader('Content-Length', (string)$bodyLength);

        if ($offset !== 0 || $length !== 0) {
            $response->withStatus(206);
            $lastByte = $bodyLength > 0 ? $offset + $bodyLength - 1 : $offset;
            $response->withHeader('Content-Range', 'bytes ' . $offset . '-' . $lastByte . '/' . $size);
        }

        // HEAD должен вернуть те же representation headers, но не читать файл с диска.
        if ($response->isBodySuppressed()) {
            $response->file = null;
            $response->withBody('');
            return (string)$response;
        }

        // Маленький файл дешевле отправить одним HTTP message без отдельного stream lifecycle.
        if ($bodyLength <= 2 * 1024 * 1024) {
            $body = $bodyLength === 0 ? '' : file_get_contents($file, false, null, $offset, $bodyLength);
            if ($body === false || strlen($body) !== $bodyLength) {
                // Head ещё не ушёл в socket, поэтому здесь можно безопасно вернуть
                // полноценную ошибку вместо response с ложным Content-Length.
                return (string)(new Response(500, ['Connection' => 'close'], '500 Internal Server Error'));
            }
            $response->file = null;
            $response->withBody($body);
            return (string)$response;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return (string)(new Response(403, [], '403 Forbidden'));
        }
        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            return (string)(new Response(416, ['Content-Range' => 'bytes */' . $size]));
        }

        $remaining = $bodyLength;
        $previousDrain = $connection->onBufferDrain;
        $connection->context->streamSending = true;
        $connection->context->closeAfterStream = null;

        $drainCallback = null;
        $cleanup = null;
        $pump = null;

        /**
         * Завершает владение file stream и восстанавливает callback приложения.
         * $complete=false используется из TcpConnection::destroy(): там нельзя
         * повторно инициировать close/end.
         */
        $cleanup = function (bool $complete = true) use (
            $connection,
            $handle,
            $previousDrain,
            &$drainCallback
        ): void {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($connection->onBufferDrain === $drainCallback) {
                $connection->onBufferDrain = $previousDrain;
            }

            $connection->context->streamSending = false;
            unset($connection->context->streamCleanup);

            if (!$complete) {
                unset($connection->context->closeAfterStream);
                return;
            }

            $after = $connection->context->closeAfterStream ?? null;
            unset($connection->context->closeAfterStream);
            if (!is_array($after)) {
                return;
            }

            if (($after['mode'] ?? 'close') === 'end') {
                $connection->end($after['data'] ?? null, (bool)($after['raw'] ?? false));
            } else {
                $connection->close($after['data'] ?? null, (bool)($after['raw'] ?? false));
            }
        };
        $connection->context->streamCleanup = $cleanup;

        // Читаем только тогда, когда предыдущий кусок полностью принят socket layer.
        // Если fwrite частичный, TcpConnection буферизует хвост и onBufferDrain
        // возобновит этот pump после опустошения очереди.
        $pump = function () use ($connection, $handle, &$remaining, &$cleanup): void {
            $sentThisTurn = 0;
            while ($remaining > 0 && is_resource($handle)) {
                $chunkSize = min(1024 * 1024, $remaining);
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    // Headers уже могли уйти с исходным Content-Length. Продолжать
                    // соединение после premature EOF нельзя: клиент иначе примет
                    // следующий HTTP response за хвост текущего body.
                    $remaining = 0;
                    $cleanup(false);
                    $connection->destroy();
                    return;
                }

                $remaining -= strlen($chunk);
                $result = $connection->send($chunk, true);
                if ($result === false) {
                    $cleanup(false);
                    $connection->destroy();
                    return;
                }
                if ($result === null) {
                    return;
                }

                $sentThisTurn += strlen($chunk);
                // Даже если kernel buffer очень большой, не монополизируем loop.
                if ($sentThisTurn >= 4 * 1024 * 1024 && $remaining > 0) {
                    $connection->getEventLoop()->delay(0.0, static function () use (&$pump): void {
                        $pump();
                    });
                    return;
                }
            }

            if ($remaining <= 0) {
                $cleanup(true);
            }
        };

        $drainCallback = function (TcpConnection $drainedConnection) use ($previousDrain, &$pump): void {
            try {
                if ($previousDrain !== null) {
                    $previousDrain($drainedConnection);
                }
            } finally {
                $pump();
            }
        };
        $connection->onBufferDrain = $drainCallback;

        // Head обязан предшествовать телу. Если head буферизовался частично,
        // первый chunk будет запущен onBufferDrain; иначе стартуем сразу.
        $headResult = $connection->send((string)$response, true);
        if ($headResult === false) {
            $cleanup(false);
            return '';
        }
        if ($headResult === true) {
            $pump();
        }
        return '';
    }

    protected static function reject(TcpConnection $connection, string $response): void
    {
        $connection->close($response, true);
    }
}
