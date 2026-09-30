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

use localzet\Server\Protocols\Http\Session\FileSessionHandler;
use localzet\Server\Protocols\Http\Session\SessionHandlerInterface;
use Throwable;

/**
 * HTTP session facade для long-running worker'ов.
 *
 * Сессия не использует PHP global session runtime: каждый Request получает свой
 * объект Session и явно сохраняет его при уничтожении. Десериализация запрещает
 * создание объектов, поэтому данные из storage не могут запустить gadget-chain.
 */
class Session
{
    protected static string $handlerClass = FileSessionHandler::class;
    protected static mixed $handlerConfig = null;
    protected static ?SessionHandlerInterface $handler = null;

    public static string $name = 'PHPSID';
    public static bool $autoUpdateTimestamp = false;
    public static int $lifetime = 1440;
    public static int $cookieLifetime = 1440;
    public static string $cookiePath = '/';
    public static string $domain = '';
    public static bool $secure = false;
    public static bool $httpOnly = true;
    public static string $sameSite = '';
    /** @var array{0:int,1:int} */
    public static array $gcProbability = [1, 20000];

    protected array $data = [];
    protected bool $needSave = false;
    protected bool $isSafe = true;

    public function __construct(protected string $id)
    {
        $raw = static::handler()->read($id);
        if (is_string($raw) && $raw !== '') {
            $value = @unserialize($raw, ['allowed_classes' => false]);
            $this->data = is_array($value) ? $value : [];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Возвращает/задаёт готовый handler instance — исторический Localzet API.
     */
    public static function handler(?SessionHandlerInterface $handler = null): SessionHandlerInterface
    {
        if ($handler !== null) {
            static::$handler = $handler;
        }
        if (static::$handler === null) {
            $class = static::$handlerClass;
            static::$handler = static::$handlerConfig === null
                ? new $class()
                : new $class(static::$handlerConfig);
        }
        return static::$handler;
    }

    public static function setHandler(SessionHandlerInterface $handler): void
    {
        static::$handler = $handler;
    }

    /**
     * Смена класса сбрасывает уже созданный singleton handler.
     */
    public static function handlerClass(mixed $className = null, mixed $config = null): string
    {
        if ($className !== null) {
            if (!is_string($className) || !is_a($className, SessionHandlerInterface::class, true)) {
                throw new \InvalidArgumentException('Session handler must implement ' . SessionHandlerInterface::class);
            }
            static::$handlerClass = $className;
            static::$handler = null;
        }
        if ($config !== null) {
            static::$handlerConfig = $config;
            static::$handler = null;
        }
        return static::$handlerClass;
    }

    public static function setCookieParams(array $params): void
    {
        if (array_key_exists('lifetime', $params)) static::$cookieLifetime = max(0, (int)$params['lifetime']);
        if (array_key_exists('path', $params)) static::$cookiePath = (string)$params['path'];
        if (array_key_exists('domain', $params)) static::$domain = (string)$params['domain'];
        if (array_key_exists('secure', $params)) static::$secure = (bool)$params['secure'];
        if (array_key_exists('httponly', $params)) static::$httpOnly = (bool)$params['httponly'];
        if (array_key_exists('samesite', $params)) static::$sameSite = (string)$params['samesite'];
    }

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

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
        $this->needSave = true;
    }

    public function put(string|array $name, mixed $value = null): void
    {
        if (!is_array($name)) {
            $this->set($name, $value);
            return;
        }
        foreach ($name as $key => $item) {
            $this->data[(string)$key] = $item;
        }
        $this->needSave = true;
    }

    public function delete(string $name): void
    {
        unset($this->data[$name]);
        $this->needSave = true;
    }

    public function pull(string $name, mixed $default = null): mixed
    {
        $value = $this->get($name, $default);
        $this->delete($name);
        return $value;
    }

    public function forget(string|array $name): void
    {
        foreach ((array)$name as $key) {
            unset($this->data[(string)$key]);
        }
        $this->needSave = true;
    }

    /** isset-semantics: null считается отсутствующим. */
    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /** array_key_exists-semantics: null считается существующим значением. */
    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function flush(): void
    {
        $this->data = [];
        $this->needSave = true;
    }

    public function save(): void
    {
        $handler = static::handler();
        if ($this->needSave) {
            if ($this->data === []) {
                $handler->destroy($this->id);
            } else {
                $handler->write($this->id, serialize($this->data));
            }
            $this->needSave = false;
            return;
        }

        if (static::$autoUpdateTimestamp) {
            $handler->updateTimestamp($this->id);
        }
    }

    public function refresh(): bool
    {
        return static::handler()->updateTimestamp($this->id);
    }

    public function gc(): void
    {
        static::handler()->gc(static::$lifetime);
    }

    public static function init(): void
    {
        $probability = (int)ini_get('session.gc_probability');
        $divisor = (int)ini_get('session.gc_divisor');
        if ($probability > 0 && $divisor > 0) {
            static::$gcProbability = [$probability, $divisor];
        }
        $maxLifetime = (int)ini_get('session.gc_maxlifetime');
        if ($maxLifetime > 0) {
            static::$lifetime = $maxLifetime;
        }
        $params = session_get_cookie_params();
        static::$cookieLifetime = (int)($params['lifetime'] ?? static::$cookieLifetime);
        static::$cookiePath = (string)($params['path'] ?? static::$cookiePath);
        static::$domain = (string)($params['domain'] ?? static::$domain);
        static::$secure = (bool)($params['secure'] ?? static::$secure);
        static::$httpOnly = (bool)($params['httponly'] ?? static::$httpOnly);
        if (isset($params['samesite']) && $params['samesite'] !== '') {
            static::$sameSite = (string)$params['samesite'];
        }
    }

    public function __unserialize(array $data): void
    {
        $this->isSafe = false;
    }

    public function __wakeup(): void
    {
        $this->isSafe = false;
    }

    public function __destruct()
    {
        if (!$this->isSafe) {
            return;
        }
        try {
            $this->save();
            [$chance, $divisor] = static::$gcProbability;
            if ($chance > 0 && $divisor > 0 && random_int(1, $divisor) <= $chance) {
                $this->gc();
            }
        } catch (Throwable) {
            // Destructors must not turn request shutdown into a fatal error.
        }
    }
}

Session::init();
