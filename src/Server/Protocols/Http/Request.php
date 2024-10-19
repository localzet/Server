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

namespace localzet\Server\Protocols\Http;

use Exception;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Protocols\Http;
use RuntimeException;
use Stringable;
use function array_walk_recursive;
use function bin2hex;
use function clearstatcache;
use function count;
use function explode;
use function file_put_contents;
use function is_file;
use function json_decode;
use function ltrim;
use function microtime;
use function pack;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function tempnam;
use function trim;
use function unlink;
use function urlencode;

/**
 * Класс Request
 * @property mixed|string $sid
 * @package localzet\Server\Protocols\Http
 */
class Request implements Stringable
{
    /**
     * Максимальное количество загружаемых файлов.
     */
    public static int $maxFileUploads = 1024;

    /**
     * Включить кэш.
     */
    protected static bool $enableCache = true;

    /**
     * Соединение.
     */
    public ?TcpConnection $connection = null;

    /**
     * Экземпляр сессии.
     */
    public ?Session $session = null;

    /**
     * Свойства.
     */
    public array $properties = [];

    /**
     * Данные запроса.
     */
    protected array $data = [];

    /**
     * Безопасно ли.
     */
    protected bool $isSafe = true;

    /**
     * Идентификатор сессии.
     *
     * @var mixed|string
     */
    protected mixed $sid;

    /**
     * Конструктор запроса.
     */
    public function __construct(
        /**
         * Буфер HTTP.
         */
        protected string $buffer
    )
    {
    }

    /**
     * Включить или отключить кэш.
     */
    public static function enableCache(bool $value): void
    {
        static::$enableCache = $value;
    }

