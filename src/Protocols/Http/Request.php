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

use JsonException;
use localzet\Server\Connection\TcpConnection;
use RuntimeException;
use Stringable;

/**
 * Lazy HTTP request object.
 *
 * Request хранит только данные конкретного HTTP сообщения. В отличие от старой
 * реализации экземпляры Request не переиспользуются между соединениями, поэтому
 * connection/context невозможно случайно "перетащить" в соседний Fiber/request.
 */
class Request implements Stringable
{
    public const MAX_CACHE_STRING_LENGTH = 4096;
    public const MAX_CACHE_SIZE = 256;

    public static int $maxFileUploads = 1024;

    /**
     * Список доверенных reverse proxy. Пока peer не входит в этот список,
     * X-Forwarded-For / Forwarded / X-Real-IP считаются обычными пользовательскими заголовками.
     * Поддерживаются точные IP, CIDR и "*".
     *
     * @var list<string>
     */
    public static array $trustedProxies = [];

    public ?TcpConnection $connection = null;
    public array $properties = [];
    public array $context = [];

    protected array $data = [];
    protected bool $isSafe = true;
    protected bool $isDirty = false;
    /** @var array<string,string>|null */
    protected ?array $chunkTrailers = null;

    public function __construct(protected string $buffer)
    {
    }

    /** @internal HTTP protocol sets trailers once after chunked normalization. */
    public function setChunkTrailers(array $trailers): void
    {
        $this->chunkTrailers ??= $trailers;
    }

    public function get(?string $name = null, mixed $default = null): mixed
    {
        $this->data['get'] ??= $this->parseQuery($this->queryString());
        return $name === null ? $this->data['get'] : ($this->data['get'][$name] ?? $default);
    }

    public function setGet(array $get): static
    {
        $this->isDirty = true;
        $this->data['get'] = $get;
        return $this;
    }

    public function post(?string $name = null, mixed $default = null): mixed
    {
        if (!array_key_exists('post', $this->data)) {
            $this->parsePost();
        }
        return $name === null ? $this->data['post'] : ($this->data['post'][$name] ?? $default);
    }

    public function setPost(array $post): static
    {
        $this->isDirty = true;
        $this->data['post'] = $post;
        return $this;
    }

    /** GET имеет приоритет над POST — сохраняем историческую семантику Localzet. */
    public function input(string $name, mixed $default = null): mixed
    {
        return $this->get($name, $this->post($name, $default));
    }

