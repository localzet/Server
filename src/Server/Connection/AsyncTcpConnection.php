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

namespace localzet\Server\Connection;

use Exception;
use localzet\Server;
use localzet\Timer;
use RuntimeException;
use stdClass;
use Throwable;
use function class_exists;
use function explode;
use function function_exists;
use function is_resource;
use function method_exists;
use function microtime;
use function parse_url;
use function socket_import_stream;
use function socket_set_option;
use function stream_context_create;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_client;
use function stream_socket_get_name;
use function ucfirst;
use const PHP_INT_MAX;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const TCP_NODELAY;

/**
 * Асинхронное TCP-соединение.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * Встроенные протоколы PHP.
     *
     * @var array<string,string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls' => 'tls'
    ];
    /**
     * Вызывается при успешном установлении соединения по сокету.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Вызывается, когда завершено рукопожатие веб-сокета (работает только при протоколе ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Протокол транспортного уровня.
     *
     * @var string
     */
    public string $transport = 'tcp';
    /**
     * Socks5-прокси.
     *
     * @var string
     */
    public string $proxySocks5 = '';
    /**
     * HTTP-прокси.
     *
     * @var string
     */
    public string $proxyHttp = '';
    /**
     * Статус соединения.
     *
     * @var int
     */
    protected int $status = self::STATUS_INITIAL;
    /**
     * Удаленный хост.
     *
     * @var string
     */
    protected string $remoteHost = '';
    /**
     * Удаленный порт.
     *
     * @var int
     */
    protected int $remotePort = 80;
    /**
     * Время начала установки соединения.
     *
     * @var float
     */
    protected float $connectStartTime = 0;
    /**
     * Удаленный URI.
     *
     * @var string
     */
    protected string $remoteURI = '';
    /**
     * Опции контекста.
     *
     * @var array
     */
    protected array $contextOption = [];
    /**
     * Таймер переподключения.
     *
     * @var int
     */
    protected int $reconnectTimer = 0;

    /**
     * Конструктор.
     *
     * @param string $remoteAddress Адрес удаленного хоста
     * @param array $socketContext Опции контекста
     * @throws Exception
     */
    public function __construct(string $remoteAddress, array $socketContext = [])
    {
        $addressInfo = parse_url($remoteAddress);
        if (!$addressInfo) {
            [$scheme, $this->remoteAddress] = explode(':', $remoteAddress, 2);
            if ('unix' === strtolower($scheme)) {
                $this->remoteAddress = substr($remoteAddress, strpos($remoteAddress, '/') + 2);
            }
            if (!$this->remoteAddress) {
                throw new RuntimeException('Некорректный remoteAddress');
            }
        } else {
            $addressInfo['port'] ??= 0;
            $addressInfo['path'] ??= '/';
            if (!isset($addressInfo['query'])) {
                $addressInfo['query'] = '';
            } else {
                $addressInfo['query'] = '?' . $addressInfo['query'];
            }
            $this->remoteHost = $addressInfo['host'];
            $this->remotePort = $addressInfo['port'];
            $this->remoteURI = "{$addressInfo['path']}{$addressInfo['query']}";
            $scheme = $addressInfo['scheme'] ?? 'tcp';
            $this->remoteAddress = 'unix' === strtolower($scheme)
                ? substr($remoteAddress, strpos($remoteAddress, '/') + 2)
                : $this->remoteHost . ':' . $this->remotePort;
        }

        $this->id = $this->realId = self::$idRecorder++;
        if (PHP_INT_MAX === self::$idRecorder) {
            self::$idRecorder = 0;
        }

        // Проверяем класс протокола на уровне приложения.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\localzet\\Server\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("Класс \\Protocols\\$scheme не существует");
                }
            }
        } else {
            $this->transport = self::BUILD_IN_TRANSPORTS[$scheme];
        }

        // Для статистики.
        ++self::$statistics['connection_count'];
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->socketContext = $socketContext;
        static::$connections[$this->realId] = $this;
        $this->context = new stdClass;
    }

    /**
     * Переподключение.
     *
     * @param int $after
     * @return void
     * @throws Throwable
     */
    public function reconnect(int $after = 0): void
    {
        $this->status = self::STATUS_INITIAL;
        static::$connections[$this->realId] = $this;
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
        }
        if ($after > 0) {
            $this->reconnectTimer = Timer::add($after, $this->connect(...), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Подключение.
     *
     * @return void
     * @throws Throwable
     */
    public function connect(): void
    {
        if ($this->status !== self::STATUS_INITIAL && $this->status !== self::STATUS_CLOSING &&
            $this->status !== self::STATUS_CLOSED) {
            return;
        }

        if (!$this->eventLoop) {
            $this->eventLoop = Server::$globalEvent;
        }

        $this->status = self::STATUS_CONNECTING;
        $this->connectStartTime = microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->remotePort) {
                $this->remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->remoteAddress = $this->remoteHost . ':' . $this->remotePort;
            }
            // Открыть соединение сокета асинхронно.
            if ($this->proxySocks5) {
                $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->proxySocks5", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
                fwrite($this->socket, chr(5) . chr(1) . chr(0));
                fread($this->socket, 512);
                fwrite($this->socket, chr(5) . chr(1) . chr(0) . chr(3) . chr(strlen($this->remoteHost)) . $this->remoteHost . pack("n", $this->remotePort));
                fread($this->socket, 512);
            } else if ($this->proxyHttp) {
                $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->proxyHttp", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
                $str = "CONNECT $this->remoteHost:$this->remotePort HTTP/1.1\n";
                $str .= "Host: $this->remoteHost:$this->remotePort\n";
                $str .= "Proxy-Connection: keep-alive\n";
                fwrite($this->socket, $str);
                fread($this->socket, 512);
            } else if ($this->socketContext) {
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->remoteHost:$this->remotePort",
                    $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->socket = stream_socket_client("tcp://$this->remoteHost:$this->remotePort",
                    $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->socket = stream_socket_client("$this->transport://$this->remoteAddress", $errno, $err_str, 0,
                STREAM_CLIENT_ASYNC_CONNECT);
        }
        // В случае неудачной попытки вызвать колбэк onError.
        if (!$this->socket || !is_resource($this->socket)) {
            $this->emitError(static::CONNECT_FAIL, $err_str);
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Добавить сокет в глобальный цикл событий, ожидающий успешного подключения или ошибки.
        $this->eventLoop->onWritable($this->socket, $this->checkConnection(...));
        // Для Windows.
        if (!is_unix() && method_exists($this->eventLoop, 'onExcept')) {
            $this->eventLoop->onExcept($this->socket, $this->checkConnection(...));
        }
    }

    /**
     * Попытка вызова колбэка ошибки.
     *
     * @param int $code Код ошибки
     * @param mixed $msg Сообщение об ошибке
     * @return void
     * @throws Throwable
     */
    protected function emitError(int $code, mixed $msg): void
    {
        $this->status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                ($this->onError)($this, $code, $msg);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Отмена переподключения.
     * Если был установлен таймер переподключения.
     */
    public function cancelReconnect(): void
    {
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = 0;
        }
    }

    /**
     * Получение удаленного хоста.
     *
     * @return string
     */
    public function getRemoteHost(): string
    {
        return $this->remoteHost;
    }

    /**
     * Получение удаленного URI.
     *
     * @return string
     */
    public function getRemoteURI(): string
    {
        return $this->remoteURI;
    }

    /**
     * Проверка успешности установки соединения или ошибки.
     *
     * @return void
     * @throws Throwable
     */
    public function checkConnection(): void
    {
        // Удаляем EV_EXPECT для Windows.
        if (!is_unix() && method_exists($this->eventLoop, 'offExcept')) {
            $this->eventLoop->offExcept($this->socket);
        }

        // Удаляем прослушиватель записи.
        $this->eventLoop->offWritable($this->socket);

        if ($this->status !== self::STATUS_CONNECTING) {
            return;
        }

        // Проверим состояние сокета.
        if ($address = stream_socket_get_name($this->socket, true)) {

            // Неблокирующий.
            stream_set_blocking($this->socket, false);

            // Совместим с hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($this->socket, 0);
            }

            // Попробуем открыть keepalive для tcp и отключить алгоритм Nagle.
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $rawSocket = socket_import_stream($this->socket);
                socket_set_option($rawSocket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($rawSocket, SOL_TCP, TCP_NODELAY, 1);
            }
            // SSL-рукопожатие.
            if ($this->transport === 'ssl') {
                $this->sslHandshakeCompleted = $this->doSslHandshake($this->socket);
                if ($this->sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // Есть некоторые данные, ожидающие отправки.
                if ($this->sendBuffer) {
                    $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
                }
            }

            // Зарегистрируем слушателя чтения.
            $this->eventLoop->onReadable($this->socket, $this->baseRead(...));

            $this->status = self::STATUS_ESTABLISHED;
            $this->remoteAddress = $address;

            // Попытка вызвать колбэк подключения.
            if ($this->onConnect) {
                try {
                    ($this->onConnect)($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }

            // Попытка вызвать protocol::onConnect
            if ($this->protocol && method_exists($this->protocol, 'onConnect')) {
                try {
                    $this->protocol::onConnect($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
        } else {
            // Ошибка соединения.
            $this->emitError(static::CONNECT_FAIL, 'Не удалось подключиться к ' . $this->remoteAddress . ' после ' . round(microtime(true) - $this->connectStartTime, 4) . ' секунд');
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
