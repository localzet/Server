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
use InvalidArgumentException;
use localzet\Server\Protocols\Http\Session\{FileSessionHandler, SessionHandlerInterface};
use function array_key_exists;
use function ini_get;
use function is_array;
use function is_scalar;
use function preg_match;
use function random_int;
use function serialize;
use function session_get_cookie_params;
use function unserialize;


/**
 * Класс Session
 * @package localzet\Server\Protocols\Http
 */
class Session
{
    /**
     * Имя сессии.
     *
     * @var string
     */
    public static string $name = 'PHPSID';

    /**
     * Автоматическое обновление метки времени.
     *
     * @var bool
     */
    public static bool $autoUpdateTimestamp = false;

    /**
     * Время жизни сессии.
     *
     * @var int
     */
    public static int $lifetime = 1440;

    /**
     * Время жизни cookie.
     *
     * @var int
     */
    public static int $cookieLifetime = 1440;

    /**
     * Путь к cookie сессии.
     *
     * @var string
     */
    public static string $cookiePath = '/';

    /**
     * Домен cookie сессии.
     *
     * @var string
     */
    public static string $domain = '';

    /**
     * Только HTTPS cookie.
     *
     * @var bool
     */
    public static bool $secure = false;

    /**
     * Только HTTP доступ.
     *
     * @var bool
     */
    public static bool $httpOnly = true;

    /**
     * Same-site cookies.
     *
     * @var string
     */
    public static string $sameSite = '';

    /**
     * Вероятность выполнения сборки мусора.
     *
     * @var int[]
     */
    public static array $gcProbability = [1, 20000];

    /**
     * Класс обработчика сессий, реализующий интерфейс SessionHandlerInterface.
     *
     * @var string
     */
    protected static string $handlerClass = FileSessionHandler::class;

    /**
     * Параметры конструктора для класса обработчика сессий.
     *
     * @var mixed
     */
    protected static mixed $handlerConfig = null;

    /**
     * Экземпляр обработчика сессий.
     *
     * @var ?SessionHandlerInterface
     */
    protected static ?SessionHandlerInterface $handler = null;

    /**
     * Данные сессии.
     *
     * @var array
     */
    protected mixed $data = [];

    /**
     * Безопасность данных.
     *
     * @var bool
     */
    protected bool $isSafe = true;

    /**
     * Флаг изменения данных сессии, требующий сохранения.
     *
     * @var bool
     */
    protected bool $needSave = false;

    /**
     * Идентификатор сессии.
     *
     * @var string
     */
    protected string $sessionId;

    /**
     * Конструктор сессии.
     */
    public function __construct(string $sessionId)
    {
        // Проверяем идентификатор сессии.
        static::checkSessionId($sessionId);
        // Если обработчик еще не инициализирован, инициализируем его.
        if (static::$handler === null) {
            static::initHandler();
        }
        // Устанавливаем идентификатор сессии.
        $this->sessionId = $sessionId;
        // Если есть данные, читаем их из обработчика и десериализуем.
        if ($data = static::$handler->read($sessionId)) {
            $this->data = unserialize($data);
        }
    }

    /**
     * Проверка идентификатора сессии.
     */
    protected static function checkSessionId(string $sessionId): void
    {
        // Если идентификатор сессии пуст, выбрасываем исключение.
        if ($sessionId === '') {
            throw new InvalidArgumentException('Идентификатор сессии не может быть пустым.');
        }
        // Если формат идентификатора сессии неверный, выбрасываем исключение.
        if (!preg_match('/^[0-9a-zA-Z,-]{22,40}$/', $sessionId)) {
            throw new InvalidArgumentException('Неверный формат идентификатора сессии.');
        }
    }

    /**
     * Инициализация обработчика.
     *
     * @return void
     */
    protected static function initHandler(): void
    {
        // Если конфигурация обработчика не установлена, создаем новый экземпляр обработчика.
        if (static::$handlerConfig === null) {
            static::$handler = new static::$handlerClass();
        } else {
            // В противном случае создаем новый экземпляр обработчика с конфигурацией.
            static::$handler = new static::$handlerClass(static::$handlerConfig);
        }
    }