    public function all(): array
    {
        return $this->get() + $this->post();
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_fill_keys($keys, true));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_fill_keys($keys, true));
    }

    /** Декодированное JSON body либо его поле. */
    public function json(?string $name = null, mixed $default = null): mixed
    {
        if (!array_key_exists('json', $this->data)) {
            try {
                $this->data['json'] = json_decode($this->rawBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->data['json'] = null;
            }
        }
        if ($name === null) {
            return $this->data['json'];
        }
        return is_array($this->data['json']) ? ($this->data['json'][$name] ?? $default) : $default;
    }

    public function header(?string $name = null, mixed $default = null): mixed
    {
        $this->data['headers'] ??= $this->parseHeaders();
        if ($name === null) {
            return $this->data['headers'];
        }
        return $this->data['headers'][strtolower($name)] ?? $default;
    }

    public function setHeaders(array $headers): static
    {
        $this->isDirty = true;
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = $value;
        }
        $this->data['headers'] = $normalized;
        return $this;
    }

    public function trailer(?string $name = null, mixed $default = null): mixed
    {
        $trailers = $this->chunkTrailers ?? [];
        return $name === null ? $trailers : ($trailers[strtolower($name)] ?? $default);
    }

    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['cookie'])) {
            $cookies = [];
            foreach (explode(';', (string)$this->header('cookie', '')) as $item) {
                $parts = explode('=', trim($item), 2);
                if (count($parts) !== 2 || $parts[0] === '') {
                    continue;
                }
                $cookies[$parts[0]] = rawurldecode($parts[1]);
            }
            $this->data['cookie'] = $cookies;
        }
        return $name === null ? $this->data['cookie'] : ($this->data['cookie'][$name] ?? $default);
    }

    /** @return array<string,mixed>|null */
    public function file(?string $name = null): mixed
    {
        if (!array_key_exists('files', $this->data)) {
            $this->parsePost();
        }
        return $name === null ? $this->data['files'] : ($this->data['files'][$name] ?? null);
    }

    public function method(): string
    {
        $this->parseFirstLine();
        return $this->data['method'];
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function protocolVersion(): string
    {
        $this->parseFirstLine();
        return $this->data['protocolVersion'];
    }

    public function host(bool $withoutPort = false): ?string
    {
        $host = $this->header('host');
        if (!is_string($host) || $host === '') {
            return null;
        }
        if (!$withoutPort) {
            return $host;
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        return preg_replace('/:\d{1,5}$/', '', $host);
    }

    public function uri(): string
    {
        $this->parseFirstLine();
        return $this->data['uri'];
    }

    public function path(): string
    {
        $this->parseUri();
        return $this->data['path'];
    }

    public function queryString(): string
    {
        $this->parseUri();
        return $this->data['query_string'];
    }

    /** Protocol-relative URL, сохранён для совместимости со старым Localzet API. */
    public function url(): string
    {
        return '//' . ($this->host() ?? '') . $this->path();
    }

    /** Protocol-relative URL с query string. */
    public function fullUrl(): string
    {
        return '//' . ($this->host() ?? '') . $this->uri();
    }

    public function rawHead(): string
    {
        if (!isset($this->data['head'])) {
            $pos = strpos($this->buffer, "\r\n\r\n");
            $this->data['head'] = $pos === false ? $this->buffer : substr($this->buffer, 0, $pos);
        }
        return $this->data['head'];
    }

    public function rawBody(): string
    {
        $pos = strpos($this->buffer, "\r\n\r\n");
        return $pos === false ? '' : substr($this->buffer, $pos + 4);
    }

    public function rawBuffer(): string
    {
        return $this->buffer;
    }

    public function isAjax(): bool
    {
        return strcasecmp((string)$this->header('x-requested-with', ''), 'XMLHttpRequest') === 0;
    }

    public function isPjax(): bool
    {
        return (bool)$this->header('x-pjax', false);
    }

    public function isJson(): bool
    {
        $type = strtolower((string)$this->header('content-type', ''));
        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    public function isHTML(): bool
    {
        $type = strtolower((string)$this->header('content-type', ''));
        return str_contains($type, '/html') || str_contains($type, '+html');
    }

    /** Историческое имя. */
    public function acceptJson(): bool
    {
        return $this->acceptsJson();
    }

    public function acceptsJson(): bool
    {
        if ($this->isJson()) {
            return true;
        }
        if ($this->acceptsAnyContentType()) {
            return true;
        }
        foreach (array_keys($this->parseAcceptHeader()) as $type) {
            if ($type === 'application/json' || str_ends_with($type, '+json')) {
                return true;
            }
        }
        return false;
    }

    public function expectsJson(): bool
    {
        return ($this->isAjax() && !$this->isPjax()) || ($this->acceptsJson() && !$this->isHTML());
    }

    public function acceptsAnyContentType(): bool
    {
        $accepts = $this->parseAcceptHeader();
        return isset($accepts['*/*']) || isset($accepts['*']);
    }

    /**
     * @return array<string,array<string,string>> media-type => parameters
     */
    public function parseAcceptHeader(): array
    {
        if (isset($this->data['accept'])) {
            return $this->data['accept'];
        }
        $this->data['accept'] = [];
        foreach (explode(',', (string)$this->header('accept', '')) as $accept) {
            $accept = trim($accept);
            if ($accept === '') {
                continue;
            }
            $parts = array_map('trim', explode(';', $accept));
            $mediaType = strtolower((string)array_shift($parts));
            $params = [];
            foreach ($parts as $part) {
                if (!str_contains($part, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $part, 2);
                $params[strtolower(trim($key))] = trim($value, " \t\"");
            }
            $this->data['accept'][$mediaType] = $params;
        }
        return $this->data['accept'];
    }

    public function session(): Session
    {
        return $this->context['session'] ??= new Session($this->sessionId());
    }

    public function sessionId(?string $sessionId = null): string
    {
        if ($sessionId !== null) {
            if (!$this->isValidSessionId($sessionId)) {
                throw new \InvalidArgumentException('Invalid session id.');
            }
            unset($this->context['sid'], $this->context['session']);
        }

        if (!isset($this->context['sid'])) {
            $sessionName = Session::$name;
            $sid = $sessionId === null ? $this->cookie($sessionName, '') : '';
            if (is_string($sid) && strlen($sid) >= 2 && $sid[0] === '"' && $sid[-1] === '"') {
                $sid = substr($sid, 1, -1);
            }
            $sid = $this->isValidSessionId($sid) ? $sid : '';

            if ($sid === '') {
                if ($this->connection === null) {
                    throw new RuntimeException('Cannot create session after the connection is detached.');
                }
                $sid = $sessionId ?? static::createSessionId();
                $this->setSidCookie($sessionName, $sid, Session::getCookieParams());
            }
            $this->context['sid'] = $sid;
        }
        return $this->context['sid'];
    }

    public function isValidSessionId(mixed $sessionId): bool
    {
        return is_string($sessionId) && preg_match('/^[A-Za-z0-9,-]{16,256}$/D', $sessionId) === 1;
    }

    public static function createSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function sessionRegenerateId(bool $deleteOldSession = false): string
    {
        $old = $this->session();
        $data = $old->all();
        if ($deleteOldSession) {
            $old->flush();
        }

        $sid = static::createSessionId();
        $session = new Session($sid);
        $session->put($data);
        $this->setSidCookie(Session::$name, $sid, Session::getCookieParams());
        $this->context['sid'] = $sid;
        $this->context['session'] = $session;
        return $sid;
    }

    protected function setSidCookie(string $sessionName, string $sid, array $params): void
    {
        if ($this->connection === null) {
            throw new RuntimeException('Cannot set session cookie after the connection is detached.');
        }

        $cookie = rawurlencode($sessionName) . '=' . rawurlencode($sid);
        if (!empty($params['domain'])) $cookie .= '; Domain=' . str_replace(["\r", "\n"], '', (string)$params['domain']);
        if (!empty($params['lifetime'])) $cookie .= '; Max-Age=' . max(0, (int)$params['lifetime']);
        if (!empty($params['path'])) $cookie .= '; Path=' . str_replace(["\r", "\n"], '', (string)$params['path']);
        if (!empty($params['samesite'])) $cookie .= '; SameSite=' . str_replace(["\r", "\n"], '', (string)$params['samesite']);
        if (($params['secure'] ?? false) === true) $cookie .= '; Secure';
        if (($params['httponly'] ?? true) === true) $cookie .= '; HttpOnly';
        $this->connection->headers['Set-Cookie'][] = $cookie;
    }

    public function getLocalIp(): string
    {
        return $this->connection?->getLocalIp() ?? '';
    }

    public function getLocalPort(): int
    {
        return $this->connection?->getLocalPort() ?? 0;
    }

    public function getRemoteIp(): string
    {
        return $this->connection?->getRemoteIp() ?? '';
    }

    public function getRemotePort(): int
    {
        return $this->connection?->getRemotePort() ?? 0;
    }

    public function getConnection(): TcpConnection
    {
        if ($this->connection === null) {
            throw new RuntimeException('Request connection is no longer available.');
        }
        return $this->connection;
    }

    /**
     * Возвращает клиентский IP с безопасной моделью доверия к reverse proxy.
     *
     * По умолчанию forwarded headers игнорируются. Чтобы их учитывать, добавьте
     * адрес вашего proxy/LB в Request::$trustedProxies.
     */
    public function getRequestIp(): ?string
    {
        $peer = $this->getRemoteIp();
        if ($peer === '') {
            return null;
        }
        if (!static::isTrustedProxy($peer)) {
            return filter_var($peer, FILTER_VALIDATE_IP) ? $peer : null;
        }

        $forwarded = (string)$this->header('forwarded', '');
        if ($forwarded !== '' && preg_match('/(?:^|[;,])\s*for=(?:"?\[?)([^\]";,]+)(?:\]?"?)/i', $forwarded, $match)) {
            $candidate = static::normalizeForwardedIp($match[1]);
            if ($candidate !== null) return $candidate;
        }

        foreach (['x-forwarded-for', 'x-real-ip', 'client-ip', 'x-client-ip'] as $header) {
            $value = $this->header($header);
            if (!is_string($value) || $value === '') continue;
            foreach (explode(',', $value) as $candidate) {
                $ip = static::normalizeForwardedIp($candidate);
                if ($ip !== null) return $ip;
            }
        }
        return $peer;
    }

    public function isSecure(): bool
    {
        if (($this->connection?->transport ?? '') === 'ssl') {
            return true;
        }
        $peer = $this->getRemoteIp();
        if ($peer !== '' && static::isTrustedProxy($peer)) {
            $forwardedProto = strtolower(trim(explode(',', (string)$this->header('x-forwarded-proto', ''))[0] ?? ''));
            if ($forwardedProto === 'https' || $forwardedProto === 'wss') {
                return true;
            }
            if (preg_match('/(?:^|;)\s*proto=(https|wss)(?:;|$)/i', (string)$this->header('forwarded', ''))) {
                return true;
            }
        }
        return false;
    }

    public function toArray(): array
    {
        $result = $this->properties + [
                'protocolVersion' => $this->protocolVersion(),
                'host' => $this->host(),
                'path' => $this->path(),
                'uri' => $this->uri(),
                'method' => $this->method(),
                'get' => $this->get(),
                'post' => $this->post(),
                'header' => $this->header(),
                'cookie' => $this->cookie(),
                'isAjax' => $this->isAjax(),
                'isPjax' => $this->isPjax(),
                'acceptJson' => $this->acceptJson(),
                'expectsJson' => $this->expectsJson(),
            ];
        if ($this->connection !== null) {
            $result += [
                'localIp' => $this->getLocalIp(),
                'localPort' => $this->getLocalPort(),
                'remoteIp' => $this->getRemoteIp(),
                'remotePort' => $this->getRemotePort(),
                'requestIp' => $this->getRequestIp(),
                'secure' => $this->isSecure(),
            ];
        }
        return $result;
    }

    protected function parseFirstLine(): void
    {
        if (isset($this->data['method'])) {
            return;
        }
        $end = strpos($this->buffer, "\r\n");
        $line = $end === false ? $this->buffer : substr($this->buffer, 0, $end);
        $parts = explode(' ', $line, 3);
        $this->data['method'] = $parts[0] ?? '';
        $this->data['uri'] = $parts[1] ?? '/';
        $this->data['protocolVersion'] = isset($parts[2]) && str_starts_with($parts[2], 'HTTP/')
            ? substr($parts[2], 5)
            : '1.0';
    }

    protected function parseUri(): void
    {
        if (isset($this->data['path'])) {
            return;
        }
        $parts = parse_url($this->uri());
        $this->data['path'] = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $this->data['query_string'] = is_array($parts) ? ($parts['query'] ?? '') : '';
    }

    protected function parseHeaders(): array
    {
        $headers = [];
        $lines = explode("\r\n", $this->rawHead());
        array_shift($lines);
        foreach ($lines as $line) {
            if ($line === '' || !str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $key = strtolower(trim($name));
            $value = trim($value, " \t");
            $headers[$key] = isset($headers[$key]) ? $headers[$key] . ',' . $value : $value;
        }
        return $headers;
    }

    protected function parseQuery(string $query): array
    {
        if ($query === '') return [];
        parse_str($query, $result);
        return $this->sanitizeInput($result);
    }

    protected function parsePost(): void
    {
        $this->data['post'] = [];
        $this->data['files'] = [];
        $contentType = (string)$this->header('content-type', '');
        $lower = strtolower($contentType);

        if (str_starts_with($lower, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody(), $post);
            $this->data['post'] = $this->sanitizeInput($post);
            return;
        }
        if (str_contains($lower, 'json')) {
            $json = $this->json();
            $this->data['post'] = is_array($json) ? $json : [];
            return;
        }
        if (str_starts_with($lower, 'multipart/form-data')) {
            $this->parseMultipart($contentType);
        }
    }

    protected function parseMultipart(string $contentType): void
    {
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $matches)) {
            return;
        }
        $boundary = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
        if ($boundary === '' || strlen($boundary) > 200) {
            return;
        }

        $postPairs = [];
        $filePairs = [];
        $files = [];
        $uploadCount = 0;
        $fileToken = 0;

        foreach (explode('--' . $boundary, $this->rawBody()) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--\r\n' || $part === '--' || !str_contains($part, "\r\n\r\n")) {
                continue;
            }
            // Boundary delimiter contributes CRLF before the next part; it is not file content.
            $part = preg_replace('/\r\n$/D', '', $part, 1) ?? $part;
            [$head, $body] = explode("\r\n\r\n", $part, 2);
            if (!preg_match('/^Content-Disposition:\s*form-data;[^\r\n]*\bname="([^"]*)"(?:;\s*filename="([^"]*)")?/mi', $head, $match)) {
                continue;
            }

            $name = $match[1];
            $filename = $match[2] ?? null;
            if ($name === '') continue;

            if ($filename === null || $filename === '') {
                $postPairs[] = rawurlencode($name) . '=' . rawurlencode($body);
                continue;
            }
            if (++$uploadCount > static::$maxFileUploads) {
                break;
            }

            $tmpDir = \localzet\Server\Protocols\Http::uploadTmpDir();
            $tmp = tempnam($tmpDir, 'localzet-upload-');
            if ($tmp === false || file_put_contents($tmp, $body, LOCK_EX) === false) {
                if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
                continue;
            }
            preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $head, $typeMatch);
            $token = (string)++$fileToken;
            $files[$token] = [
                'name' => basename(str_replace('\\', '/', $filename)),
                'tmp_name' => $tmp,
                'size' => strlen($body),
                'error' => UPLOAD_ERR_OK,
                'type' => trim($typeMatch[1] ?? 'application/octet-stream'),
            ];
            $filePairs[] = rawurlencode($name) . '=' . rawurlencode($token);
        }

        if ($postPairs) {
            parse_str(implode('&', $postPairs), $post);
            $this->data['post'] = $this->sanitizeInput($post);
        }
        if ($filePairs) {
            parse_str(implode('&', $filePairs), $fileTree);
            array_walk_recursive($fileTree, static function (&$value) use ($files): void {
                if (is_string($value) && isset($files[$value])) {
                    $value = $files[$value];
                }
            });
            $this->data['files'] = $fileTree;
        }
    }

    protected function sanitizeInput(array $input): array
    {
        array_walk_recursive($input, static function (&$value): void {
            if (is_string($value)) {
                $value = str_replace("\0", '', $value);
            }
        });
        return $input;
    }

    protected static function isTrustedProxy(string $ip): bool
    {
        foreach (static::$trustedProxies as $trusted) {
            if ($trusted === '*' || $trusted === $ip || static::ipMatchesCidr($ip, $trusted)) {
                return true;
            }
        }
        return false;
    }

    protected static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) return false;
        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary) || !ctype_digit($prefix)) {
            return false;
        }
        $bits = (int)$prefix;
        $maxBits = strlen($ipBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) return false;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) return false;
        if ($remaining === 0) return true;
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($ipBinary[$bytes]) & $mask) === (ord($networkBinary[$bytes]) & $mask);
    }

    protected static function normalizeForwardedIp(string $candidate): ?string
    {
        $candidate = trim($candidate, " \t\"");
        if (str_starts_with($candidate, '[') && ($end = strpos($candidate, ']')) !== false) {
            $candidate = substr($candidate, 1, $end - 1);
        } elseif (substr_count($candidate, ':') === 1 && preg_match('/^(.+):\d+$/', $candidate, $match)) {
            $candidate = $match[1];
        }
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->properties[$name]);
    }

    public function __wakeup(): void
    {
        $this->isSafe = false;
    }

    public function __unserialize(array $data): void
    {
        $this->isSafe = false;
    }

    public function __clone()
    {
        if ($this->isDirty) {
            unset($this->data['get'], $this->data['post'], $this->data['headers']);
        }
    }

    /** Освобождает context и временные upload-файлы. Безопасно вызывать повторно. */
    public function destroy(): void
    {
        $this->context = [];
        $this->properties = [];
        $this->connection = null;

        if ($this->isSafe && isset($this->data['files'])) {
            clearstatcache();
            array_walk_recursive($this->data['files'], static function ($value, $key): void {
                if ($key === 'tmp_name' && is_string($value) && is_file($value)) {
                    @unlink($value);
                }
            });
        }
        unset($this->data['files']);
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
