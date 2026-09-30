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

namespace localzet\Server\Protocols\Http;

use Stringable;

/**
 * HTTP/1.x response value object.
 *
 * Методы сохраняют mutable-style API исторического Localzet Server:
 * `withHeader()` и `withStatus()` меняют текущий объект и возвращают `$this`.
 */
class Response implements Stringable
{
    public const PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
        207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found',
        303 => 'See Other', 304 => 'Not Modified', 307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required',
        412 => 'Precondition Failed', 413 => 'Payload Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable', 417 => 'Expectation Failed',
        418 => "I'm a teapot", 421 => 'Misdirected Request',
        422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early', 426 => 'Upgrade Required',
        428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    protected static array $mimeTypeMap = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml', 'json' => 'application/json',
        'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'wasm' => 'application/wasm', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ];

    protected ?string $reason = null;
    protected string $version = '1.1';
    public ?array $file = null;
    protected bool $suppressBody = false;

    public function __construct(
        protected int     $status = 200,
        protected array   $headers = [],
        protected ?string $body = ''
    )
    {
    }

    /** Исторический entrypoint; MIME map теперь встроен и не требует eager init. */
    public static function init(): void
    {
        static::initMimeTypeMap();
    }

    /**
     * Сохраняется для API compatibility. Базовая карта MIME уже загружена в class,
     * поэтому метод намеренно идемпотентен.
     */
    public static function initMimeTypeMap(): void
    {
        // no-op: built-in map is ready without filesystem I/O
    }

