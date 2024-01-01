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

namespace localzet\Server\Protocols\Http;

use Fig\Http\Message\StatusCodeInterface;
use localzet\Server;
use localzet\Server\PSRUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;
use function explode;
use function file;
use function filemtime;
use function gmdate;
use function is_array;
use function is_file;
use function pathinfo;
use function preg_match;
use function rawurlencode;
use function substr;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

/**
 * Class Response
 * @package localzet\Server\Protocols\Http
 */
class Response extends Message implements ResponseInterface, StatusCodeInterface, Stringable
{
    /**
     * Phrases.
     *
     * @var array<int,string>
     *
     * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
     */
    protected static array $phrases = [
        // Informational 1xx
        self::STATUS_CONTINUE => 'Continue',
        self::STATUS_SWITCHING_PROTOCOLS => 'Switching Protocols',
        self::STATUS_PROCESSING => 'Processing', // WebDAV; RFC 2518
        self::STATUS_EARLY_HINTS => 'Early Hints', // RFC 8297
        // Successful 2xx
        self::STATUS_OK => 'OK',
        self::STATUS_CREATED => 'Created',
        self::STATUS_ACCEPTED => 'Accepted',
        self::STATUS_NON_AUTHORITATIVE_INFORMATION => 'Non-Authoritative Information', // since HTTP/1.1
        self::STATUS_NO_CONTENT => 'No Content',
        self::STATUS_RESET_CONTENT => 'Reset Content',
        self::STATUS_PARTIAL_CONTENT => 'Partial Content', // RFC 7233
        self::STATUS_MULTI_STATUS => 'Multi-Status', // WebDAV; RFC 4918
        self::STATUS_ALREADY_REPORTED => 'Already Reported', // WebDAV; RFC 5842
        self::STATUS_IM_USED => 'IM Used', // RFC 3229
        // Redirection 3xx
        self::STATUS_MULTIPLE_CHOICES => 'Multiple Choices',
        self::STATUS_MOVED_PERMANENTLY => 'Moved Permanently',
        self::STATUS_FOUND => 'Found', // Previously "Moved temporarily"
        self::STATUS_SEE_OTHER => 'See Other', // since HTTP/1.1
        self::STATUS_NOT_MODIFIED => 'Not Modified', // RFC 7232
        self::STATUS_USE_PROXY => 'Use Proxy', // since HTTP/1.1
        self::STATUS_RESERVED => 'Switch Proxy',
        self::STATUS_TEMPORARY_REDIRECT => 'Temporary Redirect', // since HTTP/1.1
        self::STATUS_PERMANENT_REDIRECT => 'Permanent Redirect', // RFC 7538
        // Client Errors 4xx
        self::STATUS_BAD_REQUEST => 'Bad Request',
        self::STATUS_UNAUTHORIZED => 'Unauthorized', // RFC 7235
        self::STATUS_PAYMENT_REQUIRED => 'Payment Required',
        self::STATUS_FORBIDDEN => 'Forbidden',
        self::STATUS_NOT_FOUND => 'Not Found',
        self::STATUS_METHOD_NOT_ALLOWED => 'Method Not Allowed',
        self::STATUS_NOT_ACCEPTABLE => 'Not Acceptable',
        self::STATUS_PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required', // RFC 7235
        self::STATUS_REQUEST_TIMEOUT => 'Request Timeout',
        self::STATUS_CONFLICT => 'Conflict',
        self::STATUS_GONE => 'Gone',
        self::STATUS_LENGTH_REQUIRED => 'Length Required',
        self::STATUS_PRECONDITION_FAILED => 'Precondition Failed', // RFC 7232
        self::STATUS_PAYLOAD_TOO_LARGE => 'Payload Too Large', // RFC 7231
        self::STATUS_URI_TOO_LONG => 'URI Too Long', // RFC 7231
        self::STATUS_UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type', // RFC 7231
        self::STATUS_RANGE_NOT_SATISFIABLE => 'Range Not Satisfiable', // RFC 7233
        self::STATUS_EXPECTATION_FAILED => 'Expectation Failed',
        self::STATUS_IM_A_TEAPOT => 'I\'m a teapot', // RFC 2324, RFC 7168
        self::STATUS_MISDIRECTED_REQUEST => 'Misdirected Request', // RFC 7540
        self::STATUS_UNPROCESSABLE_ENTITY => 'Unprocessable Entity', // WebDAV; RFC 4918
        self::STATUS_LOCKED => 'Locked', // WebDAV; RFC 4918
        self::STATUS_FAILED_DEPENDENCY => 'Failed Dependency', // WebDAV; RFC 4918
        self::STATUS_TOO_EARLY => 'Too Early', // RFC 8470
        self::STATUS_UPGRADE_REQUIRED => 'Upgrade Required',
        self::STATUS_PRECONDITION_REQUIRED => 'Precondition Required', // RFC 6585
        self::STATUS_TOO_MANY_REQUESTS => 'Too Many Requests', // RFC 6585
        self::STATUS_REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large', // RFC 6585
        self::STATUS_UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons', // RFC 7725
        // Server Errors 5xx
        self::STATUS_INTERNAL_SERVER_ERROR => 'Internal Server Error',
        self::STATUS_NOT_IMPLEMENTED => 'Not Implemented',
        self::STATUS_BAD_GATEWAY => 'Bad Gateway',
        self::STATUS_SERVICE_UNAVAILABLE => 'Service Unavailable',
        self::STATUS_GATEWAY_TIMEOUT => 'Gateway Timeout',
        self::STATUS_VERSION_NOT_SUPPORTED => 'HTTP Version Not Supported',
        self::STATUS_VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates', // RFC 2295
        self::STATUS_INSUFFICIENT_STORAGE => 'Insufficient Storage', // WebDAV; RFC 4918
        self::STATUS_LOOP_DETECTED => 'Loop Detected', // WebDAV; RFC 5842
        self::STATUS_NOT_EXTENDED => 'Not Extended', // RFC 2774
        self::STATUS_NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required', // RFC 6585

    ];

