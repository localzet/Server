<?php

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 * 
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Server\Protocols\Http;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use RuntimeException;
use localzet\Server\Protocols\Http\Session\FileSessionHandler;
use localzet\Server\Protocols\Http\Session\SessionHandlerInterface;
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
 * Class Session
 * @package localzet\Server\Protocols\Http
 */
class Session
{
    /**
     * Session andler class which implements SessionHandlerInterface.
     *
     * @var string
     */
    protected static string $handlerClass = FileSessionHandler::class;

    /**
     * Parameters of __constructor for session handler class.
     *
     * @var mixed
     */
    protected static mixed $handlerConfig = null;

    /**
     * Session name.
     *
     * @var string
     */
    public static string $name = 'PHPSID';

    /**
     * Auto update timestamp.
     *
     * @var bool
     */
    public static bool $autoUpdateTimestamp = false;

    /**
     * Session lifetime.
     *
     * @var int
     */
    public static int $lifetime = 1440;

    /**
     * Cookie lifetime.
     *
     * @var int
     */
    public static int $cookieLifetime = 1440;

    /**
     * Session cookie path.
     *
     * @var string
     */
    public static string $cookiePath = '/';

    /**
     * Session cookie domain.
     *
     * @var string
     */
    public static string $domain = '';

    /**
     * HTTPS only cookies.
     *
     * @var bool
     */
    public static bool $secure = false;

    /**
     * HTTP access only.
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
     * Gc probability.
     *
     * @var int[]
     */
    public static array $gcProbability = [1, 20000];

    /**
     * Session handler instance.
     *
     * @var ?SessionHandlerInterface
     */
    protected static ?SessionHandlerInterface $handler = null;

    /**
     * Session data.
     *
     * @var array
     */
    protected mixed $data = [];

    /**
     * Session changed and need to save.
     *
     * @var bool
     */
    protected bool $needSave = false;

    /**
     * Session id.
     *
     * @var string
     */
    protected string $sessionId;

    /**
     * Session constructor.
     *
     * @param string $sessionId
     */
    public function __construct(string $sessionId)
    {
        static::checkSessionId($sessionId);
        if (static::$handler === null) {
            static::initHandler();
        }
        $this->sessionId = $sessionId;
        if ($data = static::$handler->read($sessionId)) {
            $this->data = unserialize($data);
        }
    }

    /**
     * Get session id.
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->sessionId;
    }

    /**
     * Get session.
     *
     * @param string $name
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    /**
     * Store data in the session.
     *
     * @param string $name
     * @param mixed $value
     */
    public function set(string $name, mixed $value)
    {
        $this->data[$name] = $value;
        $this->needSave = true;
    }

    /**
     * Delete an item from the session.
     *
     * @param string $name
     */
    public function delete(string $name)
    {
        unset($this->data[$name]);
        $this->needSave = true;
    }

    /**
     * Retrieve and delete an item from the session.
     *
     * @param string $name
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
     * Store data in the session.
     *
     * @param array|string $key
     * @param mixed|null $value
     */
    public function put(array|string $key, mixed $value = null)
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
     * Remove a piece of data from the session.
     *
     * @param array|string $name
     */
    public function forget(array|string $name)
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
     * Retrieve all the data in the session.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Remove all data from the session.
     *
     * @return void
     */
    public function flush()
    {
        $this->needSave = true;
        $this->data = [];
    }

    /**
     * Determining If An Item Exists In The Session.
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /**
     * To determine if an item is present in the session, even if its value is null.
     *
     * @param string $name
     * @return bool
     */
    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    /**
     * Save session to store.
     *
     * @return void
     */
    public function save()
    {
        if ($this->needSave) {
            if (empty($this->data)) {
                static::$handler->destroy($this->sessionId);
            } else {
                static::$handler->write($this->sessionId, serialize($this->data));
            }
        } elseif (static::$autoUpdateTimestamp) {
            static::refresh();
        }
        $this->needSave = false;
    }

    /**
     * Refresh session expire time.
     *
     * @return bool
     */
    public function refresh(): bool
    {
        return static::$handler->updateTimestamp($this->getId());
    }

    /**
     * Init.
     *
     * @return void
     */
    public static function init()
    {
        if (($gcProbability = (int)ini_get('session.gc_probability')) && ($gcDivisor = (int)ini_get('session.gc_divisor'))) {
            static::$gcProbability = [$gcProbability, $gcDivisor];
        }

        if ($gcMaxLifeTime = ini_get('session.gc_maxlifetime')) {
            self::$lifetime = (int)$gcMaxLifeTime;
        }

        $sessionCookieParams = session_get_cookie_params();
        static::$cookieLifetime = $sessionCookieParams['lifetime'];
        static::$cookiePath = $sessionCookieParams['path'];
        static::$domain = $sessionCookieParams['domain'];
        static::$secure = $sessionCookieParams['secure'];
        static::$httpOnly = $sessionCookieParams['httponly'];
    }

    /**
     * Set session handler class.
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
     * Get cookie params.
     *
     * @return array
     */
    #[ArrayShape(['lifetime' => "int", 'path' => "string", 'domain' => "string", 'secure' => "bool", 'httponly' => "bool", 'samesite' => "string"])]
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
     * Init handler.
     *
     * @return void
     */
    protected static function initHandler()
    {
        if (static::$handlerConfig === null) {
            static::$handler = new static::$handlerClass();
        } else {
            static::$handler = new static::$handlerClass(static::$handlerConfig);
        }
    }

    /**
     * GC sessions.
     *
     * @return void
     */
    public function gc()
    {
        static::$handler->gc(static::$lifetime);
    }

    /**
     * __destruct.
     *
     * @return void
     * @throws Exception
     */
    public function __destruct()
    {
        $this->save();
        if (random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
            $this->gc();
        }
    }

    /**
     * Check session id.
     *
     * @param string $sessionId
     */
    protected static function checkSessionId(string $sessionId)
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $sessionId)) {
            throw new RuntimeException("session_id $sessionId is invalid");
        }
    }
}

// Init session.
Session::init();