    /**
     * Инициализация.
     *
     * @return void
     */
    public static function init(): void
    {
        // Если в конфигурации PHP установлены вероятность и делитель сборки мусора, используем их.
        if (($gcProbability = (int)ini_get('session.gc_probability')) && ($gcDivisor = (int)ini_get('session.gc_divisor'))) {
            static::$gcProbability = [$gcProbability, $gcDivisor];
        }

        // Если в конфигурации PHP установлено максимальное время жизни сессии, используем его.
        if ($gcMaxLifeTime = ini_get('session.gc_maxlifetime')) {
            self::$lifetime = (int)$gcMaxLifeTime;
        }

        // Получаем параметры cookie сессии из конфигурации PHP.
        $sessionCookieParams = session_get_cookie_params();
        static::$cookieLifetime = $sessionCookieParams['lifetime'];
        static::$cookiePath = $sessionCookieParams['path'];
        static::$domain = $sessionCookieParams['domain'];
        static::$secure = $sessionCookieParams['secure'];
        static::$httpOnly = $sessionCookieParams['httponly'];
    }

    /**
     * Установить класс обработчика сессии.
     *
     * @param mixed|null $className
     * @param mixed|null $config
     * @return string
     */
    public static function handlerClass(mixed $className = null, mixed $config = null): string
    {
        if ($className) {
            static::$handlerClass = $className;
        }
        if ($config) {
            static::$handlerConfig = $config;
        }
        return static::$handlerClass;
    }

    /**
     * Получить параметры cookie.
     *
     * @return array
     */
    public static function getCookieParams(): array
    {
        return [
            'lifetime' => static::$cookieLifetime,
            'path' => static::$cookiePath,
            'domain' => static::$domain,
            'secure' => static::$secure,
            'httponly' => static::$httpOnly,
            'samesite' => static::$sameSite,
        ];
    }

    /**
     * Получить и удалить элемент из сессии.
     *
     * @param mixed|null $default
     * @return mixed
     */
    public function pull(string $name, mixed $default = null): mixed
    {
        $value = $this->get($name, $default);
        $this->delete($name);
        return $value;
    }

    /**
     * Получить данные сессии.
     *
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    /**
     * Удалить элемент из сессии.
     */
    public function delete(string $name): void
    {
        unset($this->data[$name]);
        $this->needSave = true;
    }

    /**
     * Сохранить данные в сессии.
     *
     * @param mixed|null $value
     */
    public function put(array|string $key, mixed $value = null): void
    {
        if (!is_array($key)) {
            $this->set($key, $value);
            return;
        }

        foreach ($key as $k => $v) {
            $this->data[$k] = $v;
        }
        $this->needSave = true;
    }

    /**
     * Сохранить данные в сессии.
     */
    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
        $this->needSave = true;
    }

    /**
     * Удалить данные из сессии.
     */
    public function forget(array|string $name): void
    {
        if (is_scalar($name)) {
            $this->delete($name);
            return;
        }
        if (is_array($name)) {
            foreach ($name as $key) {
                unset($this->data[$key]);
            }
        }
        $this->needSave = true;
    }

    /**
     * Получить все данные сессии.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Удалить все данные из сессии.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->needSave = true;
        $this->data = [];
    }

    /**
     * Проверить наличие элемента в сессии.
     *
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /**
     * Проверить наличие элемента в сессии, даже если его значение равно null.
     *
     * @return bool
     */
    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    /**
     * __wakeup.
     *
     * @return void
     */
    public function __wakeup()
    {
        $this->isSafe = false;
    }

    /**
     * Деструктор.
     *
     * @return void
     * @throws Exception
     */
    public function __destruct()
    {
        if (!$this->isSafe) {
            return;
        }

        $this->save();
        if (random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
            $this->gc();
        }
    }

    /**
     * Сохранить сессию в хранилище.
     *
     * @return void
     */
    public function save(): void
    {
        if ($this->needSave) {
            if (empty($this->data)) {
                static::$handler->destroy($this->sessionId);
            } else {
                static::$handler->write($this->sessionId, serialize($this->data));
            }
        } elseif (static::$autoUpdateTimestamp) {
            $this->refresh();
        }
        $this->needSave = false;
    }

    /**
     * Обновить время истечения сессии.
     *
     * @return bool
     */
    public function refresh(): bool
    {
        return static::$handler->updateTimestamp($this->getId());
    }

    /**
     * Получить идентификатор сессии.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->sessionId;
    }

    /**
     * Очистка неиспользуемых сессий.
     *
     * @return void
     */
    public function gc(): void
    {
        static::$handler->gc(static::$lifetime);
    }
}

Session::init();