    /**
     * Карта типов Mine.
     * @var array
     */
    protected static array $mimeTypeMap = [];

    /**
     * Информация о файле для отправки
     *
     * @var ?array
     */
    protected ?array $file = null;

    /**
     * Данные заголовка.
     *
     * @var array
     */
    protected array $headers = [];

    /**
     * Http статус.
     *
     * @var int
     */
    protected int $statusCode = 200;

    /**
     * Http причина.
     *
     * @var ?string
     */
    protected ?string $reasonPhrase = '';

    /**
     * Версия Http.
     *
     * @var string
     */
    protected string $version = '1.1';

    /**
     * Тело Http.
     *
     * @var string
     */
    protected string $body = '';

    /**
     * Конструктор ответа.
     *
     * @param int $status
     * @param array|null $headers
     * @param StreamInterface|string|null $body
     * @param string $version
     * @param string|null $reason
     */
    public function __construct(
        int                    $status = 200,
        ?array                 $headers = [],
        StreamInterface|string $body = null,
        string                 $version = '1.1',
        string                 $reason = null
    )
    {
        $this->statusCode = $status;

        if ($body !== '' && $body !== null) {
            $this->stream = PSRUtil::stream_for($body);
        }

        $this->withHeaders($headers);
        if ($reason == '' && isset(self::$phrases[$this->statusCode])) {
            $this->reasonPhrase = self::$phrases[$this->statusCode];
        } else {
            $this->reasonPhrase = (string)$reason;
        }

        $this->protocol = $version;
    }

    /**
     * Инициализация.
     *
     * @return void
     */
    public static function init(): void
    {
        static::initMimeTypeMap();
    }

