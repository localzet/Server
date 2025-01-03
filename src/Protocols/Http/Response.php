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

namespace localzet\Server\Protocols\Http;

use Stringable;
use function explode;
use function file;
use function filemtime;
use function gmdate;
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
class Response implements Stringable
{
    /**
     * Phrases.
     *
     * @var array<int,string>
     *
     * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
     */
    public const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // WebDAV; RFC 2518
        103 => 'Early Hints', // RFC 8297

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information', // since HTTP/1.1
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content', // RFC 7233
        207 => 'Multi-Status', // WebDAV; RFC 4918
        208 => 'Already Reported', // WebDAV; RFC 5842
        226 => 'IM Used', // RFC 3229

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // Previously "Moved temporarily"
        303 => 'See Other', // since HTTP/1.1
        304 => 'Not Modified', // RFC 7232
        305 => 'Use Proxy', // since HTTP/1.1
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect', // since HTTP/1.1
        308 => 'Permanent Redirect', // RFC 7538

        400 => 'Bad Request',
        401 => 'Unauthorized', // RFC 7235
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', // RFC 7235
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed', // RFC 7232
        413 => 'Payload Too Large', // RFC 7231
        414 => 'URI Too Long', // RFC 7231
        415 => 'Unsupported Media Type', // RFC 7231
        416 => 'Range Not Satisfiable', // RFC 7233
        417 => 'Expectation Failed',
        418 => "I'm a teapot", // RFC 2324, RFC 7168
        421 => 'Misdirected Request', // RFC 7540
        422 => 'Unprocessable Entity', // WebDAV; RFC 4918
        423 => 'Locked', // WebDAV; RFC 4918
        424 => 'Failed Dependency', // WebDAV; RFC 4918
        425 => 'Too Early', // RFC 8470
        426 => 'Upgrade Required',
        428 => 'Precondition Required', // RFC 6585
        429 => 'Too Many Requests', // RFC 6585
        431 => 'Request Header Fields Too Large', // RFC 6585
        451 => 'Unavailable For Legal Reasons', // RFC 7725

        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', // RFC 2295
        507 => 'Insufficient Storage', // WebDAV; RFC 4918
        508 => 'Loop Detected', // WebDAV; RFC 5842
        510 => 'Not Extended', // RFC 2774
        511 => 'Network Authentication Required', // RFC 6585
    ];

    /**
     * Карта типов Mine.
     */
    protected static array $mimeTypeMap = [];

    /**
     * Информация о файле для отправки
     */
    public ?array $file = null;

    /**
     * Данные заголовка.
     */
    protected array $headers = [];

    /**
     * Http причина.
     */
    protected ?string $reason = null;

    /**
     * Версия Http.
     */
    protected string $version = '1.1';

    /**
     * Конструктор ответа.
     */
    public function __construct(
        /**
         * Http статус.
         */
        protected int     $status = 200,
        array             $headers = [],
        /**
         * Тело Http.
         */
        protected ?string $body = ''
    )
    {
        $this->headers = array_change_key_case($headers);
    }

    /**
     * Инициализация.
     */
    public static function init(): void
    {
        static::initMimeTypeMap();
    }

    /**
     * Инициализация карты MIME-типов.
     */
    public static function initMimeTypeMap(): void
    {
        $mimeFile = __DIR__ . '/mime.types';
        $items = file($mimeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($items as $item) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $item, $match)) {
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
     */
    public function withHeader(string $name, string $value): static
    {
        return $this->header($name, $value);
    }

    /**
     * Установить заголовок.
     *
     * @param array|string|int $value
     */
    public function header(string $name, mixed $value): static
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /**
     * Установить заголовки.
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
     */
    public function withoutHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);
        return $this;
    }

    /**
     * Получить заголовок.
     */
    public function getHeader(string $name): array|string|null
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Получить заголовки.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Получить код статуса.
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * Получить причину фразы.
     */
    public function getReasonPhrase(): ?string
    {
        return $this->reason;
    }

    /**
     * Установить версию протокола.
     */
    public function withProtocolVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Получить HTTP-тело в исходном виде.
     */
    public function rawBody(): ?string
    {
        return $this->body;
    }

    /**
     * Отправить файл.
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
     */
    public function withBody(?string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Установить статус.
     */
    public function withStatus(int $code, ?string $reasonPhrase = null): static
    {
        $this->status = $code;
        $this->reason = $reasonPhrase;
        return $this;
    }

    /**
     * Установить cookie.
     * Установить cookie.
     */
    public function cookie(string $name, string $value = '', ?int $maxAge = null, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = ''): static
    {
        $this->header('set-cookie', $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . ($maxAge === null ? '' : '; Max-Age=' . $maxAge)
            . (empty($path) ? '' : '; Path=' . $path)
            . ($secure ? '; Secure' : '')
            . ($httpOnly ? '; HttpOnly' : '')
            . (empty($sameSite) ? '' : '; SameSite=' . $sameSite));
        return $this;
    }

    /**
     * __toString.
     */
    public function __toString(): string
    {
        // Если файл установлен, создаем заголовок для файла.
        if ($this->file) {
            return $this->createHeadForFile($this->file);
        }

        return format_http_response($this->status, $this->body, $this->headers, $this->reason, $this->version);
    }


    /**
     * Создать заголовок для файла.
     */
    protected function createHeadForFile(array $fileInfo): string
    {
        $file = $fileInfo['file'];

        // Получаем причину, если она не указана
        $reason ??= static::PHRASES[$this->status] ?? 'Unknown Status';

        // Формируем начальную строку заголовка
        $head = "HTTP/$this->version $this->status $reason\r\n";

        // Объединяем заголовки, добавляя стандартные значения
        $defaultHeaders = [
            'Server' => 'Localzet-Server',
            'Connection' => $this->headers['Connection'] ?? 'keep-alive',
        ];
        $headers = array_merge($defaultHeaders, $this->headers);

        // Формируем строку заголовков
        foreach ($headers as $name => $values) {
            foreach ((array)$values as $value) {
                $head .= "$name: $value\r\n";
            }
        }

        // Информация о файле.
        $fileInfo = pathinfo((string)$file);
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
