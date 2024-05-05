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
 *              For any questions, please contact <support@localzet.com>
 */

namespace localzet\Server\Protocols\Http;

use localzet\Server;
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
use function strlen;
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
        418 => 'I\'m a teapot', // RFC 2324, RFC 7168
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
     * @var array
     */
    protected static array $mimeTypeMap = [];

    /**
     * Информация о файле для отправки
     *
     * @var ?array
     */
    public ?array $file = null;

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
    protected int $status;

    /**
     * Http причина.
     *
     * @var ?string
     */
    protected ?string $reason = null;

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
     * @param string $body
     */
    public function __construct(
        int    $status = 200,
        ?array $headers = [],
        string $body = ''
    )
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers);
        $this->body = $body;
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
     * @param array|string|int $value
     * @return Response
     */
    public function header(string $name, mixed $value): static
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
        return $this->status;
    }

    /**
     * Получить причину фразы.
     *
     * @return ?string
     */
    public function getReasonPhrase(): ?string
    {
        return $this->reason;
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
    public function withStatus(int $code, string $reasonPhrase = null): static
    {
        $this->status = $code;
        $this->reason = $reasonPhrase;
        return $this;
    }

    /**
     * Установить cookie.
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

        // Причина фразы.
        $reason = $this->reason ?: self::PHRASES[$this->status] ?? '';
        // Длина тела.
        $bodyLen = strlen($this->body);
        if (empty($this->headers)) {
            return "HTTP/$this->version $this->status $reason\r\nServer: Localzet Server " . Server::getVersion() . "\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $bodyLen\r\nConnection: keep-alive\r\n\r\n$this->body";
        }

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

        if (!$this->getHeader('content-type')) {
            // Тип контента.
            $head .= "Content-Type: text/html;charset=utf-8\r\n";
        } else if ($this->getHeader('content-type') === 'text/event-stream') {
            return $head . $this->body;
        }

        if (!$this->getHeader('transfer-encoding')) {
            // Длина контента.
            $head .= "Content-Length: $bodyLen\r\n\r\n";
        } else {
            return $bodyLen ? "$head\r\n" . dechex($bodyLen) . "\r\n$this->body\r\n" : "$head\r\n";
        }

        // Весь HTTP-пакет.
        return $head . $this->body;
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
        $reason = $this->reason ?: self::PHRASES[$this->status];
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