    /**
     * Получить запрос.
     *
     * @param string|null $name
     * @param mixed|null $default
     */
    public function get(string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['get'])) {
            $this->parseGet();
        }

        if (null === $name) {
            return $this->data['get'];
        }

        return $this->data['get'][$name] ?? $default;
    }

    /**
     * Разобрать заголовок.
     */
    protected function parseGet(): void
    {
        static $cache = [];
        $queryString = $this->queryString();
        $this->data['get'] = [];
        if ($queryString === '') {
            return;
        }

        // Проверяем, можно ли использовать кэш и не превышает ли строка запроса 1024 символа.
        $cacheable = static::$enableCache && !isset($queryString[1024]);
        if ($cacheable && isset($cache[$queryString])) {
            // Если условие выполняется, используем данные из кэша.
            $this->data['get'] = $cache[$queryString];
            return;
        }

        // Если нет - парсим строку запроса и сохраняем результат в кэше.
        parse_str($queryString, $this->data['get']);
        if ($cacheable) {
            $cache[$queryString] = $this->data['get'];
            // Если размер кэша превышает 256, удаляем самый старый элемент кэша.
            if (count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Получить строку запроса.
     */
    public function queryString(): string
    {
        if (!isset($this->data['query_string'])) {
            $this->data['query_string'] = (string)parse_url($this->uri(), PHP_URL_QUERY);
        }

        return $this->data['query_string'];
    }

    /**
     * Получить URI.
     */
    public function uri(): string
    {
        if (!isset($this->data['uri'])) {
            $this->parseHeadFirstLine();
        }

        return $this->data['uri'];
    }

    /**
     * Разобрать первую строку буфера заголовка http.
     */
    protected function parseHeadFirstLine(): void
    {
        $firstLine = strstr($this->buffer, "\r\n", true);
        $tmp = explode(' ', $firstLine, 3);
        $this->data['method'] = $tmp[0];
        $this->data['uri'] = $tmp[1] ?? '/';
    }

    /**
     * Получить POST.
     *
     * @param string|null $name
     * @param mixed|null $default
     */
    public function post(string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['post'])) {
            $this->parsePost();
        }

        if (null === $name) {
            return $this->data['post'];
        }

        return $this->data['post'][$name] ?? $default;
    }


    /**
     * Получить ввод.
     *
     * @param mixed|null $default
     * @return mixed|null
     */
    public function input(string $name, mixed $default = null): mixed
    {
        $post = $this->post();
        if (isset($post[$name])) {
            return $post[$name];
        }

        $get = $this->get();
        return $get[$name] ?? $default;
    }

    /**
     * Получить только указанные ключи.
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        $result = [];
        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $result[$key] = $all[$key];
            }
        }

        return $result;
    }

    /**
     * Получить все данные из POST и GET.
     *
     * @return mixed|null
     */
    public function all(): mixed
    {
        return $this->post() + $this->get();
    }

    /**
     * Получить все данные, кроме указанных ключей.
     *
     * @return mixed|null
     */
    public function except(array $keys): mixed
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }

        return $all;
    }

    /**
     * Разбор POST.
     */
    protected function parsePost(): void
    {
        static $cache = [];
        $this->data['post'] = $this->data['files'] = [];
        $contentType = $this->header('content-type', '');
        if (preg_match('/boundary="?(\S+)"?/', (string)$contentType, $match)) {
            $httpPostBoundary = '--' . $match[1];
            $this->parseUploadFiles($httpPostBoundary);
            return;
        }

        $bodyBuffer = $this->rawBody();
        if ($bodyBuffer === '') {
            return;
        }

        $cacheable = static::$enableCache && !isset($bodyBuffer[1024]);
        if ($cacheable && isset($cache[$bodyBuffer])) {
            $this->data['post'] = $cache[$bodyBuffer];
            return;
        }

        if (preg_match('/\bjson\b/i', (string)$contentType)) {
            $this->data['post'] = (array)json_decode($bodyBuffer, true);
        } else {
            parse_str($bodyBuffer, $this->data['post']);
        }

        if ($cacheable) {
            $cache[$bodyBuffer] = $this->data['post'];
            if (count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Получить элемент заголовка по имени.
     *
     * @param string|null $name
     * @param mixed|null $default
     */
    public function header(string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }

        if (null === $name) {
            return $this->data['headers'];
        }

        $name = strtolower($name);
        return $this->data['headers'][$name] ?? $default;
    }


    /**
     * Разбор заголовков.
     */
    protected function parseHeaders(): void
    {
        static $cache = [];
        $this->data['headers'] = [];
        $rawHead = $this->rawHead();
        $endLinePosition = strpos($rawHead, "\r\n");
        if ($endLinePosition === false) {
            return;
        }

        $headBuffer = substr($rawHead, $endLinePosition + 2);
        $cacheable = static::$enableCache && !isset($headBuffer[4096]);
        if ($cacheable && isset($cache[$headBuffer])) {
            $this->data['headers'] = $cache[$headBuffer];
            return;
        }

        $headData = explode("\r\n", $headBuffer);
        foreach ($headData as $content) {
            if (str_contains($content, ':')) {
                [$key, $value] = explode(':', $content, 2);
                $key = strtolower($key);
                $value = ltrim($value);
            } else {
                $key = strtolower($content);
                $value = '';
            }

            if (isset($this->data['headers'][$key])) {
                $this->data['headers'][$key] .= ",$value";
            } else {
                $this->data['headers'][$key] = $value;
            }
        }

        if ($cacheable) {
            $cache[$headBuffer] = $this->data['headers'];
            if (count($cache) > 128) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Получить сырой HTTP-заголовок.
     */
    public function rawHead(): string
    {
        if (!isset($this->data['head'])) {
            $this->data['head'] = strstr($this->buffer, "\r\n\r\n", true);
        }

        return $this->data['head'];
    }

    /**
     * Разбор загруженных файлов.
     */
    protected function parseUploadFiles(string $httpPostBoundary): void
    {
        // Удаление кавычек из границы POST-запроса HTTP
        $httpPostBoundary = trim($httpPostBoundary, '"');

        // Буфер данных
        $buffer = $this->buffer;

        // Инициализация строк для кодирования POST-запроса и файлов
        $postEncodeString = '';
        $filesEncodeString = '';

        // Инициализация массива для файлов
        $files = [];

        // Позиция тела в буфере данных
        $bodayPosition = strpos($buffer, "\r\n\r\n") + 4;

        // Смещение от начала тела
        $offset = $bodayPosition + strlen($httpPostBoundary) + 2;

        // Максимальное количество загружаемых файлов
        $maxCount = static::$maxFileUploads;

        // Разбор каждого загруженного файла
        while ($maxCount-- > 0 && $offset) {
            // Разбор каждого загруженного файла и обновление смещения, строки кодирования POST-запроса и файлов
            $offset = $this->parseUploadFile($httpPostBoundary, $offset, $postEncodeString, $filesEncodeString, $files);
        }

        // Если есть строка кодирования POST-запроса, преобразовать ее в массив POST-запроса
        if ($postEncodeString) {
            parse_str((string)$postEncodeString, $this->data['post']);
        }

        // Если есть строка кодирования файлов, преобразовать ее в массив файлов
        if ($filesEncodeString) {
            parse_str((string)$filesEncodeString, $this->data['files']);

            // Обновление значений массива файлов ссылками на реальные файлы
            array_walk_recursive($this->data['files'], function (&$value) use ($files): void {
                $value = $files[$value];
            });
        }
    }

    /**
     * Разбор загруженного файла.
     *
     * @param $boundary
     * @param $sectionStartOffset
     * @param $postEncodeString
     * @param $filesEncodeStr
     * @param $files
     */
    protected function parseUploadFile($boundary, $sectionStartOffset, string &$postEncodeString, string &$filesEncodeStr, &$files): int
    {
        // Инициализация массива для файла
        $file = [];

        // Добавление символов перевода строки к границе
        $boundary = "\r\n$boundary";

        // Если длина буфера меньше смещения начала секции, вернуть 0
        if (strlen($this->buffer) < $sectionStartOffset) {
            return 0;
        }

        // Найти смещение конца секции по границе
        $sectionEndOffset = strpos($this->buffer, $boundary, $sectionStartOffset);

        // Если смещение конца секции не найдено, вернуть 0
        if (!$sectionEndOffset) {
            return 0;
        }

        // Найти смещение конца строк содержимого
        $contentLinesEndOffset = strpos($this->buffer, "\r\n\r\n", $sectionStartOffset);

        // Если смещение конца строк содержимого не найдено или оно больше смещения конца секции, вернуть 0
        if (!$contentLinesEndOffset || $contentLinesEndOffset + 4 > $sectionEndOffset) {
            return 0;
        }

        // Получить строки содержимого из буфера и разбить их на массив строк
        $contentLinesStr = substr($this->buffer, $sectionStartOffset, $contentLinesEndOffset - $sectionStartOffset);
        $contentLines = explode("\r\n", trim($contentLinesStr . "\r\n"));

        // Получить значение границы из буфера
        $boundaryValue = substr($this->buffer, $contentLinesEndOffset + 4, $sectionEndOffset - $contentLinesEndOffset - 4);

        // Инициализация ключа загрузки как false
        $uploadKey = false;

        // Обработка каждой строки содержимого
        foreach ($contentLines as $contentLine) {
            // Если в строке содержимого нет ': ', вернуть 0
            if (!strpos($contentLine, ': ')) {
                return 0;
            }

            // Разбить строку содержимого на ключ и значение по ': '
            [$key, $value] = explode(': ', $contentLine);

            // Обработка ключа в зависимости от его значения
            switch (strtolower($key)) {
                case "content-disposition":
                    // Это данные файла.
                    if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                        // Инициализация ошибки как 0 и временного файла как пустой строки
                        $error = 0;
                        $tmpFile = '';

                        // Получение имени файла из регулярного выражения
                        $fileName = $match[1];

                        // Получение размера значения границы
                        $size = strlen($boundaryValue);

                        // Получение временного каталога для загрузки HTTP
                        $tmpUploadDir = HTTP::uploadTmpDir();

                        // Если временный каталог для загрузки HTTP не найден, установить ошибку в UPLOAD_ERR_NO_TMP_DIR
                        if (!$tmpUploadDir) {
                            $error = UPLOAD_ERR_NO_TMP_DIR;
                        } elseif ($boundaryValue === '' && $fileName === '') {
                            $error = UPLOAD_ERR_NO_FILE;
                        } else {
                            $tmpFile = tempnam($tmpUploadDir, 'localzet.upload.');
                            if ($tmpFile === false || false === file_put_contents($tmpFile, $boundaryValue)) {
                                $error = UPLOAD_ERR_CANT_WRITE;
                            }
                        }

                        // Установить ключ загрузки в имя файла
                        $uploadKey = $fileName;

                        // Добавить данные файла в массив файла
                        $file = [...$file, 'name' => $match[2], 'tmp_name' => $tmpFile, 'size' => $size, 'error' => $error, 'full_path' => $match[2]];

                        // Если тип файла не установлен, установить его в пустую строку
                        if (!isset($file['type'])) {
                            $file['type'] = '';
                        }

                        break;
                    }

                    // Это поле POST.
                    // Разбор $POST.
                    if (preg_match('/name="(.*?)"$/', $value, $match)) {
                        // Получить ключ из регулярного выражения
                        $k = $match[1];

                        // Добавить ключ и значение границы в строку кодирования POST-запроса
                        $postEncodeString .= urlencode($k) . "=" . urlencode($boundaryValue) . '&';
                    }

                    // Вернуть смещение конца секции плюс длина границы плюс 2
                    return $sectionEndOffset + strlen($boundary) + 2;

                case "content-type":
                    // Установить тип файла в значение
                    $file['type'] = trim($value);
                    break;

                case "webkitrelativepath":
                    // Установить полный путь файла в значение
                    $file['full_path'] = trim($value);
                    break;
            }
        }

        // Если ключ загрузки все еще false, вернуть 0
        if ($uploadKey === false) {
            return 0;
        }

        // Добавить ключ загрузки и количество файлов в строку кодирования файлов
        $filesEncodeStr .= urlencode($uploadKey) . '=' . count($files) . '&';

        // Добавить файл в массив файлов
        $files[] = $file;

        // Вернуть смещение конца секции плюс длина границы плюс 2
        return $sectionEndOffset + strlen($boundary) + 2;
    }

    /**
     * Получить сырое тело HTTP.
     */
    public function rawBody(): string
    {
        return substr($this->buffer, strpos($this->buffer, "\r\n\r\n") + 4);
    }

    /**
     * Получить загруженные файлы.
     *
     * @param string|null $name
     * @return array|null
     */
    public function file(string $name = null): mixed
    {
        // Если файлы не установлены, разобрать POST-запрос
        if (!isset($this->data['files'])) {
            $this->parsePost();
        }

        // Если имя не указано, вернуть все файлы, иначе вернуть файл с указанным именем или null, если он не найден
        return $name === null ? $this->data['files'] : $this->data['files'][$name] ?? null;
    }

    /**
     * Получить URL.
     */
    public function url(): string
    {
        // Вернуть URL, состоящий из хоста и пути
        return '//' . $this->host() . $this->path();
    }

    /**
     * Получить полный URL.
     */
    public function fullUrl(): string
    {
        // Вернуть полный URL, состоящий из хоста и URI
        return '//' . $this->host() . $this->uri();
    }

    /**
     * Ожидает ли запрос JSON.
     */
    public function expectsJson(): bool
    {
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson();
    }

    /**
     * Принимает ли запрос любой тип контента.
     */
    public function acceptsAnyContentType(): bool
    {
        if (!isset($this->data['accept'])) {
            $this->parseAcceptHeader();
        }

        return array_key_exists('*/*', $this->data['accept']) || array_key_exists('*', $this->data['accept']);
    }

    /**
     * Парсит заголовок Accept.
     */
    public function parseAcceptHeader(): void
    {
        $accepts = explode(',', (string)$this->header('Accept', ''));
        $this->data['accept'] = [];

        foreach ($accepts as $accept) {
            $parts = explode(';', $accept);
            $media_type = trim(array_shift($parts));
            $params = [];

            foreach ($parts as $part) {
                [$name, $value] = explode('=', $part);
                $params[trim($name)] = trim($value);
            }

            $this->data['accept'][$media_type] = $params;
        }
    }

    /**
     * Проверяет, является ли тип контента JSON.
     */
    public function isJson(): bool
    {
        return str_contains((string)$this->header('Content-Type', ''), '/json')
            || str_contains((string)$this->header('Content-Type', ''), '+json');
    }

    /**
     * Является ли запрос AJAX-запросом.
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With', '') === 'XMLHttpRequest';
    }

    /**
     * Является ли запрос PJAX-запросом.
     */
    public function isPjax(): bool
    {
        return (bool)$this->header('X-PJAX', false);
    }

    /**
     * Принимает ли запрос JSON.
     */
    public function acceptJson(): bool
    {
        return str_contains((string)$this->header('Accept', ''), '/json')
            || str_contains((string)$this->header('Accept', ''), '+json')
            || $this->acceptsAnyContentType();
    }

    /**
     * Получить метод.
     */
    public function method(): string
    {
        // Если метод не установлен, разобрать первую строку заголовка
        if (!isset($this->data['method'])) {
            $this->parseHeadFirstLine();
        }

        // Вернуть метод
        return $this->data['method'];
    }

    /**
     * Проверяет, является ли метод запроса указанным методом.
     */
    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    /**
     * Получить версию протокола HTTP.
     */
    public function protocolVersion(): string
    {
        // Если версия протокола не установлена, разобрать версию протокола
        if (!isset($this->data['protocolVersion'])) {
            $this->parseProtocolVersion();
        }

        // Вернуть версию протокола HTTP
        return $this->data['protocolVersion'];
    }

    /**
     * Разбор версии протокола.
     */
    protected function parseProtocolVersion(): void
    {
        // Получить первую строку из буфера данных
        $firstLine = strstr($this->buffer, "\r\n", true);

        // Получить версию протокола из первой строки
        $protocolVersion = substr(strstr($firstLine, 'HTTP/'), 5);

        // Установить версию протокола в данные или '1.0', если она не найдена
        $this->data['protocolVersion'] = $protocolVersion ?: '1.0';
    }

    /**
     * Получить хост.
     */
    public function host(bool $withoutPort = false): ?string
    {
        // Получить хост из заголовка 'host'
        $host = $this->header('host', '');

        // Если хост установлен и без порта, вернуть хост без порта, иначе вернуть хост
        return $host && $withoutPort ? preg_replace('/:\d{1,5}$/', '', (string)$host) : $host;
    }

    /**
     * Получить путь.
     */
    public function path(): string
    {
        // Если путь не установлен, установить его в путь URI из буфера данных
        if (!isset($this->data['path'])) {
            $this->data['path'] = (string)parse_url($this->uri(), PHP_URL_PATH);
        }

        // Вернуть путь
        return $this->data['path'];
    }

    /**
     * Сгенерировать новый идентификатор сессии.
     *
     * @throws Exception
     */
    public function sessionRegenerateId(bool $deleteOldSession = false): string
    {
        // Получить сессию и все ее данные
        $session = $this->session();
        $sessionData = $session->all();

        // Если старая сессия должна быть удалена, очистить ее
        if ($deleteOldSession) {
            $session->flush();
        }

        // Создать новый идентификатор сессии
        $newSid = static::createSessionId();

        // Создать новую сессию с новым идентификатором и установить в нее данные старой сессии
        $session = new Session($newSid);
        $session->put($sessionData);

        // Получить параметры cookie сессии и имя сессии
        $cookieParams = Session::getCookieParams();
        $sessionName = Session::$name;

        // Установить cookie с идентификатором сессии
        $this->setSidCookie($sessionName, $newSid, $cookieParams);

        // Вернуть новый идентификатор сессии
        return $newSid;
    }

    /**
     * Получить сессию.
     *
     * @throws Exception
     */
    public function session(): Session
    {
        // Если сессия не установлена, создать новую сессию с идентификатором сессии
        if (!$this->session instanceof Session) {
            $this->session = new Session($this->sessionId());
        }

        // Вернуть сессию
        return $this->session;
    }

    /**
     * Получить/установить идентификатор сессии.
     *
     * @param string|null $sessionId
     * @throws Exception
     */
    public function sessionId(string $sessionId = null): string
    {
        // Если идентификатор сессии указан, удалить текущий идентификатор сессии
        if ($sessionId) {
            unset($this->sid);
        }

        // Если идентификатор сессии не установлен, получить его из cookie или создать новый
        if ($this->sid === null) {
            // Получить имя сессии
            $sessionName = Session::$name;

            // Получить идентификатор сессии из cookie или создать новый, если он не указан или равен пустой строке
            $sid = $sessionId ? '' : $this->cookie($sessionName);
            if ($sid === '' || $sid === null) {
                // Если соединение не установлено, выбросить исключение
                if (!$this->connection instanceof TcpConnection) {
                    throw new RuntimeException('Request->session() fail, header already send');
                }

                // Создать новый идентификатор сессии, если он не указан
                $sid = $sessionId ?: static::createSessionId();

                // Получить параметры cookie сессии и установить cookie с идентификатором сессии
                $cookieParams = Session::getCookieParams();
                $this->setSidCookie($sessionName, $sid, $cookieParams);
            }

            // Установить идентификатор сессии
            $this->sid = $sid;
        }

        // Вернуть идентификатор сессии
        return $this->sid;
    }

    /**
     * Получить элемент cookie по имени.
     *
     * @param string|null $name
     * @param mixed|null $default
     */
    public function cookie(string $name = null, mixed $default = null): mixed
    {
        // Если cookie не установлены, получить их из заголовка 'cookie' и разобрать в массив
        if (!isset($this->data['cookie'])) {
            $this->data['cookie'] = [];
            parse_str((string)preg_replace('/; ?/', '&', (string)$this->header('cookie', '')), $this->data['cookie']);
        }

        // Если имя не указано, вернуть все cookie, иначе вернуть cookie с указанным именем или значение по умолчанию, если он не найден
        return $name === null ? $this->data['cookie'] : $this->data['cookie'][$name] ?? $default;
    }

    /**
     * Создать идентификатор сессии.
     *
     * @throws Exception
     */
    public static function createSessionId(): string
    {
        // Вернуть двоичное представление текущего времени в микросекундах и 8 случайных байтов в шестнадцатеричном виде
        return bin2hex(pack('d', microtime(true)) . random_bytes(8));
    }

    /**
     * Установить cookie с идентификатором сессии.
     */
    protected function setSidCookie(string $sessionName, string $sid, array $cookieParams): void
    {
        // Если соединение не установлено, выбросить исключение
        if (!$this->connection instanceof TcpConnection) {
            throw new RuntimeException('Request->setSidCookie() fail, header already send');
        }

        // Установить заголовок 'Set-Cookie' с идентификатором сессии и параметрами cookie сессии
        $this->connection->headers['Set-Cookie'] = [$sessionName . '=' . $sid
            . (empty($cookieParams['domain']) ? '' : '; Domain=' . $cookieParams['domain'])
            . (empty($cookieParams['lifetime']) ? '' : '; Max-Age=' . $cookieParams['lifetime'])
            . (empty($cookieParams['path']) ? '' : '; Path=' . $cookieParams['path'])
            . (empty($cookieParams['samesite']) ? '' : '; SameSite=' . $cookieParams['samesite'])
            . ($cookieParams['secure'] ? '; Secure' : '')
            . ($cookieParams['httponly'] ? '; HttpOnly' : '')];
    }

    /**
     * Получить сырой буфер.
     */
    public function rawBuffer(): string
    {
        // Вернуть буфер
        return $this->buffer;
    }

    /**
     * Получить локальный IP-адрес.
     */
    public function getLocalIp(): string
    {
        // Вернуть локальный IP-адрес из соединения
        return $this->connection->getLocalIp();
    }

    /**
     * Получить локальный порт.
     */
    public function getLocalPort(): int
    {
        // Вернуть локальный порт из соединения
        return $this->connection->getLocalPort();
    }

    /**
     * Получить удаленный IP-адрес.
     */
    public function getRemoteIp(): string
    {
        // Вернуть удаленный IP-адрес из соединения
        return $this->connection->getRemoteIp();
    }

    /**
     * Получить удаленный порт.
     */
    public function getRemotePort(): int
    {
        // Вернуть удаленный порт из соединения
        return $this->connection->getRemotePort();
    }

    /**
     * Получить соединение.
     */
    public function getConnection(): TcpConnection
    {
        // Вернуть соединение
        return $this->connection;
    }

    public function toArray(): array
    {
        $return = $this->properties +
            [
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

        if ($this->connection instanceof TcpConnection) {
            $return += [
                'localIp' => $this->getLocalIp(),
                'localPort' => $this->getLocalPort(),
                'remoteIp' => $this->getRemoteIp(),
                'remotePort' => $this->getRemotePort(),
            ];
        }

        return $return;
    }

    /**
     * __toString.
     */
    public function __toString(): string
    {
        // Вернуть буфер
        return $this->buffer;
    }

    /**
     * Getter.
     *
     * @return mixed|null
     */
    public function __get(string $name)
    {
        // Вернуть свойство с указанным именем или null, если оно не найдено
        return $this->properties[$name] ?? null;
    }

    /**
     * Setter.
     *
     * @return void
     */
    public function __set(string $name, mixed $value)
    {
        // Установить свойство с указанным именем в указанное значение
        $this->properties[$name] = $value;
    }

    /**
     * Isset.
     *
     * @return bool
     */
    public function __isset(string $name)
    {
        // Вернуть true, если свойство с указанным именем установлено, иначе вернуть false
        return isset($this->properties[$name]);
    }

    /**
     * Unset.
     *
     * @return void
     */
    public function __unset(string $name)
    {
        // Удалить свойство с указанным именем
        unset($this->properties[$name]);
    }

    /**
     * __wakeup.
     *
     * @return void
     */
    public function __wakeup()
    {
        // Установить безопасность в false
        $this->isSafe = false;
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        // Если файлы установлены и безопасность включена, очистить кэш статуса файла и удалить временные файлы
        if (isset($this->data['files']) && $this->isSafe) {
            // Очистить кэш статуса файла
            clearstatcache();

            // Обойти все файлы рекурсивно и удалить временные файлы
            array_walk_recursive($this->data['files'], function ($value, $key): void {
                // Если ключ равен 'tmp_name' и значение является файлом, удалить файл
                if ($key === 'tmp_name' && is_file($value)) {
                    unlink($value);
                }
            });
        }
    }
}
