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
     *
     * @var int
     */
    public static int $maxFileUploads = 1024;
    /**
     * Включить кэш.
     *
     * @var bool
     */
    protected static bool $enableCache = true;
    /**
     * Соединение.
     *
     * @var ?TcpConnection
     */
    public ?TcpConnection $connection = null;
    /**
     * Экземпляр сессии.
     *
     * @var ?Session
     */
    public ?Session $session = null;
    /**
     * Свойства.
     *
     * @var array
     */
    public array $properties = [];
    /**
     * Буфер HTTP.
     *
     * @var string
     */
    protected string $buffer;
    /**
     * Данные запроса.
     *
     * @var array
     */
    protected array $data = [];
    /**
     * Безопасно ли.
     *
     * @var bool
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
     *
     * @param string $buffer
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * Включить или отключить кэш.
     *
     * @param bool $value
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
     * @return mixed
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
     *
     * @return void
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
     *
     * @return string
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
     *
     * @return string
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
     *
     * @return void
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
     * @return mixed
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
     * @param string $name
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
     *
     * @param array $keys
     * @return array
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
     * @param array $keys
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
     *
     * @return void
     */
    protected function parsePost(): void
    {
        static $cache = [];
        $this->data['post'] = $this->data['files'] = [];
        $contentType = $this->header('content-type', '');
        if (preg_match('/boundary="?(\S+)"?/', $contentType, $match)) {
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
        if (preg_match('/\bjson\b/i', $contentType)) {
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
     * @return mixed
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
     *
     * @return void
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
     *
     * @return string
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
     *
     * @param string $httpPostBoundary
     * @return void
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
            parse_str($postEncodeString, $this->data['post']);
        }

        // Если есть строка кодирования файлов, преобразовать ее в массив файлов
        if ($filesEncodeString) {
            parse_str($filesEncodeString, $this->data['files']);

            // Обновление значений массива файлов ссылками на реальные файлы
            array_walk_recursive($this->data['files'], function (&$value) use ($files) {
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
     * @return int
     */
    protected function parseUploadFile($boundary, $sectionStartOffset, &$postEncodeString, &$filesEncodeStr, &$files): int
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
                        } // Иначе если значение границы и имя файла пустые, установить ошибку в UPLOAD_ERR_NO_FILE
                        else if ($boundaryValue === '' && $fileName === '') {
                            $error = UPLOAD_ERR_NO_FILE;
                        }
                        // Иначе создать временный файл во временном каталоге для загрузки HTTP и записать в него значение границы,
                        // если создание временного файла или запись в него не удалась, установить ошибку в UPLOAD_ERR_CANT_WRITE
                        else {
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
     *
     * @return string
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
     *
     * @return string
     */
    public function url(): string
    {
        // Вернуть URL, состоящий из хоста и пути
        return '//' . $this->host() . $this->path();
    }

    /**
     * Получить полный URL.
     *
     * @return string
     */
    public function fullUrl(): string
    {
        // Вернуть полный URL, состоящий из хоста и URI
        return '//' . $this->host() . $this->uri();
    }

    /**
     * Ожидает ли запрос JSON.
     *
     * @return bool
     */
    public function expectsJson(): bool
    {
        // Вернуть true, если запрос является AJAX-запросом и не является PJAX-запросом, или принимает JSON, или метод не равен GET
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson() || strtoupper($this->method()) != 'GET';
    }

    /**
     * Является ли запрос AJAX-запросом.
     *
     * @return bool
     */
    public function isAjax(): bool
    {
        // Вернуть true, если заголовок 'X-Requested-With' равен 'XMLHttpRequest'
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Является ли запрос PJAX-запросом.
     *
     * @return bool
     */
    public function isPjax(): bool
    {
        // Вернуть true, если заголовок 'X-PJAX' установлен
        return (bool)$this->header('X-PJAX');
    }

    /**
     * Принимает ли запрос JSON.
     *
     * @return bool
     */
    public function acceptJson(): bool
    {
        // Вернуть true, если заголовок 'accept' содержит 'json'
        return str_contains($this->header('accept', ''), 'json');
    }

    /**
     * Получить метод.
     *
     * @return string
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
     * Получить версию протокола HTTP.
     *
     * @return string
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
     *
     * @return void
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
     *
     * @param bool $withoutPort
     * @return string|null
     */
    public function host(bool $withoutPort = false): ?string
    {
        // Получить хост из заголовка 'host'
        $host = $this->header('host');

        // Если хост установлен и без порта, вернуть хост без порта, иначе вернуть хост
        return $host && $withoutPort ? preg_replace('/:\d{1,5}$/', '', $host) : $host;
    }

    /**
     * Получить путь.
     *
     * @return string
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
     * @param bool $deleteOldSession
     * @return string
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
     * @return Session
     * @throws Exception
     */
    public function session(): Session
    {
        // Если сессия не установлена, создать новую сессию с идентификатором сессии
        if ($this->session === null) {
            $this->session = new Session($this->sessionId());
        }

        // Вернуть сессию
        return $this->session;
    }

    /**
     * Получить/установить идентификатор сессии.
     *
     * @param string|null $sessionId
     * @return string
     * @throws Exception
     */
    public function sessionId(string $sessionId = null): string
    {
        // Если идентификатор сессии указан, удалить текущий идентификатор сессии
        if ($sessionId) {
            unset($this->sid);
        }

        // Если идентификатор сессии не установлен, получить его из cookie или создать новый
        if (!isset($this->sid)) {
            // Получить имя сессии
            $sessionName = Session::$name;

            // Получить идентификатор сессии из cookie или создать новый, если он не указан или равен пустой строке
            $sid = $sessionId ? '' : $this->cookie($sessionName);
            if ($sid === '' || $sid === null) {
                // Если соединение не установлено, выбросить исключение
                if (!$this->connection) {
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
     * @return mixed
     */
    public function cookie(string $name = null, mixed $default = null): mixed
    {
        // Если cookie не установлены, получить их из заголовка 'cookie' и разобрать в массив
        if (!isset($this->data['cookie'])) {
            $this->data['cookie'] = [];
            parse_str(preg_replace('/; ?/', '&', $this->header('cookie', '')), $this->data['cookie']);
        }

        // Если имя не указано, вернуть все cookie, иначе вернуть cookie с указанным именем или значение по умолчанию, если он не найден
        return $name === null ? $this->data['cookie'] : $this->data['cookie'][$name] ?? $default;
    }

    /**
     * Создать идентификатор сессии.
     *
     * @return string
     * @throws Exception
     */
    public static function createSessionId(): string
    {
        // Вернуть двоичное представление текущего времени в микросекундах и 8 случайных байтов в шестнадцатеричном виде
        return bin2hex(pack('d', microtime(true)) . random_bytes(8));
    }

    /**
     * Установить cookie с идентификатором сессии.
     *
     * @param string $sessionName
     * @param string $sid
     * @param array $cookieParams
     * @return void
     */
    protected function setSidCookie(string $sessionName, string $sid, array $cookieParams): void
    {
        // Если соединение не установлено, выбросить исключение
        if (!$this->connection) {
            throw new RuntimeException('Request->setSidCookie() fail, header already send');
        }

        // Установить заголовок 'Set-Cookie' с идентификатором сессии и параметрами cookie сессии
        $this->connection->headers['Set-Cookie'] = [$sessionName . '=' . $sid
            . (empty($cookieParams['domain']) ? '' : '; Domain=' . $cookieParams['domain'])
            . (empty($cookieParams['lifetime']) ? '' : '; Max-Age=' . $cookieParams['lifetime'])
            . (empty($cookieParams['path']) ? '' : '; Path=' . $cookieParams['path'])
            . (empty($cookieParams['samesite']) ? '' : '; SameSite=' . $cookieParams['samesite'])
            . (!$cookieParams['secure'] ? '' : '; Secure')
            . (!$cookieParams['httponly'] ? '' : '; HttpOnly')];
    }

    /**
     * Получить сырой буфер.
     *
     * @return string
     */
    public function rawBuffer(): string
    {
        // Вернуть буфер
        return $this->buffer;
    }

    /**
     * Получить локальный IP-адрес.
     *
     * @return string
     */
    public function getLocalIp(): string
    {
        // Вернуть локальный IP-адрес из соединения
        return $this->connection->getLocalIp();
    }

    /**
     * Получить локальный порт.
     *
     * @return int
     */
    public function getLocalPort(): int
    {
        // Вернуть локальный порт из соединения
        return $this->connection->getLocalPort();
    }

    /**
     * Получить удаленный IP-адрес.
     *
     * @return string
     */
    public function getRemoteIp(): string
    {
        // Вернуть удаленный IP-адрес из соединения
        return $this->connection->getRemoteIp();
    }

    /**
     * Получить удаленный порт.
     *
     * @return int
     */
    public function getRemotePort(): int
    {
        // Вернуть удаленный порт из соединения
        return $this->connection->getRemotePort();
    }

    /**
     * Получить соединение.
     *
     * @return TcpConnection
     */
    public function getConnection(): TcpConnection
    {
        // Вернуть соединение
        return $this->connection;
    }

    public function toArray(): array
    {
        return $this->properties +
            [
                'localIp' => $this->getLocalIp(),
                'localPort' => $this->getLocalPort(),
                'remoteIp' => $this->getRemoteIp(),
                'remotePort' => $this->getRemotePort(),

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
     * @param string $name
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
     * @param string $name
     * @param mixed $value
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
     * @param string $name
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
     * @param string $name
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
            array_walk_recursive($this->data['files'], function ($value, $key) {
                // Если ключ равен 'tmp_name' и значение является файлом, удалить файл
                if ($key === 'tmp_name' && is_file($value)) {
                    unlink($value);
                }
            });
        }
    }
}