    /**
     * Инициализация карты MIME-типов.
     *
     * @return void
     */
    public static function initMimeTypeMap(): void
    {
        $mimeFile = __DIR__ . '/mime.types';
        $items = file($mimeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mimeType = $match[1];
                $extensionVar = $match[2];
                $extensionArray = explode(' ', substr($extensionVar, 0, -1));
                foreach ($extensionArray as $fileExtension) {
                    static::$mimeTypeMap[$fileExtension] = $mimeType;
                }
            }
        }
    }

    /**
     * Установить заголовок.
     *
     * @param string $name
     * @param string $value
     * @return Response
     */
    public function withHeader(string $name, string $value): static
    {
        return $this->header($name, $value);
    }

    /**
     * Установить заголовок.
     *
     * @param string $name
     * @param array|string $value
     * @return Response
     */
    public function header(string $name, array|string $value): static
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Установить заголовки.
     *
     * @param array $headers
     * @return Response
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
        return $this;
    }

    /**
     * Удалить заголовок.
     *
     * @param string $name
     * @return Response
     */
    public function withoutHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }

    /**
     * Получить заголовок.
     *
     * @param string $name
     * @return null|array|string
     */
    public function getHeader(string $name): array|string|null
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Получить заголовки.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Получить код статуса.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Получить причину фразы.
     *
     * @return string
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * Установить версию протокола.
     *
     * @param string $version
     * @return Response
     */
    public function withProtocolVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Получить HTTP-тело в исходном виде.
     *
     * @return string
     */
    public function rawBody(): string
    {
        return $this->body;
    }

    /**
     * Отправить файл.
     *
     * @param string $file
     * @param int $offset
     * @param int $length
     * @return Response
     */
    public function withFile(string $file, int $offset = 0, int $length = 0): static
    {
        if (!is_file($file)) {
            return $this->withStatus(404)->withBody('<h3>404 Не найдено</h3>');
        }
        $this->file = ['file' => $file, 'offset' => $offset, 'length' => $length];
        return $this;
    }

    /**
     * Установить HTTP-тело.
     *
     * @param string $body
     * @return Response
     */
    public function withBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Установить статус.
     *
     * @param int $code
     * @param string|null $reasonPhrase
     * @return Response
     */
    public function withStatus($code, $reasonPhrase = ''): Response|static
    {
        $new = clone $this;
        $new->statusCode = (int)$code;
        if ($reasonPhrase == '' && isset(self::$phrases[$new->statusCode])) {
            $reasonPhrase = self::$phrases[$new->statusCode];
        }
        $new->reasonPhrase = $reasonPhrase;
        return $new;
    }

    /**
     * Установить cookie.
     *
     * @param string $name
     * @param string $value
     * @param int|null $maxAge
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $httpOnly
     * @param string|null $sameSite
     * @return Response
     */
    public function cookie(string $name, string $value = '', ?int $maxAge = null, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, ?string $sameSite = null): static
    {
        $this->header('set-cookie', $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . ($maxAge === null ? '' : '; Max-Age=' . $maxAge)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$httpOnly ? '' : '; HttpOnly')
            . (empty($sameSite) ? '' : '; SameSite=' . $sameSite));
        return $this;
    }

    /**
     * __toString.
     *
     * @return string
     */
    public function __toString(): string
    {

        if ($this->file) {
            return $this->createHeadForFile($this->file);
        }

        $msg = 'HTTP/' . $this->getProtocolVersion() . ' '
            . $this->getStatusCode() . ' '
            . $this->getReasonPhrase()
            . "\r\nServer: Localzet Server " . Server::getVersion();


        $headers = $this->getHeaders();
        if (empty($headers)) {
            $msg .=
                "\r\nContent-Length: " . $this->getBody()->getSize() .
                "\r\nContent-Type: text/html;charset=utf-8" .
                "\r\nConnection: keep-alive";
        } else {
            if ('' === $this->getHeaderLine('Transfer-Encoding') &&
                '' === $this->getHeaderLine('Content-Length')) {
                $msg .= "\r\nContent-Length: " . $this->getBody()->getSize();
            }
            if ('' === $this->getHeaderLine('Content-Type')) {
                $msg .= "\r\nContent-Type: text/html;charset=utf-8";
            }
            if ('' === $this->getHeaderLine('Connection')) {
                $msg .= "\r\nConnection: keep-alive";
            }
        }

        foreach ($headers as $name => $values) {
            if (strtolower($name) == 'server') {
                continue;
            }
            $msg .= "\r\n$name: " . implode(', ', $values);
        }

        if ($this->getHeader('Content-Type') === 'text/event-stream') {
            return "$msg\r\n\r\n" . $this->getBody();
        }

        if ('' !== $this->getHeader('Transfer-Encoding')) {
            return $this->getBody()->getSize() ? "$msg\r\n" . dechex($this->getBody()->getSize()) . "\r\n" . $this->getBody() . "\r\n" : "$msg\r\n";
        }

        // Весь HTTP-пакет.
        return "$msg\r\n\r\n" . $this->getBody();
    }


    /**
     * Создать заголовок для файла.
     *
     * @param array $fileInfo
     * @return string
     */
    protected function createHeadForFile(array $fileInfo): string
    {
        $file = $fileInfo['file'];
        // Причина фразы.
        $reason = $this->reason ?: self::$phrases[$this->status];
        // Заголовок.
        $head = "HTTP/$this->version $this->status $reason\r\nServer: Localzet Server " . Server::getVersion() . "\r\n";

        foreach ($this->headers as $name => $value) {
            if (strtolower($name) == 'server') {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    // Заголовок.
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            // Заголовок.
            $head .= "$name: $value\r\n";
        }

        if (!$this->getHeader('connection')) {
            // Соединение.
            $head .= "Connection: keep-alive\r\n";
        }

        // Информация о файле.
        $fileInfo = pathinfo($file);
        // Расширение файла.
        $extension = $fileInfo['extension'] ?? '';
        // Базовое имя файла.
        $baseName = $fileInfo['basename'] ?: 'unknown';
        if (!$this->getHeader('content-type')) {
            if (isset(self::$mimeTypeMap[$extension])) {
                // Тип контента.
                $head .= "Content-Type: " . self::$mimeTypeMap[$extension] . "\r\n";
            } else {
                // Тип контента.
                $head .= "Content-Type: application/octet-stream\r\n";
            }
        }

        if (!$this->getHeader('content-disposition') && !isset(self::$mimeTypeMap[$extension])) {
            // Расположение контента.
            $head .= "Content-Disposition: attachment; filename=\"$baseName\"\r\n";
        }

        if (!$this->getHeader('last-modified') && $mtime = filemtime($file)) {
            // Последнее изменение.
            $head .= 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT' . "\r\n";
        }

        return "$head\r\n";
    }
}

Response::init();