    public function header(string $name, mixed $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeader(string $name, mixed $value): static
    {
        return $this->header($name, $value);
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function withoutHeader(string $name): static
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp((string)$key, $name) === 0) {
                unset($this->headers[$key]);
            }
        }
        return $this;
    }

    public function getHeader(string $name): array|string|int|null
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMimeType(string $extension): string
    {
        return self::$mimeTypeMap[strtolower($extension)] ?? 'application/octet-stream';
    }

    public function withStatus(int $code, ?string $reasonPhrase = null): static
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }
        $this->status = $code;
        $this->reason = $reasonPhrase === null ? null : str_replace(["\r", "\n"], '', $reasonPhrase);
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getReasonPhrase(): ?string
    {
        return $this->reason;
    }

    public function withProtocolVersion(string $version): static
    {
        if (!preg_match('/^1\.[01]$/', $version)) {
            throw new \InvalidArgumentException('Only HTTP/1.0 and HTTP/1.1 are supported.');
        }
        $this->version = $version;
        return $this;
    }

    public function withBody(?string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Готовит headers для application-managed HTTP/1.1 chunked stream.
     *
     * После отправки response приложение может слать `new Chunk($data)` обычным
     * `send()` и завершить поток пустым Chunk. Тогда HTTP codec сам увидит финальный
     * zero-chunk и корректно выполнит отложенный `Connection: close`.
     * Raw-режим остаётся совместимым, но lifecycle в нём контролирует приложение.
     */
    public function withChunkedTransfer(): static
    {
        $this->withoutHeader('Content-Length');
        $this->headers['Transfer-Encoding'] = 'chunked';
        $this->body = '';
        return $this;
    }

    public function rawBody(): ?string
    {
        return $this->body;
    }

    /**
     * Не отправляет body, сохраняя Content-Length исходного representation.
     * Используется для HEAD и может быть полезен application adapters.
     */
    public function withoutBody(bool $preserveContentLength = true): static
    {
        if ($preserveContentLength && $this->file === null && $this->getHeader('Content-Length') === null) {
            $this->headers['Content-Length'] = (string)strlen($this->body ?? '');
        }
        $this->suppressBody = true;
        return $this;
    }

    public function isBodySuppressed(): bool
    {
        return $this->suppressBody;
    }

    /**
     * Сжимает in-memory representation для конкретного HTTP-запроса, если клиент
     * разрешает gzip и сжатие действительно уменьшает payload.
     *
     * Метод намеренно opt-in: автоматическое HTTP compression может быть опасно
     * для ответов, где секретные данные смешиваются с отражённым пользовательским
     * вводом (класс атак BREACH). Решение о включении остаётся за приложением.
     *
     * File/range/chunked/already encoded responses не изменяются. `no-transform`
     * также запрещает преобразование representation. Для HEAD рассчитывается
     * Content-Length gzip representation, но тело в wire response не отправляется.
     *
     * @param Request $request Запрос, для которого формируется ответ.
     * @param int $minBytes Минимальный размер тела, начиная с которого пробуем gzip.
     * @param int $level Уровень zlib от -1 до 9.
     */
    public function withCompressionForRequest(Request $request, int $minBytes = 1024, int $level = -1): static
    {
        if ($minBytes < 0) {
            throw new \InvalidArgumentException('Compression minimum size must be >= 0.');
        }
        if ($level < -1 || $level > 9) {
            throw new \InvalidArgumentException('Gzip level must be between -1 and 9.');
        }
        if (!function_exists('gzencode')
            || $this->file !== null
            || $this->body === null
            || strlen($this->body) < $minBytes
            || $this->getHeader('Content-Encoding') !== null
            || $this->getHeader('Transfer-Encoding') !== null
            || $request->header('range') !== null
            || !self::requestAcceptsGzip($request)) {
            return $this;
        }

        $cacheControl = strtolower(implode(',', (array)($this->getHeader('Cache-Control') ?? '')));
        if (preg_match('/(?:^|,)\s*no-transform\s*(?:,|$)/', $cacheControl)) {
            return $this;
        }

        // 1xx, 204 и 304 не содержат message body по HTTP semantics.
        if (($this->status >= 100 && $this->status < 200) || $this->status === 204 || $this->status === 304) {
            return $this;
        }

        $compressed = gzencode($this->body, $level, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed) || strlen($compressed) >= strlen($this->body)) {
            return $this;
        }

        $this->body = $compressed;
        $this->headers['Content-Encoding'] = 'gzip';
        $this->headers['Content-Length'] = (string)strlen($compressed);
        $this->appendVary('Accept-Encoding');

        if ($request->isMethod('HEAD')) {
            $this->suppressBody = true;
        }

        return $this;
    }

    /**
     * Готовит file response. Реальная потоковая отправка выполняется Http::encode().
     */
    public function withFile(string $file, int $offset = 0, int $length = 0): static
    {
        $this->file = null;
        if (!is_file($file) || !is_readable($file)) {
            return $this->withStatus(404)->withBody('404 Not Found');
        }
        if ($offset < 0 || $length < 0) {
            throw new \InvalidArgumentException('File offset/length must be >= 0.');
        }

        clearstatcache(true, $file);
        $size = filesize($file);
        $mtime = filemtime($file);
        if ($size === false || $mtime === false) {
            return $this->withStatus(404)->withBody('404 Not Found');
        }

        $this->file = [
            'file' => $file,
            'offset' => $offset,
            'length' => $length,
            'size' => $size,
            'mtime' => $mtime,
        ];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $this->headers['Content-Type'] ??= $this->getMimeType($extension);
        $this->headers['Last-Modified'] ??= gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $this->headers['ETag'] ??= sprintf('"%x-%x"', $mtime, $size);
        $this->headers['Accept-Ranges'] ??= 'bytes';
        return $this;
    }

    /**
     * HTTP-aware file response: conditional GET/HEAD + single byte range.
     *
     * Multiple ranges intentionally fall back to a full 200 response instead of
     * building multipart/byteranges. Это RFC-compatible server choice и не требует
     * держать сложный multipart streaming path внутри базового runtime.
     */
    public function withFileForRequest(Request $request, string $file): static
    {
        $this->withFile($file);
        if ($this->file === null) {
            return $this;
        }

        $size = (int)$this->file['size'];
        $mtime = (int)$this->file['mtime'];
        $etag = (string)$this->getHeader('ETag');
        $method = $request->method();
        $isReadMethod = $method === 'GET' || $method === 'HEAD';

        if ($isReadMethod && $this->preconditionFailed($request, $etag, $mtime)) {
            $this->file = null;
            $this->body = '';
            $this->suppressBody = true;
            return $this->withStatus(412)->withHeader('Content-Length', '0');
        }

        if ($isReadMethod && $this->isNotModified($request, $etag, $mtime)) {
            $this->file = null;
            $this->body = '';
            $this->suppressBody = true;
            $this->withoutHeader('Content-Length');
            return $this->withStatus(304);
        }

        if ($isReadMethod) {
            $range = trim((string)$request->header('range', ''));
            if ($range !== '' && $this->ifRangeAllowsRange($request, $etag, $mtime)) {
                $parsed = $this->parseSingleByteRange($range, $size);
                if ($parsed === false) {
                    $this->file = null;
                    $this->body = '';
                    $this->suppressBody = true;
                    $this->withStatus(416)->withHeader('Content-Range', 'bytes */' . $size);
                } elseif ($parsed !== null) {
                    [$offset, $length] = $parsed;
                    $this->file['offset'] = $offset;
                    $this->file['length'] = $length;
                }
            }
        }

        if ($method === 'HEAD') {
            $this->suppressBody = true;
        }
        return $this;
    }

    protected function preconditionFailed(Request $request, string $etag, int $mtime): bool
    {
        $ifMatch = trim((string)$request->header('if-match', ''));
        if ($ifMatch !== '') {
            if ($ifMatch === '*') {
                return false; // Resource exists: withFile() already resolved it.
            }

            $matched = false;
            foreach (explode(',', $ifMatch) as $candidate) {
                $candidate = trim($candidate);
                // If-Match uses strong comparison: weak validators never satisfy it.
                if (!str_starts_with($candidate, 'W/') && hash_equals($etag, $candidate)) {
                    $matched = true;
                    break;
                }
            }
            return !$matched;
        }

        // RFC precedence: If-Unmodified-Since is ignored when If-Match is present.
        $ifUnmodifiedSince = trim((string)$request->header('if-unmodified-since', ''));
        if ($ifUnmodifiedSince === '') {
            return false;
        }
        $time = strtotime($ifUnmodifiedSince);
        return $time !== false && $mtime > $time;
    }

    protected function isNotModified(Request $request, string $etag, int $mtime): bool
    {
        $ifNoneMatch = trim((string)$request->header('if-none-match', ''));
        if ($ifNoneMatch !== '') {
            if ($ifNoneMatch === '*') {
                return true;
            }
            $target = preg_replace('/^W\//i', '', $etag);
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if (preg_replace('/^W\//i', '', $candidate) === $target) {
                    return true;
                }
            }
            return false;
        }

        $ifModifiedSince = trim((string)$request->header('if-modified-since', ''));
        if ($ifModifiedSince === '') {
            return false;
        }
        $time = strtotime($ifModifiedSince);
        return $time !== false && $mtime <= $time;
    }

    protected function ifRangeAllowsRange(Request $request, string $etag, int $mtime): bool
    {
        $ifRange = trim((string)$request->header('if-range', ''));
        if ($ifRange === '') {
            return true;
        }
        if (str_starts_with($ifRange, 'W/')) {
            return false;
        }
        if (str_starts_with($ifRange, '"')) {
            return hash_equals($etag, $ifRange);
        }
        $time = strtotime($ifRange);
        return $time !== false && $mtime <= $time;
    }

    /** @return null|false|array{0:int,1:int} null=ignore unsupported multi-range */
    protected function parseSingleByteRange(string $range, int $size): array|false|null
    {
        if (!str_starts_with(strtolower($range), 'bytes=')) {
            return null;
        }
        $spec = trim(substr($range, 6));
        if ($spec === '' || str_contains($spec, ',')) {
            return $spec === '' ? false : null;
        }
        if (!preg_match('/^(\d*)-(\d*)$/D', $spec, $match)) {
            return false;
        }

        $startText = $match[1];
        $endText = $match[2];
        if ($startText === '' && $endText === '') {
            return false;
        }
        if ($size <= 0) {
            return false;
        }

        if ($startText === '') {
            $suffix = (int)$endText;
            if ($suffix <= 0) {
                return false;
            }
            $length = min($suffix, $size);
            return [$size - $length, $length];
        }

        $start = (int)$startText;
        if ($start >= $size) {
            return false;
        }
        $end = $endText === '' ? $size - 1 : min((int)$endText, $size - 1);
        if ($end < $start) {
            return false;
        }
        return [$start, $end - $start + 1];
    }

    public function cookie(
        string $name,
        string $value = '',
        ?int   $maxAge = null,
        string $path = '',
        string $domain = '',
        bool   $secure = false,
        bool   $httpOnly = false,
        string $sameSite = ''
    ): static
    {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);
        if ($maxAge !== null) {
            $cookie .= '; Max-Age=' . $maxAge;
        }
        if ($path !== '') {
            $cookie .= '; Path=' . $path;
        }
        if ($domain !== '') {
            $cookie .= '; Domain=' . $domain;
        }
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        if ($sameSite !== '') {
            $cookie .= '; SameSite=' . $sameSite;
        }

        $existing = $this->getHeader('Set-Cookie');
        if ($existing === null) {
            $this->headers['Set-Cookie'] = [$cookie];
        } elseif (is_array($existing)) {
            $this->withoutHeader('Set-Cookie');
            $this->headers['Set-Cookie'] = [...$existing, $cookie];
        } else {
            $this->withoutHeader('Set-Cookie');
            $this->headers['Set-Cookie'] = [$existing, $cookie];
        }
        return $this;
    }

    public function __toString(): string
    {
        $reason = $this->reason ?? self::PHRASES[$this->status] ?? 'Unknown';
        $headers = $this->headers;
        $headers['Server'] ??= 'Localzet-Server';
        $headers['Date'] ??= gmdate('D, d M Y H:i:s') . ' GMT';

        $statusWithoutBody = ($this->status >= 100 && $this->status < 200)
            || $this->status === 204
            || $this->status === 304;

        if (!$statusWithoutBody
            && $this->file === null
            && $this->getHeaderFrom($headers, 'Content-Length') === null
            && $this->getHeaderFrom($headers, 'Transfer-Encoding') === null) {
            $headers['Content-Length'] = (string)strlen($this->body ?? '');
        }

        $head = "HTTP/{$this->version} {$this->status} {$reason}\r\n";
        foreach ($headers as $name => $value) {
            foreach ((array)$value as $item) {
                $safeName = str_replace(["\r", "\n"], '', (string)$name);
                $safeValue = str_replace(["\r", "\n"], '', (string)$item);
                $head .= $safeName . ': ' . $safeValue . "\r\n";
            }
        }

        return $head . "\r\n" . (($statusWithoutBody || $this->suppressBody) ? '' : ($this->body ?? ''));
    }

    /** Проверяет Accept-Encoding с учётом q-values и wildcard. */
    protected static function requestAcceptsGzip(Request $request): bool
    {
        $header = strtolower((string)$request->header('accept-encoding', ''));
        if ($header === '') {
            return false;
        }

        $gzipQ = null;
        $wildcardQ = null;
        foreach (explode(',', $header) as $item) {
            $parts = array_map('trim', explode(';', $item));
            $encoding = strtolower((string)array_shift($parts));
            if ($encoding === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (!str_contains($parameter, '=')) {
                    continue;
                }
                [$name, $value] = array_map('trim', explode('=', $parameter, 2));
                if (strtolower($name) !== 'q') {
                    continue;
                }
                if (!is_numeric($value)) {
                    $quality = 0.0;
                    break;
                }
                $quality = max(0.0, min(1.0, (float)$value));
            }

            if ($encoding === 'gzip' || $encoding === 'x-gzip') {
                $gzipQ = $quality;
            } elseif ($encoding === '*') {
                $wildcardQ = $quality;
            }
        }

        return ($gzipQ ?? $wildcardQ ?? 0.0) > 0.0;
    }

    /** Добавляет token в Vary, не создавая дубликатов. */
    protected function appendVary(string $token): void
    {
        $existing = implode(',', (array)($this->getHeader('Vary') ?? ''));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $existing))));
        foreach ($tokens as $existingToken) {
            if (strcasecmp($existingToken, $token) === 0) {
                return;
            }
        }
        $tokens[] = $token;
        $this->withoutHeader('Vary');
        $this->headers['Vary'] = implode(', ', $tokens);
    }

    protected function getHeaderFrom(array $headers, string $name): mixed
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
