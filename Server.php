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

namespace localzet;

use AllowDynamicProperties;
use localzet\Server\Connection\ConnectionInterface;
use localzet\Server\Connection\TcpConnection;
use localzet\Server\Connection\UdpConnection;
use localzet\Server\Events\EventInterface;
use localzet\Server\Events\EventLoopFactory;
use localzet\Server\Protocols\Frame;
use localzet\Server\Protocols\Http;
use localzet\Server\Protocols\ProtocolInterface;
use localzet\Server\Protocols\Redis;
use localzet\Server\Protocols\Text;
use localzet\Server\Protocols\Websocket;
use localzet\Server\Runtime\HotUpgradeBroker;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Localzet Server.
 *
 * Центральный supervisor и listening endpoint. API сохраняет привычную модель:
 * создаём Server instances, назначаем callbacks и один раз вызываем runAll().
 * На Unix master управляет worker-процессами; на Windows endpoint'ы работают
 * в одном процессе.
 *
 * Сохраняем исходную lifecycle-модель Localzet Events:
 * <code>
 * Localzet\Events = [
 *  'Server::Start' => fn($server = null){},
 *  'Server::Stop' => fn($server = null){},
 *  'Server::Reload' => fn($server = null){},
 *  'Server::Exit' => fn(['server', 'status', 'pid'] = []){},
 *
 *  'Server::Master::Stop' => fn(){},
 *  'Server::Master::Reload' => fn(){},
 * ];
 * </code>
 */
#[AllowDynamicProperties]
class Server
{
    /**
     * Версия Localzet Server.
     *
     * @var string
     */
    final public const VERSION = '700_26.09.11';

    /** Начальное состояние процесса. */
    public const STATUS_INITIAL = 0;

    /** Статус: запуск. */
    public const STATUS_STARTING = 1;

    /** Статус: работает. */
    public const STATUS_RUNNING = 2;

    /** Статус: остановка. */
    public const STATUS_SHUTDOWN = 4;

    /** Статус: перезагрузка. */
    public const STATUS_RELOADING = 8;

    /**
     * Backlog по умолчанию. Backlog — максимальная длина очереди ожидающих соединений.
     */
    public const DEFAULT_BACKLOG = 102400;

    /**
     * Безопасное расстояние для соседних колонок CLI status.
     *
     * Константа сохранена из публичного API ветки 5.x для совместимости.
     *
     * @var int
     */
    public const UI_SAFE_LENGTH = 4;

    /**
     * Встроенные типы PHP-ошибок.
     *
     * Публичная таблица сохранена для кода, который использовал Localzet Server
     * как единый formatter ошибок до модернизации supervisor-а.
     *
     * @var array<int,string>
     */
    public const ERROR_TYPE = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    /**
     * Встроенные транспортные протоколы.
     *
     * @var array<string,string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp',
    ];

    /**
     * Соответствие Localzet environment variables параметрам PHP SSL stream context.
     *
     * Комментарии у отдельных параметров намеренно оставлены рядом со значением:
     * это исходная документация конфигурации и одновременно подсказка при ревью.
     */
    public const CONTEXT_SSL = [
        'LOCALZET_SSL_PEER_NAME' => 'peer_name',                            // Имя узла. Если его значение не задано, имя выводится из hostname потока.
        'LOCALZET_SSL_VERIFY_PEER' => 'verify_peer',                        // Требовать проверки используемого SSL-сертификата.
        'LOCALZET_SSL_VERIFY_PEER_NAME' => 'verify_peer_name',              // Требовать проверки имени узла.
        'LOCALZET_SSL_SELF_SIGNED' => 'allow_self_signed',                  // Разрешить самоподписанные сертификаты.
        'LOCALZET_SSL_CAFILE' => 'cafile',                                  // Файл CA для проверки подлинности удалённого узла.
        'LOCALZET_SSL_CAPATH' => 'capath',                                  // Каталог CA-сертификатов, используемый если cafile не задан или сертификат не найден.
        'LOCALZET_SSL_CERT' => 'local_cert',                                // Локальный PEM-сертификат; может также содержать закрытый ключ и цепочку эмитента.
        'LOCALZET_SSL_CERT_KEY' => 'local_pk',                              // Отдельный файл приватного ключа для local_cert.
        'LOCALZET_SSL_CERT_PASS' => 'passphrase',                           // Пароль приватного ключа/local_cert.
        'LOCALZET_SSL_CERT_VERIFY_DEPTH' => 'verify_depth',                 // Максимальная допустимая глубина цепочки сертификатов.
        'LOCALZET_SSL_CIPHERS' => 'ciphers',                                // Список доступных алгоритмов шифрования.
        'LOCALZET_SSL_CAPTURE_CERT' => 'capture_peer_cert',                 // Сохранить сертификат удалённого узла в контексте.
        'LOCALZET_SSL_CAPTURE_CERT_CHAIN' => 'capture_peer_cert_chain',     // Сохранить цепочку сертификатов удалённого узла.
        'LOCALZET_SSL_SNI' => 'SNI_enabled',                                // Включить SNI для нескольких сертификатов на одном IP.
        'LOCALZET_SSL_DISABLE_COMPRESSION' => 'disable_compression',        // Отключить TLS compression для защиты от CRIME.
        'LOCALZET_SSL_SECURITY_LEVEL' => 'security_level',                  // Уровень безопасности OpenSSL.
        'LOCALZET_SSL_PEER_FINGERPRINT' => 'peer_fingerprint',              // Проверять fingerprint удалённого сертификата.
    ];

    /** ID сервера. */
    public int $id = 0;

    /** Название для серверных процессов. */
    public string $name = 'none';

    /** Количество серверных процессов. */
    public int $count = 1;

    /** Unix-пользователь, под которым должен работать worker (для смены нужен root). */
    public string $user = '';

    /** Unix-группа, под которой должен работать worker (для смены нужен root). */
    public string $group = '';

    /** Разрешено ли заменять этот worker при reload. */
    public bool $reloadable = true;

    /** Повторно использовать порт через SO_REUSEPORT, если это поддерживается платформой. */
    public bool $reusePort = false;

    /** Протокол транспортного уровня. */
    public string $transport = 'tcp';

    /** Протокол уровня приложения. */
    public ?string $protocol = null;

    /** Предпочтительный event-loop backend для этого runtime. */
    public ?string $eventLoop = null;

    /** Сервер находится в процессе остановки. */
    public bool $stopping = false;

    /** Максимум обработанных сообщений до graceful recycle worker. 0 = без лимита. */
    public int $maxRequests = 0;

    /** Максимальное время жизни worker в секундах. 0 = без лимита. */
    public float $maxLifetime = 0.0;

    /** Максимальная память worker в байтах. 0 = без лимита. */
    public int $maxMemory = 0;

    /** Idle timeout для новых TCP-соединений. 0 = отключён. */
    public float $idleTimeout = 0.0;

    /**
     * Абсолютный deadline сборки одного protocol frame.
     *
     * В отличие от idleTimeout не продлевается каждым новым байтом, поэтому
     * защищает length-delimited/HTTP protocols от бесконечного slow-drip.
     * 0 = отключён.
     */
    public float $frameTimeout = 0.0;

    /** Максимальное число одновременно открытых TCP connections на worker. 0 = без лимита. */
    public int $maxConnections = 0;

    /** Максимальное время TLS handshake. 0 = отключён. */
    public float $tlsHandshakeTimeout = 10.0;

    /** @var array<int,TcpConnection> */
    public array $connections = [];

    /** Выполняется при запуске server worker. @var ?callable */
    public $onServerStart = null;

    /** Выполняется, когда TCP-соединение успешно установлено. @var ?callable */
    public $onConnect = null;

    /** Выполняется перед завершением server-side WebSocket handshake. @var ?callable */
    public $onWebSocketConnect = null;

    /** Выполняется после успешного WebSocket handshake. @var ?callable */
    public $onWebSocketConnected = null;

    /** Выполняется при получении WebSocket Close frame. @var ?callable */
    public $onWebSocketClose = null;

    /** Выполняется при получении WebSocket Ping frame. @var ?callable */
    public $onWebSocketPing = null;

    /** Выполняется при получении WebSocket Pong frame. @var ?callable */
    public $onWebSocketPong = null;

    /** Выполняется при получении application message. @var ?callable */
    public $onMessage = null;

    /** Выполняется, когда соединение закрывается/другой конец присылает FIN. @var ?callable */
    public $onClose = null;

    /** Выполняется при ошибке соединения. @var ?callable */
    public $onError = null;

    /** Выполняется, когда send-buffer достигает high-water mark. @var ?callable */
    public $onBufferFull = null;

    /** Выполняется, когда send-buffer снова освобождается. @var ?callable */
    public $onBufferDrain = null;

    /** Выполняется при остановке worker. @var ?callable */
    public $onServerStop = null;

    /** Выполняется перед заменой worker при reload. @var ?callable */
    public $onServerReload = null;

    /** В режиме демона? */
    public static bool $daemonize = false;

    /**
     * Поток стандартного вывода.
     *
     * @var resource|null
     */
    public static $outputStream = null;
    /** Файл Stdout. */
    public static string $stdoutFile = '/dev/null';
    /** Файл для хранения PID master-процесса. */
    public static string $pidFile = '';
    /** Файл состояния master/worker процессов. */
    public static string $statusFile = '';
    /** Файл журнала Localzet Server. */
    public static string $logFile = '';
    /** Максимальный размер активного log-файла до ротации. */
    public static int $logFileMaxSize = 10_485_760;
    /** Глобальная петля событий текущего процесса. */
    public static ?EventInterface $globalEvent = null;
    /** Выполняется при перезагрузке master-процесса. @var ?callable */
    public static $onMasterReload = null;
    /** Выполняется при остановке master-процесса. @var ?callable */
    public static $onMasterStop = null;
    /** Выполняется при выходе server process. @var ?callable */
    public static $onServerExit = null;
    /** Явно заданный класс событийной петли. @var ?class-string<EventInterface> */
    public static ?string $eventLoopClass = null;
    /** Таймаут graceful stop дочерних процессов до принудительного завершения. */
    public static int $stopTimeout = 3;

    /** Базовая задержка повторного запуска аварийно упавшего worker. 0 = без backoff. */
    public static float $restartDelay = 0.10;

    /** Верхняя граница exponential crash backoff. */
    public static float $maxRestartDelay = 5.0;

    /**
     * Worker, проживший дольше этого интервала, считается стабильным:
     * следующая авария снова начинает backoff с базового значения.
     */
    public static float $stableWorkerTime = 10.0;

    /** Интервал записи лёгкого worker status snapshot. 0 = отключить heartbeat. */
    public static float $statusInterval = 1.0;

    /** Сколько ждать свежие worker snapshots в CLI status/connections. */
    public static float $statusRefreshTimeout = 0.75;

    /**
     * Текущая generation master image. Увеличивается после каждого успешного
     * zero-downtime hot-upgrade и выводится в status для диагностики деплоев.
     */
    public static int $generation = 1;

    /** Текущая CLI-команда. */
    public static string $command = '';

    /** Hot-upgrade запрошен signal handler'ом и будет выполнен из monitor loop. */
    protected static bool $hotUpgradeRequested = false;

    /** Новый PHP image сейчас восстанавливает supervisor state через broker. */
    protected static bool $hotUpgradeBootstrap = false;

    /** Аргументы исходного запуска, которые нужно сохранить при pcntl_exec(). */
    protected static array $startArguments = [];

    /** @var array<int,self> */
    protected static array $servers = [];
    /** @var array<int,array<int,int>> server object id -> child pids */
    protected static array $pidMap = [];
    /** @var array<int,int> child pid -> logical process id */
    protected static array $childIdMap = [];

    /** @var array<int,float> child pid -> fork timestamp */
    protected static array $childStartedAt = [];

    /** @var array<string,array{failures:int,last_crash:float}> */
    protected static array $restartState = [];

    /** @var list<array{server_id:int,logical_id:int,due_at:float,delay:float,failures:int}> */
    protected static array $pendingRestarts = [];

    protected static int $status = self::STATUS_INITIAL;
    protected static int $masterPid = 0;
    protected static bool $masterStopping = false;
    protected static bool $masterReloading = false;
    protected static bool $gracefulStop = false;
    protected static float $masterStopStartedAt = 0.0;
    protected static float $masterStartedAt = 0.0;
    protected static string $startFile = '';
    protected static bool $controlJson = false;

    /**
     * Advisory startup lock for one entry script.
     *
     * PID-файл сам по себе не защищает от двух одновременных `start`: оба процесса
     * могут прочитать его до того, как один успеет записать свой PID. flock() закрывает
     * эту race и наследуется forked workers вместе с master process.
     *
     * @var resource|null
     */
    protected static $pidLockHandle = null;

    /**
     * В multi-process child только один Server instance является активным.
     * Остальные объекты унаследованы от master исключительно как bootstrap state.
     */
    protected static ?int $activeWorkerServerId = null;

    /** @var list<array{server_id:int,pid:int,graceful:bool}> */
    protected static array $reloadQueue = [];

    /** @var null|array{server_id:int,pid:int,graceful:bool,started_at:float} */
    protected static ?array $reloadCurrent = null;

    protected string $socketName = '';
    protected ?string $localSocket = null;
    /** @var resource|null */
    protected $mainSocket = null;
    /** @var resource|null */
    protected $socketContext = null;
    public stdClass $context;
    protected bool $pauseAccept = true;
    protected int $serverObjectId;
    protected int $processedMessages = 0;

    /**
     * Синхронные application callbacks, которые прямо сейчас исполняются.
     *
     * Нужны для graceful shutdown: POSIX signal может прервать PHP прямо внутри
     * onMessage, и закрывать этот socket до возврата callback было бы гонкой.
     *
     * @var array<int, ConnectionInterface>
     */
    protected array $activeDispatches = [];

    protected float $workerStartedAt = 0.0;
    protected int $workerPolicyTimerId = 0;
    protected int $statusTimerId = 0;

    public function __construct(?string $socketName = null, array $socketContext = [])
    {
        $this->serverObjectId = spl_object_id($this);
        $this->context = new stdClass();
        self::$servers[$this->serverObjectId] = $this;
        self::$pidMap[$this->serverObjectId] = [];

        if ($socketName !== null) {
            $this->socketName = $socketName;
            $socketContext['socket']['backlog'] ??= self::DEFAULT_BACKLOG;
            $this->applySslEnvironment($socketContext);
            $this->socketContext = stream_context_create($socketContext);
            $this->parseSocketName();
        }

        $this->onMessage = static function (): void {
        };
    }

    /** Запускает все зарегистрированные Server instances. */
    public static function runAll(): void
    {
        self::checkEnvironment();
        self::initializeRuntime();
        self::parseCommand();

        if (self::$command !== 'start') {
            self::executeControlCommand();
            return;
        }

        self::$status = self::STATUS_STARTING;

        // После pcntl_exec() bootstrap создаёт Server objects заново. Вместо
        // повторного bind/startup-lock новый image восстанавливает descriptors и
        // topology у короткоживущего HotUpgradeBroker.
        if (self::$hotUpgradeBootstrap) {
            self::adoptHotUpgradeRuntime();
            return;
        }

        self::acquireStartupLock();
        if (self::$daemonize && DIRECTORY_SEPARATOR === '/') {
            self::daemonizeProcess();
        }

        self::$masterPid = getmypid() ?: 0;
        self::$masterStartedAt = microtime(true);
        self::setProcessTitle('localzet: master ' . basename(self::$startFile));
        @file_put_contents(self::$pidFile, (string)self::$masterPid, LOCK_EX);

        if (DIRECTORY_SEPARATOR === '\\' || !function_exists('pcntl_fork')) {
            self::runSingleProcess();
            return;
        }

        self::installMasterSignals();
        // Listening sockets создаются в master до fork и наследуются детьми.
        // Так несколько процессов делят один socket без SO_REUSEPORT и без race на bind().
        foreach (self::$servers as $server) {
            $server->listen();
        }
        self::forkAllServers();
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
        self::displayStartInfo();
        self::monitorChildren();
    }

    /**
     * Создаёт listening socket. Можно вызвать вручную до runAll() для advanced setup.
     */
    public function listen(): void
    {
        if ($this->socketName === '') {
            return;
        }
        if (is_resource($this->mainSocket)) {
            $this->resumeAccept();
            return;
        }

        $this->parseSocketName();
        $flags = STREAM_SERVER_BIND;
        if ($this->transport !== 'udp') {
            $flags |= STREAM_SERVER_LISTEN;
        }

        $errno = 0;
        $errstr = '';
        if ($this->reusePort && is_resource($this->socketContext)) {
            @stream_context_set_option($this->socketContext, 'socket', 'so_reuseport', true);
        }

        $this->mainSocket = @stream_socket_server(
            $this->localSocket,
            $errno,
            $errstr,
            $flags,
            $this->socketContext ?: stream_context_create(['socket' => ['backlog' => self::DEFAULT_BACKLOG]])
        );

        if (!is_resource($this->mainSocket)) {
            throw new RuntimeException("Unable to listen on {$this->socketName}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($this->mainSocket, false);
        $this->pauseAccept = false;
        $this->resumeAccept();
    }

    public function unlisten(): void
    {
        if (!is_resource($this->mainSocket)) {
            return;
        }
        self::$globalEvent?->offReadable($this->mainSocket);
        @fclose($this->mainSocket);
        $this->mainSocket = null;
        $this->pauseAccept = true;
    }

    public function pauseAccept(): void
    {
        if ($this->pauseAccept || !is_resource($this->mainSocket)) {
            return;
        }
        $this->pauseAccept = true;
        self::$globalEvent?->offReadable($this->mainSocket);
    }

    public function resumeAccept(): void
    {
        if (!is_resource($this->mainSocket) || self::$globalEvent === null) {
            return;
        }
        $this->pauseAccept = false;
        if ($this->transport === 'udp') {
            self::$globalEvent->onReadable($this->mainSocket, $this->acceptUdpConnection(...));
        } else {
            self::$globalEvent->onReadable($this->mainSocket, $this->acceptTcpConnection(...));
        }
    }

    /** @internal */
    public function acceptTcpConnection($socket): void
    {
        $remote = '';
        $client = @stream_socket_accept($socket, 0, $remote);
        if (!is_resource($client)) {
            return;
        }

        if ($this->maxConnections > 0 && count($this->connections) >= $this->maxConnections) {
            // Listener всё равно accept'ит socket, чтобы backlog не забивался
            // соединениями, которые worker заведомо не сможет обслужить.
            ConnectionInterface::$statistics['connection_rejected']++;
            @fclose($client);
            return;
        }

        $connection = new TcpConnection(self::$globalEvent, $client, $remote);
        $connection->server = $this;
        $connection->transport = $this->transport;
        $connection->protocol = $this->protocol;
        $connection->onMessage = $this->dispatchMessage(...);
        $connection->setIdleTimeout($this->idleTimeout);
        $connection->setFrameTimeout($this->frameTimeout);
        $connection->setTlsHandshakeTimeout($this->tlsHandshakeTimeout);
        $connection->onError = $this->onError;
        $connection->onBufferFull = $this->onBufferFull;
        $connection->onBufferDrain = $this->onBufferDrain;
        $connection->onWebSocketConnect = $this->onWebSocketConnect;
        $connection->onWebSocketConnected = $this->onWebSocketConnected;
        $connection->onWebSocketClose = $this->onWebSocketClose;
        $connection->onWebSocketPing = $this->onWebSocketPing;
        $connection->onWebSocketPong = $this->onWebSocketPong;

        $this->connections[$connection->id] = $connection;
        // Connection removes itself from this collection internally on destroy(),
        // so application code is free to replace/chain onClose without leaking entries.
        $connection->onClose = $this->onClose;

        if ($this->transport === 'ssl') {
            $connection->enableSsl();
        }

        if ($this->onConnect !== null) {
            try {
                ($this->onConnect)($connection);
            } catch (Throwable $e) {
                self::log($e);
                $connection->destroy();
            }
        }
    }

    /** @internal */
    public function acceptUdpConnection($socket): bool
    {
        $remote = '';
        $buffer = @stream_socket_recvfrom($socket, 65535, 0, $remote);
        if ($buffer === false || $remote === '') {
            return false;
        }

        $connection = new UdpConnection(self::$globalEvent, $socket, $remote);
        $connection->protocol = $this->protocol;
        $connection->onError = $this->onError;
        $connection->onClose = $this->onClose;

        try {
            $message = $this->protocol !== null ? ($this->protocol)::decode($buffer, $connection) : $buffer;
            if ($message !== null) {
                $this->dispatchMessage($connection, $message);
            }
        } catch (Throwable $e) {
            self::log($e);
            if ($this->onError !== null) {
                ($this->onError)($connection, 0, $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Единая точка dispatch для TCP/UDP сообщений.
     *
     * Благодаря этому worker policies не завязаны на HTTP и одинаково работают
     * для WebSocket, custom protocols и обычного TCP/UDP приложения.
     */
    public function dispatchMessage(\localzet\Server\Connection\ConnectionInterface $connection, mixed $message): void
    {
        $this->processedMessages++;
        $connectionId = property_exists($connection, 'id') ? (int)$connection->id : spl_object_id($connection);
        $this->activeDispatches[$connectionId] = $connection;

        try {
            if ($this->onMessage !== null) {
                ($this->onMessage)($connection, $message);
            }
        } finally {
            unset($this->activeDispatches[$connectionId]);

            // Graceful stop/reload мог прийти POSIX-сигналом прямо внутри callback.
            // В таком случае socket не трогаем до завершения callback и только
            // сейчас переводим его в half-close/flush lifecycle.
            if (($connection->gracefulCloseAfterDispatch ?? false) === true) {
                unset($connection->gracefulCloseAfterDispatch);
                self::gracefullyCloseConnection($connection);
            }
        }

        if ($this->maxRequests > 0 && $this->processedMessages >= $this->maxRequests) {
            $this->requestWorkerRecycle('max_requests');
        }
    }

    /** Возвращает true, если connection прямо сейчас находится внутри onMessage. */
    protected function isDispatching(ConnectionInterface $connection): bool
    {
        $connectionId = property_exists($connection, 'id') ? (int)$connection->id : spl_object_id($connection);
        return isset($this->activeDispatches[$connectionId]);
    }

    /** Количество сообщений, обработанных текущим worker этим Server instance. */
    public function getProcessedMessages(): int
    {
        return $this->processedMessages;
    }

    /** Время жизни текущего worker/server instance в секундах. */
    public function getWorkerUptime(): float
    {
        return $this->workerStartedAt > 0 ? max(0.0, microtime(true) - $this->workerStartedAt) : 0.0;
    }

    public function getSocketName(): string
    {
        return $this->socketName;
    }

    /**
     * Получить основной listening socket.
     *
     * Метод сохранён из 5.x для advanced integrations и диагностических tools.
     *
     * @return resource|null
     */
    public function getMainSocket()
    {
        return $this->mainSocket;
    }

    /**
     * Совместимая таблица колонок старого CLI UI.
     *
     * Новый status renderer не обязан использовать её внутренне, но внешние
     * расширения 5.x могли переопределять/читать этот mapping.
     *
     * @return array<string,string>
     */
    public static function getUiColumns(): array
    {
        return [
            'proto' => 'transport',
            'user' => 'user',
            'server' => 'name',
            'socket' => 'statusSocket',
            'processes' => 'count',
            'state' => 'statusState',
        ];
    }

    /** Возвращает текущий режим graceful shutdown/reload. */
    public static function getGracefulStop(): bool
    {
        return self::$gracefulStop;
    }

    /**
     * Совместимый публичный entry-point смены Unix user/group.
     *
     * Реальная проверка выполняется новым fail-fast privilege drop кодом.
     */
    public function setUserAndGroup(): void
    {
        self::dropPrivileges($this);
    }

    public static function getAllServers(): array
    {
        return self::$servers;
    }

    public static function getStatus(): int
    {
        return self::$status;
    }

    /** Возвращает версию runtime. Сохранено для совместимости со старым Localzet API. */
    public static function getVersion(): string
    {
        return self::VERSION;
    }

    public static function getEventLoop(): EventInterface
    {
        if (self::$globalEvent !== null) {
            return self::$globalEvent;
        }
        self::$globalEvent = self::createEventLoop();
        self::$globalEvent->setErrorHandler(static fn(Throwable $e) => self::log($e));
        Timer::init(self::$globalEvent);
        return self::$globalEvent;
    }

    public static function stopAll(int $code = 0, mixed $log = ''): void
    {
        if (self::$status === self::STATUS_SHUTDOWN) {
            return;
        }
        self::$status = self::STATUS_SHUTDOWN;
        if ($log !== '' && $log !== null && $log !== false) {
            self::log($log instanceof Throwable ? $log : (string)$log);
        }

        foreach (self::currentProcessServers() as $server) {
            $server->stopping = true;
            $server->unlisten();
            foreach ($server->connections as $connection) {
                if (!self::$gracefulStop) {
                    $connection->destroy();
                    continue;
                }

                if ($server->isDispatching($connection)) {
                    // Не закрываем socket из async signal handler посреди user code.
                    // dispatchMessage() завершит его после возврата callback.
                    $connection->gracefulCloseAfterDispatch = true;
                    continue;
                }

                self::gracefullyCloseConnection($connection);
            }
        }

        if (self::$gracefulStop && self::hasOpenConnections()) {
            // Даём активным send buffers завершиться, но не зависаем бесконечно.
            $deadline = microtime(true) + self::$stopTimeout;
            Timer::add(0.05, static function () use ($deadline, $code): void {
                if (!self::hasOpenConnections()) {
                    self::finalizeProcessStop($code);
                    return;
                }
                if (microtime(true) >= $deadline) {
                    foreach (self::currentProcessServers() as $server) {
                        foreach ($server->connections as $connection) {
                            $connection->destroy();
                        }
                    }
                    self::finalizeProcessStop($code);
                }
            });
            return;
        }

        self::finalizeProcessStop($code);
    }

    protected static function hasOpenConnections(): bool
    {
        foreach (self::currentProcessServers() as $server) {
            if ($server->connections) {
                return true;
            }
        }
        return false;
    }

    /**
     * Даёт прикладному protocol корректно закрыть свою сессию перед TCP FIN.
     * WebSocket, например, обязан сначала отправить RFC 6455 Close frame.
     */
    protected static function gracefullyCloseConnection(ConnectionInterface $connection): void
    {
        try {
            if ($connection instanceof TcpConnection
                && $connection->protocol !== null
                && method_exists($connection->protocol, 'gracefulClose')) {
                ($connection->protocol)::gracefulClose($connection);
                return;
            }
            if (method_exists($connection, 'end')) {
                $connection->end();
            } else {
                $connection->close();
            }
        } catch (Throwable $e) {
            self::log($e);
            $connection->close();
        }
    }

    protected static function finalizeProcessStop(int $code): void
    {
        foreach (self::currentProcessServers() as $server) {
            if ($server->onServerStop !== null) {
                try {
                    ($server->onServerStop)($server);
                } catch (Throwable $e) {
                    self::log($e);
                }
            }
            self::emitLifecycleEvent('Server::Stop', $server);
        }
        self::$globalEvent?->stop();
        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_getpid') && posix_getpid() !== self::$masterPid) {
            exit($code);
        }
    }

    public static function reloadAll(): void
    {
        self::$status = self::STATUS_RELOADING;
        foreach (self::$servers as $server) {
            if (!$server->reloadable) {
                continue;
            }
            if ($server->onServerReload !== null) {
                try {
                    ($server->onServerReload)($server);
                } catch (Throwable $e) {
                    self::log($e);
                }
            }
            self::emitLifecycleEvent('Server::Reload', $server);
        }
    }

    public static function log(string|Throwable $message): void
    {
        $text = $message instanceof Throwable
            ? sprintf("%s: %s in %s:%d\n%s", $message::class, $message->getMessage(), $message->getFile(), $message->getLine(), $message->getTraceAsString())
            : $message;
        $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($text) . PHP_EOL;

        if (self::$logFile !== '') {
            self::appendLogLine($line);
        }
        if (!self::$daemonize) {
            @fwrite(STDERR, $line);
        }
    }

    /**
     * Совместимый безопасный вывод из старого Localzet Server.
     *
     * Поддерживает небольшой набор цветовых тегов, использовавшихся старым UI.
     * Если stdout не TTY или decoration отключена, теги просто удаляются.
     */
    public static function safeEcho(string $message, bool $decorated = true): void
    {
        $canDecorate = $decorated
            && defined('STDOUT')
            && function_exists('posix_isatty')
            && @posix_isatty(STDOUT);

        $colors = [
            '<red>' => "\033[31m", '</red>' => "\033[0m",
            '<green>' => "\033[32m", '</green>' => "\033[0m",
            '<yellow>' => "\033[33m", '</yellow>' => "\033[0m",
            '<blue>' => "\033[34m", '</blue>' => "\033[0m",
            '<magenta>' => "\033[35m", '</magenta>' => "\033[0m",
            '<cyan>' => "\033[36m", '</cyan>' => "\033[0m",
            '<white>' => "\033[37m", '</white>' => "\033[0m",
            '<bold>' => "\033[1m", '</bold>' => "\033[0m",
        ];

        $output = $canDecorate
            ? strtr($message, $colors)
            : preg_replace('/<\/?(?:red|green|yellow|blue|magenta|cyan|white|bold)>/', '', $message);

        @fwrite(defined('STDOUT') ? STDOUT : fopen('php://stdout', 'wb'), (string)$output);
    }

    /** Тип PHP error для совместимости со старым диагностическим API. */
    public static function getErrorType(int $type): string
    {
        return match ($type) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }

    /** Публикует lifecycle в localzet/events, не связывая supervisor с event bus. */
    protected static function emitLifecycleEvent(string $eventName, mixed $payload = null): void
    {
        if (!class_exists(Events::class)) {
            return;
        }
        try {
            Events::emit($eventName, $payload);
        } catch (Throwable $e) {
            self::log($e);
        }
    }

    protected function parseSocketName(): void
    {
        if ($this->socketName === '') {
            return;
        }
        $scheme = parse_url($this->socketName, PHP_URL_SCHEME);
        if (!is_string($scheme) || $scheme === '') {
            throw new \InvalidArgumentException('Socket name must include a scheme, e.g. tcp://0.0.0.0:8080.');
        }
        $scheme = strtolower($scheme);

        $protocolMap = [
            'http' => [Http::class, 'tcp'],
            'https' => [Http::class, 'ssl'],
            'websocket' => [Websocket::class, 'tcp'],
            'ws' => [Websocket::class, 'tcp'],
            'wss' => [Websocket::class, 'ssl'],
            'text' => [Text::class, 'tcp'],
            'frame' => [Frame::class, 'tcp'],
            'redis' => [Redis::class, 'tcp'],
        ];

        $address = substr($this->socketName, strlen($scheme) + 3);
        if (isset($protocolMap[$scheme])) {
            [$defaultProtocol, $transport] = $protocolMap[$scheme];
            $this->protocol ??= $defaultProtocol;
            $this->transport = $transport;
            $this->localSocket = ($transport === 'udp' ? 'udp://' : 'tcp://') . $address;
            return;
        }

        if (isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $this->transport = $scheme;
            $transport = self::BUILD_IN_TRANSPORTS[$scheme];
            $this->localSocket = $transport . '://' . $address;
            return;
        }

        // Пользовательский protocol scheme: localzet\Server\Protocols\Foo.
        $class = 'localzet\\Server\\Protocols\\' . ucfirst($scheme);
        if (!class_exists($class) || !is_a($class, ProtocolInterface::class, true)) {
            throw new \InvalidArgumentException("Unknown socket protocol '{$scheme}'.");
        }
        $this->protocol ??= $class;
        $this->transport = 'tcp';
        $this->localSocket = 'tcp://' . $address;
    }

    protected function applySslEnvironment(array &$context): void
    {
        foreach (self::CONTEXT_SSL as $env => $option) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                continue;
            }
            if (in_array($option, ['verify_peer', 'verify_peer_name', 'allow_self_signed', 'capture_peer_cert', 'capture_peer_cert_chain', 'SNI_enabled', 'disable_compression'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value;
            } elseif (in_array($option, ['verify_depth', 'security_level'], true) && is_numeric($value)) {
                $value = (int)$value;
            }
            $context['ssl'][$option] = $value;
        }
    }

    /**
     * Server instances, которые действительно исполняются в текущем процессе.
     *
     * В master/single-process режиме это все endpoints. В forked worker — только
     * его endpoint, чтобы stop hooks и drain не срабатывали для чужих workers.
     *
     * @return array<int,self>
     */
    protected static function currentProcessServers(): array
    {
        if (self::$activeWorkerServerId === null) {
            return self::$servers;
        }

        $server = self::$servers[self::$activeWorkerServerId] ?? null;
        return $server === null ? [] : [self::$activeWorkerServerId => $server];
    }

    /** Best-effort process titles для ps/top/system observability. */
    protected static function setProcessTitle(string $title): void
    {
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    protected static function initializeRuntime(): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        self::$startFile = $trace[array_key_last($trace)]['file'] ?? ($_SERVER['SCRIPT_FILENAME'] ?? 'server.php');
        self::$startArguments = is_array($_SERVER['argv'] ?? null)
            ? array_values($_SERVER['argv'])
            : [self::$startFile, 'start'];
        self::$hotUpgradeBootstrap = (string)getenv(HotUpgradeBroker::ENV_PATH) !== ''
            && (string)getenv(HotUpgradeBroker::ENV_TOKEN) !== '';

        $hash = substr(hash('sha256', self::$startFile), 0, 12);
        if (self::$pidFile === '') {
            self::$pidFile = sys_get_temp_dir() . '/localzet-server-' . $hash . '.pid';
        }
        if (self::$statusFile === '') {
            self::$statusFile = sys_get_temp_dir() . '/localzet-server-' . $hash . '.status';
        }
        if (self::$logFile === '') {
            self::$logFile = dirname(self::$startFile) . '/localzet-server.log';
        }
    }

    /**
     * Захватывает lock конкретного entry script и не позволяет второму master
     * перезаписать PID/status уже работающего экземпляра.
     */
    protected static function acquireStartupLock(): void
    {
        if (self::$pidLockHandle !== null) {
            return;
        }

        $lockFile = self::$pidFile . '.lock';
        $handle = @fopen($lockFile, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException("Unable to open Localzet startup lock: {$lockFile}");
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $pid = is_file(self::$pidFile) ? (int)trim((string)@file_get_contents(self::$pidFile)) : 0;
            $suffix = $pid > 0 ? " (PID {$pid})" : '';
            throw new RuntimeException('Localzet Server is already starting or running' . $suffix . '.');
        }

        // Старый Server мог не использовать lock. Поэтому после успешного flock
        // отдельно проверяем legacy PID-файл и не стартуем поверх живого master.
        $previousPid = is_file(self::$pidFile) ? (int)trim((string)@file_get_contents(self::$pidFile)) : 0;
        if ($previousPid > 0 && self::isProcessAlive($previousPid)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException("Localzet Server is already running (PID {$previousPid}).");
        }

        self::$pidLockHandle = $handle;
        if ($previousPid > 0) {
            @unlink(self::$pidFile);
        }
        self::cleanupStatusFiles();
    }

    protected static function releaseStartupLock(): void
    {
        if (!is_resource(self::$pidLockHandle)) {
            self::$pidLockHandle = null;
            return;
        }
        @flock(self::$pidLockHandle, LOCK_UN);
        @fclose(self::$pidLockHandle);
        self::$pidLockHandle = null;
    }

    protected static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return false;
    }

    /**
     * Ждёт завершения старого master во время restart. Если supervisor не смог
     * остановиться за свой stopTimeout, control process делает последний SIGKILL,
     * иначе новый master рискует стартовать поверх занятого listener/PID lock.
     */
    protected static function waitForProcessExit(int $pid, float $timeout): void
    {
        $deadline = microtime(true) + max(0.1, $timeout);
        while (self::isProcessAlive($pid) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (!self::isProcessAlive($pid)) {
            return;
        }

        if (defined('SIGKILL')) {
            @posix_kill($pid, SIGKILL);
            $killDeadline = microtime(true) + 1.0;
            while (self::isProcessAlive($pid) && microtime(true) < $killDeadline) {
                usleep(20_000);
            }
        }

        if (self::isProcessAlive($pid)) {
            throw new RuntimeException("Unable to stop previous Localzet master PID {$pid}.");
        }
    }

    protected static function checkEnvironment(): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'micro', 'embed'], true)) {
            throw new RuntimeException('Localzet Server must run in CLI-like SAPI.');
        }
        if (PHP_VERSION_ID < 80100) {
            throw new RuntimeException('PHP 8.1 or newer is required.');
        }
    }

    protected static function parseCommand(): void
    {
        global $argv;
        $command = $argv[1] ?? 'start';
        self::$gracefulStop = in_array('-g', $argv ?? [], true);
        self::$daemonize = self::$daemonize || in_array('-d', $argv ?? [], true);
        self::$controlJson = in_array('--json', $argv ?? [], true);

        if (in_array($command, ['-h', '--help'], true)) {
            $command = 'help';
        } elseif (in_array($command, ['-V', '--version'], true)) {
            $command = 'version';
        }

        $allowed = ['start', 'stop', 'restart', 'reload', 'upgrade', 'status', 'connections', 'capabilities', 'help', 'version'];
        if (!in_array($command, $allowed, true)) {
            throw new RuntimeException("Unknown Localzet command '{$command}'. Run 'php " . basename(self::$startFile) . " help'.");
        }
        self::$command = $command;
    }

    protected static function executeControlCommand(): void
    {
        $pid = is_file(self::$pidFile) ? (int)trim((string)file_get_contents(self::$pidFile)) : 0;
        $alive = $pid > 0 && DIRECTORY_SEPARATOR === '/' && function_exists('posix_kill') && @posix_kill($pid, 0);

        switch (self::$command) {
            case 'help':
                self::displayCommandHelp();
                return;
            case 'version':
                echo 'Localzet Server ' . self::VERSION . PHP_EOL;
                return;
            case 'capabilities':
                self::displayCapabilities();
                return;
            case 'status':
            case 'connections':
                if (!$alive) {
                    echo self::$controlJson
                        ? json_encode(['running' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
                        : "Localzet Server is not running\n";
                    return;
                }
                self::displayRuntimeStatus(self::$command === 'connections');
                return;
            case 'stop':
                if (!$alive) {
                    echo "Localzet Server is not running\n";
                    return;
                }
                @posix_kill($pid, self::$gracefulStop && defined('SIGQUIT') ? SIGQUIT : SIGINT);
                return;
            case 'reload':
                if (!$alive) throw new RuntimeException('Localzet Server is not running.');
                $signal = self::$gracefulStop && defined('SIGUSR2') ? SIGUSR2 : SIGUSR1;
                @posix_kill($pid, $signal);
                return;
            case 'upgrade':
                if (!$alive) {
                    throw new RuntimeException('Localzet Server is not running.');
                }
                $status = self::readJsonFile(self::$statusFile);
                if (!(bool)($status['capabilities']['hot_upgrade'] ?? false)) {
                    throw new RuntimeException(
                        'The running Localzet master does not advertise hot-upgrade support. '
                        . 'Use `restart -g` once when upgrading from an older Server version.'
                    );
                }
                if (!defined('SIGHUP')) {
                    throw new RuntimeException('SIGHUP is not available on this platform.');
                }
                @posix_kill($pid, SIGHUP);
                return;
            case 'restart':
                if ($alive) {
                    $signal = self::$gracefulStop && defined('SIGQUIT') ? SIGQUIT : SIGINT;
                    @posix_kill($pid, $signal);
                    self::waitForProcessExit($pid, self::$stopTimeout + 1.0);
                }
                self::$command = 'start';
                if (isset($GLOBALS['argv'][1])) {
                    $GLOBALS['argv'][1] = 'start';
                }
                self::runAll();
                return;
        }
    }

    protected static function displayCommandHelp(): void
    {
        $script = basename(self::$startFile ?: 'server.php');
        echo 'Localzet Server ' . self::VERSION . PHP_EOL . PHP_EOL;
        echo "Usage:\n"
            . "  php {$script} start [-d]\n"
            . "  php {$script} stop [-g]\n"
            . "  php {$script} restart [-g] [-d]\n"
            . "  php {$script} reload [-g]\n"
            . "  php {$script} upgrade\n"
            . "  php {$script} status [--json]\n"
            . "  php {$script} connections [--json]\n"
            . "  php {$script} capabilities [--json]\n"
            . "  php {$script} version\n"
            . "  php {$script} help\n\n"
            . "Options:\n"
            . "  -d       daemonize on Unix-like systems\n"
            . "  -g       graceful stop/restart/reload\n"
            . "  --json   machine-readable status/connections/capabilities output\n\n"
            . "Notes:\n"
            . "  reload replaces workers from the already-running master image. Use restart\n"
            . "  after deploying changed PHP code when definitions may already be loaded in master.\n"
            . "  upgrade re-execs the master without rebinding listeners when runtime capabilities allow it.\n";
    }

    /**
     * Возвращает capabilities текущего PHP runtime, которые важны для Localzet.
     *
     * Команда `capabilities` использует тот же источник данных, что и status,
     * поэтому deployment scripts могут принимать решения без парсинга php -m.
     *
     * @return array<string,mixed>
     */
    protected static function runtimeCapabilities(): array
    {
        return [
            'fork' => DIRECTORY_SEPARATOR === '/' && function_exists('pcntl_fork'),
            'exec' => DIRECTORY_SEPARATOR === '/' && function_exists('pcntl_exec'),
            'signals' => DIRECTORY_SEPARATOR === '/' && function_exists('pcntl_signal'),
            'posix' => extension_loaded('posix'),
            'sockets' => extension_loaded('sockets'),
            'openssl' => extension_loaded('openssl'),
            'zlib' => extension_loaded('zlib'),
            'hot_upgrade' => HotUpgradeBroker::isSupported(),
            'event_loops' => EventLoopFactory::capabilities(),
        ];
    }

    /** Выводит capabilities в человекочитаемом или JSON формате. */
    protected static function displayCapabilities(): void
    {
        $capabilities = self::runtimeCapabilities();
        if (self::$controlJson) {
            echo json_encode(
                    $capabilities,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL;
            return;
        }

        echo 'Localzet Server ' . self::VERSION . PHP_EOL;
        echo 'Hot upgrade: ' . ($capabilities['hot_upgrade'] ? 'yes' : 'no') . PHP_EOL;
        echo 'Fork/exec: ' . ($capabilities['fork'] ? 'yes' : 'no')
            . '/' . ($capabilities['exec'] ? 'yes' : 'no') . PHP_EOL;
        echo 'Event loops:' . PHP_EOL;
        foreach ($capabilities['event_loops'] as $name => $available) {
            echo '  ' . str_pad($name, 10) . ($available ? 'available' : 'unavailable') . PHP_EOL;
        }
    }

    /**
     * Стабильная идентичность listening endpoint для проверки hot-upgrade topology.
     *
     * Имя worker и application protocol сюда намеренно не входят: их можно менять
     * между generations без rebinding socket. Менять сам listen address/transport
     * через zero-downtime upgrade нельзя — для этого используется restart -g.
     */
    protected function hotUpgradeIdentity(int $index): string
    {
        if ($this->socketName === '') {
            return 'nosocket:' . $index;
        }
        return hash('sha256', $this->transport . '|' . $this->socketName);
    }

    /**
     * Формирует supervisor topology, которую новый PHP image восстановит после exec.
     *
     * @return array<string,mixed>
     */
    protected static function buildHotUpgradeMetadata(): array
    {
        $servers = [];
        foreach (array_values(self::$servers) as $index => $server) {
            $workers = [];
            foreach (self::$pidMap[$server->serverObjectId] ?? [] as $pid) {
                $workers[] = [
                    'pid' => $pid,
                    'logical_id' => self::$childIdMap[$pid] ?? 0,
                    'started_at' => self::$childStartedAt[$pid] ?? microtime(true),
                ];
            }
            $servers[] = [
                'index' => $index,
                'identity' => $server->hotUpgradeIdentity($index),
                'socket_name' => $server->socketName,
                'transport' => $server->transport,
                'workers' => $workers,
            ];
        }

        return [
            'version' => self::VERSION,
            'master_pid' => self::$masterPid,
            'master_started_at' => self::$masterStartedAt,
            'generation' => self::$generation,
            'servers' => $servers,
        ];
    }

    /**
     * Запускает новый PHP image в том же master PID без остановки listeners.
     *
     * Алгоритм:
     *  1. fork broker, который временно удерживает startup-lock и listen sockets;
     *  2. broker публикует Unix control socket с одноразовым random token;
     *  3. master делает pcntl_exec(PHP_BINARY, ...), сохраняя PID;
     *  4. новый bootstrap получает descriptors через SCM_RIGHTS;
     *  5. старые workers заменяются rolling-порядком уже новым master image.
     */
    protected static function performHotUpgrade(): void
    {
        self::$hotUpgradeRequested = false;

        if (!HotUpgradeBroker::isSupported()) {
            self::log('Hot upgrade requested, but this runtime does not support SCM_RIGHTS/pcntl_exec.');
            return;
        }
        if (!is_resource(self::$pidLockHandle)) {
            self::log('Hot upgrade aborted: startup lock is not available.');
            return;
        }

        $resources = ['startup-lock' => self::$pidLockHandle];
        foreach (array_values(self::$servers) as $index => $server) {
            if ($server->socketName !== '') {
                if (!is_resource($server->mainSocket)) {
                    self::log("Hot upgrade aborted: listener #{$index} is not open.");
                    return;
                }
                $resources['listener:' . $index] = $server->mainSocket;
            }
        }

        try {
            $ticket = HotUpgradeBroker::fork(self::buildHotUpgradeMetadata(), $resources);
        } catch (Throwable $e) {
            self::log($e);
            return;
        }

        self::$status = self::STATUS_RELOADING;
        self::writeMasterStatusSnapshot();
        self::log(sprintf(
            'Hot upgrade generation %d -> %d requested for master PID %d.',
            self::$generation,
            self::$generation + 1,
            self::$masterPid
        ));

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment[HotUpgradeBroker::ENV_PATH] = $ticket['path'];
        $environment[HotUpgradeBroker::ENV_TOKEN] = $ticket['token'];

        // Повторный bootstrap должен видеть тот же application argv, но master уже
        // daemonized (если требовалось), поэтому control-only flags не повторяем.
        $arguments = self::$startArguments ?: [self::$startFile, 'start'];
        $arguments[0] = self::$startFile;
        if (isset($arguments[1]) && !str_starts_with((string)$arguments[1], '-')) {
            $arguments[1] = 'start';
        } else {
            array_splice($arguments, 1, 0, ['start']);
        }
        $arguments = array_values(array_filter(
            $arguments,
            static fn(string $argument, int $index): bool => $index < 2 || !in_array($argument, ['-d', '-g', '--json'], true),
            ARRAY_FILTER_USE_BOTH
        ));

        // Успешный pcntl_exec() не возвращается. Если управление продолжилось,
        // exec завершился ошибкой и старый master обязан остаться работоспособным.
        @pcntl_exec(PHP_BINARY, $arguments, $environment);

        $error = error_get_last();
        if (defined('SIGTERM')) {
            @posix_kill($ticket['pid'], SIGTERM);
        }
        @unlink($ticket['path']);
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
        self::log('Hot upgrade exec failed: ' . ($error['message'] ?? 'unknown pcntl_exec error'));
    }

    /**
     * Восстанавливает master после pcntl_exec() и запускает rolling replacement.
     */
    protected static function adoptHotUpgradeRuntime(): void
    {
        if (!HotUpgradeBroker::isSupported()) {
            throw new RuntimeException('This PHP image cannot receive Localzet hot-upgrade descriptors.');
        }

        $transfer = HotUpgradeBroker::receiveFromEnvironment();
        $metadata = $transfer['metadata'];
        $resources = $transfer['resources'];

        self::$masterPid = getmypid() ?: 0;
        if ((int)($metadata['master_pid'] ?? 0) !== self::$masterPid) {
            throw new RuntimeException('Hot-upgrade master PID changed unexpectedly.');
        }

        $startupLock = $resources['startup-lock'] ?? null;
        if (!is_resource($startupLock)) {
            throw new RuntimeException('Hot-upgrade startup lock descriptor was not received.');
        }
        self::$pidLockHandle = $startupLock;
        self::$generation = max(1, (int)($metadata['generation'] ?? 1) + 1);
        self::$masterStartedAt = (float)($metadata['master_started_at'] ?? microtime(true));
        self::$masterStopping = false;
        self::$masterReloading = false;
        self::$pendingRestarts = [];
        self::$restartState = [];
        self::$reloadQueue = [];
        self::$reloadCurrent = null;
        self::setProcessTitle('localzet: master ' . basename(self::$startFile));
        @file_put_contents(self::$pidFile, (string)self::$masterPid, LOCK_EX);

        $oldServers = is_array($metadata['servers'] ?? null) ? array_values($metadata['servers']) : [];
        $newServers = array_values(self::$servers);
        $topologyMatches = count($oldServers) === count($newServers);

        if ($topologyMatches) {
            foreach ($newServers as $index => $server) {
                $oldIdentity = (string)($oldServers[$index]['identity'] ?? '');
                if ($oldIdentity !== $server->hotUpgradeIdentity($index)) {
                    $topologyMatches = false;
                    break;
                }
            }
        }

        if (!$topologyMatches) {
            self::recoverFromHotUpgradeTopologyChange($metadata, $resources);
            return;
        }

        self::$pidMap = [];
        self::$childIdMap = [];
        self::$childStartedAt = [];

        foreach ($newServers as $index => $server) {
            self::$pidMap[$server->serverObjectId] = [];

            if ($server->socketName !== '') {
                $listener = $resources['listener:' . $index] ?? null;
                if (!is_resource($listener)) {
                    throw new RuntimeException("Hot-upgrade listener #{$index} was not received.");
                }
                stream_set_blocking($listener, false);
                $server->mainSocket = $listener;
                $server->pauseAccept = true;
            }

            foreach ($oldServers[$index]['workers'] ?? [] as $worker) {
                $pid = (int)($worker['pid'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                self::$pidMap[$server->serverObjectId][$pid] = $pid;
                self::$childIdMap[$pid] = (int)($worker['logical_id'] ?? 0);
                self::$childStartedAt[$pid] = (float)($worker['started_at'] ?? microtime(true));
            }
        }

        self::installMasterSignals();
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
        self::log(sprintf(
            'Hot upgrade adopted generation %d in master PID %d; starting rolling worker replacement.',
            self::$generation,
            self::$masterPid
        ));

        // Все унаследованные workers исполняют старый PHP image. Заменяем их
        // строго по одному, чтобы listener всё время оставался обслуживаемым.
        self::signalReload(true);
        self::monitorChildren();
    }

    /**
     * Safety fallback на случай изменения listener topology между generations.
     *
     * Zero-downtime гарантия распространяется только на неизменный набор listen
     * endpoints. Если bootstrap изменил адрес/transport, новый master аккуратно
     * дренирует старых workers и выполняет обычный bind уже новой topology вместо
     * того, чтобы оставить orphan processes или silently использовать старый port.
     *
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $resources
     */
    protected static function recoverFromHotUpgradeTopologyChange(array $metadata, array $resources): void
    {
        self::log('Hot-upgrade listener topology changed; falling back to graceful topology restart.');

        $oldPids = [];
        foreach ($metadata['servers'] ?? [] as $server) {
            foreach ($server['workers'] ?? [] as $worker) {
                $pid = (int)($worker['pid'] ?? 0);
                if ($pid > 0) {
                    $oldPids[] = $pid;
                }
            }
        }

        $signal = defined('SIGQUIT') ? SIGQUIT : SIGTERM;
        foreach ($oldPids as $pid) {
            @posix_kill($pid, $signal);
        }

        $deadline = microtime(true) + max(0.1, self::$stopTimeout);
        while ($oldPids !== [] && microtime(true) < $deadline) {
            $status = 0;
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid > 0) {
                $oldPids = array_values(array_filter($oldPids, static fn(int $candidate): bool => $candidate !== $pid));
                continue;
            }
            usleep(20_000);
        }
        foreach ($oldPids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        while (pcntl_wait($status, WNOHANG) > 0) {
            // Reap остатки старой generation до создания новых workers.
        }

        foreach ($resources as $label => $resource) {
            if ($label !== 'startup-lock' && is_resource($resource)) {
                @fclose($resource);
            }
        }

        self::$pidMap = [];
        self::$childIdMap = [];
        self::$childStartedAt = [];
        foreach (self::$servers as $server) {
            self::$pidMap[$server->serverObjectId] = [];
            $server->mainSocket = null;
            $server->pauseAccept = true;
            $server->listen();
        }

        self::installMasterSignals();
        self::forkAllServers();
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
        self::monitorChildren();
    }

    protected static function runSingleProcess(): void
    {
        self::$masterPid = getmypid() ?: 0;
        self::$activeWorkerServerId = null;
        self::setProcessTitle('localzet: single ' . basename(self::$startFile));
        self::$globalEvent = self::createEventLoop();
        self::$globalEvent->setErrorHandler(static fn(Throwable $e) => self::log($e));
        Timer::init(self::$globalEvent);

        foreach (self::$servers as $server) {
            $server->listen();
            if ($server->onServerStart !== null) {
                ($server->onServerStart)($server);
            }
            self::emitLifecycleEvent('Server::Start', $server);
            self::setupWorkerRuntime($server);
        }
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
        self::displayStartInfo();
        self::$globalEvent->run();

        // В single-process режиме нет master wait-loop, поэтому финальную уборку
        // выполняем здесь после остановки event loop.
        @unlink(self::$pidFile);
        self::cleanupStatusFiles();
        self::releaseStartupLock();
        if (self::$onMasterStop !== null) {
            try {
                (self::$onMasterStop)();
            } catch (Throwable $e) {
                self::log($e);
            }
        }
        self::emitLifecycleEvent('Server::Master::Stop');
    }

    protected static function forkAllServers(): void
    {
        foreach (self::$servers as $server) {
            for ($i = 0; $i < max(1, $server->count); $i++) {
                self::forkOne($server, $i);
            }
        }
    }

    protected static function forkOne(self $server, int $id): void
    {
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new RuntimeException('pcntl_fork failed.');
        }
        if ($pid > 0) {
            self::$pidMap[$server->serverObjectId][$pid] = $pid;
            self::$childIdMap[$pid] = $id;
            self::$childStartedAt[$pid] = microtime(true);
            self::writeMasterStatusSnapshot();
            return;
        }

        // Child process.
        //
        // startup flock принадлежит master supervisor. После fork файловый
        // descriptor наследуется worker'ом и ссылается на тот же open-file
        // description. Если worker оставит его открытым, аварийно погибший master
        // не освободит lock: живые workers заблокируют последующий recovery start.
        // Здесь descriptor только закрывается — LOCK_UN вызывать нельзя, потому что
        // это сняло бы общий flock также у master.
        if (is_resource(self::$pidLockHandle)) {
            @fclose(self::$pidLockHandle);
            self::$pidLockHandle = null;
        }

        self::$masterPid = (int)(posix_getppid() ?: 0);
        self::$activeWorkerServerId = $server->serverObjectId;
        self::setProcessTitle(sprintf('localzet: worker %s #%d', $server->name, $id));
        foreach (self::$servers as $candidate) {
            if ($candidate !== $server) {
                $candidate->unlisten();
            }
        }
        $server->id = $id;
        self::$globalEvent = self::createEventLoop($server->eventLoop);
        self::$globalEvent->setErrorHandler(static fn(Throwable $e) => self::log($e));
        Timer::init(self::$globalEvent);
        self::installChildSignals($server);
        self::dropPrivileges($server);
        $server->listen();
        if ($server->onServerStart !== null) {
            ($server->onServerStart)($server);
        }
        self::emitLifecycleEvent('Server::Start', $server);
        self::setupWorkerRuntime($server);
        self::$status = self::STATUS_RUNNING;
        self::$globalEvent->run();
        exit(0);
    }

    protected static function monitorChildren(): void
    {
        // Используем WNOHANG, чтобы PHP регулярно возвращался из waitpid и мог
        // гарантированно выполнить async signal handlers. На некоторых Unix-сборках
        // блокирующий wait() автоматически перезапускается после сигнала, из-за чего
        // master успевает пометить shutdown только после выхода одного из workers.
        while (true) {
            self::runDueRestarts();

            if (self::$hotUpgradeRequested && !self::$masterStopping && !self::$masterReloading) {
                self::performHotUpgrade();
            }

            $status = 0;
            $pid = pcntl_wait($status, WNOHANG);

            if ($pid > 0) {
                self::handleChildExit($pid, $status);
                continue;
            }

            if (self::$reloadCurrent !== null
                && self::$reloadCurrent['graceful']
                && microtime(true) - self::$reloadCurrent['started_at'] >= self::$stopTimeout) {
                @posix_kill(self::$reloadCurrent['pid'], SIGKILL);
                // Не сбрасываем reloadCurrent здесь: handleChildExit() завершит шаг
                // и только после reap поднимет replacement worker.
                self::$reloadCurrent['started_at'] = PHP_FLOAT_MAX;
            }

            if (self::$masterStopping) {
                if (!self::hasChildProcesses()) {
                    break;
                }

                // Graceful shutdown ограничен stopTimeout. Если worker завис внутри
                // пользовательского callback или системного вызова, master не должен
                // оставаться вечным zombie-supervisor'ом.
                if (self::$masterStopStartedAt > 0
                    && microtime(true) - self::$masterStopStartedAt >= self::$stopTimeout) {
                    foreach (self::$pidMap as $pids) {
                        foreach ($pids as $childPid) {
                            @posix_kill($childPid, SIGKILL);
                        }
                    }
                }
            }

            // -1 означает, что дочерних процессов уже нет.
            if ($pid === -1 && self::$masterStopping) {
                break;
            }

            usleep(50_000);
        }

        // Забираем возможные SIGKILL-exits, чтобы master не оставлял zombies.
        while (($pid = pcntl_wait($status, WNOHANG)) > 0) {
            self::handleChildExit($pid, $status, false);
        }

        foreach (self::$servers as $server) {
            $server->unlisten();
        }
        @unlink(self::$pidFile);
        self::cleanupStatusFiles();
        self::releaseStartupLock();
        if (self::$onMasterStop !== null) {
            try {
                (self::$onMasterStop)();
            } catch (Throwable $e) {
                self::log($e);
            }
        }
        self::emitLifecycleEvent('Server::Master::Stop');
    }

    protected static function handleChildExit(int $pid, int $status, bool $restart = true): void
    {
        $owner = null;
        foreach (self::$pidMap as $serverId => &$pids) {
            if (isset($pids[$pid])) {
                unset($pids[$pid]);
                $owner = self::$servers[$serverId] ?? null;
                break;
            }
        }
        unset($pids);

        if ($owner === null) {
            unset(self::$childIdMap[$pid], self::$childStartedAt[$pid]);
            return;
        }

        $callback = self::$onServerExit;
        if ($callback !== null) {
            try {
                $callback($owner, $status, $pid);
            } catch (Throwable $e) {
                self::log($e);
            }
        }
        self::emitLifecycleEvent('Server::Exit', [
            'server' => $owner,
            'status' => $status,
            'pid' => $pid,
        ]);

        $logicalId = self::$childIdMap[$pid] ?? 0;
        $startedAt = self::$childStartedAt[$pid] ?? microtime(true);
        $runtime = max(0.0, microtime(true) - $startedAt);
        unset(self::$childIdMap[$pid], self::$childStartedAt[$pid]);
        self::removeWorkerStatusFile($pid);

        $wasReloadTarget = self::$reloadCurrent !== null && self::$reloadCurrent['pid'] === $pid;
        $cleanExit = function_exists('pcntl_wifexited')
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0;
        $crashed = !$wasReloadTarget && !$cleanExit;

        // Planned reload/recycle exits restart immediately. Только аварийные
        // non-zero/signal exits проходят через exponential backoff.
        if ($restart && !self::$masterStopping) {
            if ($crashed) {
                self::scheduleCrashRestart($owner, $logicalId, $runtime);
            } else {
                self::resetRestartStateIfStable($owner, $logicalId, $runtime);
                self::forkOne($owner, $logicalId);
            }
        }

        if ($wasReloadTarget) {
            self::$reloadCurrent = null;
            self::advanceRollingReload();
        } else {
            // Worker мог умереть сам ещё до своей очереди reload. Новый процесс уже
            // считается replacement, поэтому старый PID из очереди можно удалить.
            self::$reloadQueue = array_values(array_filter(
                self::$reloadQueue,
                static fn(array $item): bool => $item['pid'] !== $pid
            ));
        }

        self::writeMasterStatusSnapshot();
    }

    protected static function restartKey(self $server, int $logicalId): string
    {
        return $server->serverObjectId . ':' . $logicalId;
    }

    protected static function resetRestartStateIfStable(self $server, int $logicalId, float $runtime): void
    {
        if ($runtime >= self::$stableWorkerTime) {
            unset(self::$restartState[self::restartKey($server, $logicalId)]);
        }
    }

    protected static function scheduleCrashRestart(self $server, int $logicalId, float $runtime): void
    {
        $key = self::restartKey($server, $logicalId);

        if ($runtime >= self::$stableWorkerTime) {
            unset(self::$restartState[$key]);
        }

        $state = self::$restartState[$key] ?? ['failures' => 0, 'last_crash' => 0.0];
        $failures = min(30, (int)$state['failures'] + 1);
        $base = max(0.0, self::$restartDelay);
        $cap = max($base, self::$maxRestartDelay);
        $delay = $base <= 0
            ? 0.0
            : min($cap, $base * (2 ** min(20, $failures - 1)));

        self::$restartState[$key] = [
            'failures' => $failures,
            'last_crash' => microtime(true),
        ];

        if ($delay <= 0) {
            self::forkOne($server, $logicalId);
            return;
        }

        self::$pendingRestarts[] = [
            'server_id' => $server->serverObjectId,
            'logical_id' => $logicalId,
            'due_at' => microtime(true) + $delay,
            'delay' => $delay,
            'failures' => $failures,
        ];

        self::log(sprintf(
            'Worker %s#%d crashed; restart in %.3fs (failure #%d).',
            $server->name,
            $logicalId,
            $delay,
            $failures
        ));
        self::writeMasterStatusSnapshot();
    }

    protected static function runDueRestarts(): void
    {
        if (self::$masterStopping || self::$pendingRestarts === []) {
            return;
        }

        $now = microtime(true);
        $remaining = [];
        foreach (self::$pendingRestarts as $restart) {
            if ($restart['due_at'] > $now) {
                $remaining[] = $restart;
                continue;
            }

            $server = self::$servers[$restart['server_id']] ?? null;
            if ($server === null) {
                continue;
            }

            // Если logical slot уже занят (например, другим recovery path),
            // устаревший pending restart не должен создавать лишний worker.
            $occupied = false;
            foreach (self::$pidMap[$restart['server_id']] ?? [] as $pid) {
                if ((self::$childIdMap[$pid] ?? null) === $restart['logical_id']) {
                    $occupied = true;
                    break;
                }
            }

            if (!$occupied) {
                self::forkOne($server, $restart['logical_id']);
            }
        }

        self::$pendingRestarts = $remaining;
        self::writeMasterStatusSnapshot();
    }

    protected static function hasChildProcesses(): bool
    {
        foreach (self::$pidMap as $pids) {
            if ($pids !== []) {
                return true;
            }
        }
        return false;
    }

    protected static function installMasterSignals(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static fn() => self::signalStop(false));
        // SIGTERM is the normal stop signal from systemd/Docker/Kubernetes. Treat
        // it as graceful by default so orchestrator shutdown does not cut requests.
        pcntl_signal(SIGTERM, static fn() => self::signalStop(true));
        if (defined('SIGQUIT')) pcntl_signal(SIGQUIT, static fn() => self::signalStop(true));
        if (defined('SIGPIPE')) pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGUSR1, static fn() => self::signalReload(false));
        if (defined('SIGUSR2')) {
            pcntl_signal(SIGUSR2, static fn() => self::signalReload(true));
        }
        if (defined('SIGHUP')) {
            // Signal handler только выставляет флаг. fork/SCM_RIGHTS/exec выполняются
            // из обычного monitor loop, а не из асинхронного signal callback.
            pcntl_signal(SIGHUP, static fn() => self::$hotUpgradeRequested = true);
        }
    }

    protected static function installChildSignals(self $server): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static fn() => self::stopAll());
        pcntl_signal(SIGTERM, static function (): void {
            self::$gracefulStop = true;
            self::stopAll();
        });
        if (defined('SIGQUIT')) pcntl_signal(SIGQUIT, static function (): void {
            self::$gracefulStop = true;
            self::stopAll();
        });
        if (defined('SIGPIPE')) pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGUSR1, static function () use ($server): void {
            self::reloadChild($server, false);
        });
        if (defined('SIGUSR2')) {
            pcntl_signal(SIGUSR2, static function () use ($server): void {
                self::reloadChild($server, true);
            });
        }
        if (defined('SIGWINCH')) {
            pcntl_signal(SIGWINCH, static fn() => self::writeWorkerStatusSnapshot($server, true));
        }
    }

    protected static function signalStop(bool $graceful): void
    {
        if (self::$masterStopping) return;
        self::$masterStopping = true;
        self::$masterStopStartedAt = microtime(true);
        self::$pendingRestarts = [];
        self::$gracefulStop = $graceful;
        self::$status = self::STATUS_SHUTDOWN;
        $signal = $graceful && defined('SIGQUIT') ? SIGQUIT : SIGINT;
        foreach (self::$pidMap as $pids) {
            foreach ($pids as $pid) @posix_kill($pid, $signal);
        }
    }

    protected static function signalReload(bool $graceful = false): void
    {
        if (self::$masterStopping || self::$masterReloading) {
            return;
        }

        self::$masterReloading = true;
        self::$status = self::STATUS_RELOADING;
        if (self::$onMasterReload !== null) {
            try {
                (self::$onMasterReload)();
            } catch (Throwable $e) {
                self::log($e);
            }
        }
        self::emitLifecycleEvent('Server::Master::Reload', ['graceful' => $graceful]);

        self::$reloadQueue = [];
        foreach (self::$pidMap as $serverId => $pids) {
            $server = self::$servers[$serverId] ?? null;
            if ($server === null || !$server->reloadable) {
                continue;
            }
            foreach ($pids as $pid) {
                self::$reloadQueue[] = [
                    'server_id' => $serverId,
                    'pid' => $pid,
                    'graceful' => $graceful,
                ];
            }
        }

        self::writeMasterStatusSnapshot();
        self::advanceRollingReload();
    }

    /**
     * Заменяет workers строго по одному. Следующий worker получает reload signal
     * только после reap + fork replacement предыдущего.
     */
    protected static function advanceRollingReload(): void
    {
        if (self::$masterStopping) {
            self::$reloadQueue = [];
            self::$reloadCurrent = null;
            self::$masterReloading = false;
            return;
        }

        while (($next = array_shift(self::$reloadQueue)) !== null) {
            $serverId = $next['server_id'];
            $pid = $next['pid'];
            if (!isset(self::$pidMap[$serverId][$pid])) {
                continue;
            }

            self::$reloadCurrent = $next + ['started_at' => microtime(true)];
            $signal = $next['graceful'] && defined('SIGUSR2') ? SIGUSR2 : SIGUSR1;
            @posix_kill($pid, $signal);
            self::writeMasterStatusSnapshot();
            return;
        }

        self::$reloadCurrent = null;
        self::$masterReloading = false;
        self::$status = self::STATUS_RUNNING;
        self::writeMasterStatusSnapshot();
    }

    /**
     * Завершает текущий worker после reload callback.
     *
     * Replacement создаётся master-процессом через fork(). Код, который worker
     * автозагрузит уже после fork, будет прочитан заново; определения, заранее
     * загруженные самим master, остаются его текущим process image. Для полного
     * перечитывания bootstrap-файлов используйте restart; zero-downtime re-exec
     * master является отдельным lifecycle-механизмом.
     */
    protected static function reloadChild(self $server, bool $graceful): void
    {
        if (!$server->reloadable || self::$status === self::STATUS_SHUTDOWN) {
            return;
        }

        self::$status = self::STATUS_RELOADING;
        if ($server->onServerReload !== null) {
            try {
                ($server->onServerReload)($server);
            } catch (Throwable $e) {
                self::log($e);
            }
        }
        self::emitLifecycleEvent('Server::Reload', $server);
        self::$gracefulStop = $graceful;
        self::$status = self::STATUS_RUNNING;
        self::stopAll();
    }

    /** Инициализирует worker-level limits, heartbeat и runtime telemetry. */
    protected static function setupWorkerRuntime(self $server): void
    {
        $server->workerStartedAt = microtime(true);
        $server->processedMessages = 0;
        $server->activeDispatches = [];
        $server->stopping = false;

        if ($server->maxLifetime > 0 || $server->maxMemory > 0) {
            $server->workerPolicyTimerId = Timer::repeat(1.0, static function () use ($server): void {
                if ($server->stopping) {
                    return;
                }
                if ($server->maxLifetime > 0 && $server->getWorkerUptime() >= $server->maxLifetime) {
                    $server->requestWorkerRecycle('max_lifetime');
                    return;
                }
                if ($server->maxMemory > 0 && memory_get_usage(true) >= $server->maxMemory) {
                    $server->requestWorkerRecycle('max_memory');
                }
            });
        }

        self::writeWorkerStatusSnapshot($server, false);
        if (self::$statusInterval > 0) {
            $server->statusTimerId = Timer::repeat(self::$statusInterval, static function () use ($server): void {
                self::writeWorkerStatusSnapshot($server, false);
            });
        }
    }

    /** Просит текущий worker завершиться после корректного drain соединений. */
    protected function requestWorkerRecycle(string $reason): void
    {
        if ($this->stopping || self::$status === self::STATUS_SHUTDOWN) {
            return;
        }
        $this->stopping = true;
        self::emitLifecycleEvent('Server::Recycle', [
            'server' => $this,
            'reason' => $reason,
            'processed_messages' => $this->processedMessages,
            'memory' => memory_get_usage(true),
            'uptime' => $this->getWorkerUptime(),
        ]);

        // Выходим из пользовательского callback прежде, чем менять process state.
        self::$globalEvent?->delay(0.0, static function (): void {
            self::$gracefulStop = true;
            self::stopAll();
        });
    }

    protected static function statusDirectory(): string
    {
        return self::$statusFile . '.d';
    }

    protected static function workerStatusFile(int $pid): string
    {
        return self::statusDirectory() . DIRECTORY_SEPARATOR . 'worker-' . $pid . '.json';
    }

    /** Atomic JSON writer: control process никогда не видит половину snapshot. */
    protected static function writeJsonAtomically(string $file, array $data): void
    {
        $directory = dirname($file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
        @unlink($tmp);
    }

    protected static function writeMasterStatusSnapshot(): void
    {
        if (self::$statusFile === '' || self::$masterPid <= 0 || getmypid() !== self::$masterPid) {
            return;
        }

        $servers = [];
        foreach (self::$servers as $serverId => $server) {
            $workers = [];
            foreach (self::$pidMap[$serverId] ?? [] as $pid) {
                $workers[] = [
                    'pid' => $pid,
                    'logical_id' => self::$childIdMap[$pid] ?? null,
                ];
            }
            $servers[] = [
                'id' => $serverId,
                'name' => $server->name,
                'listen' => $server->socketName,
                'transport' => $server->transport,
                'protocol' => $server->protocol,
                'configured_workers' => max(1, $server->count),
                'limits' => [
                    'max_connections' => $server->maxConnections,
                    'max_requests' => $server->maxRequests,
                    'max_memory' => $server->maxMemory,
                    'max_lifetime' => $server->maxLifetime,
                    'idle_timeout' => $server->idleTimeout,
                    'frame_timeout' => $server->frameTimeout,
                    'tls_handshake_timeout' => $server->tlsHandshakeTimeout,
                ],
                'workers' => $workers,
            ];
        }

        self::writeJsonAtomically(self::$statusFile, [
            'running' => !self::$masterStopping,
            'version' => self::VERSION,
            'generation' => self::$generation,
            'master_pid' => self::$masterPid,
            'capabilities' => self::runtimeCapabilities(),
            'state' => self::statusName(self::$status),
            'started_at' => self::$masterStartedAt,
            'uptime' => self::$masterStartedAt > 0 ? microtime(true) - self::$masterStartedAt : 0,
            'reload' => [
                'active' => self::$masterReloading,
                'current' => self::$reloadCurrent,
                'remaining' => count(self::$reloadQueue),
            ],
            'pending_restarts' => self::$pendingRestarts,
            'servers' => $servers,
            'updated_at' => microtime(true),
        ]);
    }

    protected static function writeWorkerStatusSnapshot(self $server, bool $includeConnections): void
    {
        $pid = getmypid() ?: 0;
        if ($pid <= 0 || $pid === self::$masterPid || self::$statusFile === '') {
            return;
        }

        $connections = [];
        foreach ($server->connections as $connection) {
            if ($includeConnections) {
                $connections[] = $connection->jsonSerialize();
            }
        }
        $statistics = ConnectionInterface::$statistics;

        self::writeJsonAtomically(self::workerStatusFile($pid), [
            'pid' => $pid,
            'master_pid' => self::$masterPid,
            'server_id' => $server->serverObjectId,
            'server_name' => $server->name,
            'logical_id' => $server->id,
            'state' => self::statusName(self::$status),
            'started_at' => $server->workerStartedAt,
            'uptime' => $server->getWorkerUptime(),
            'memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'processed_messages' => $server->processedMessages,
            'connections' => count($server->connections),
            'bytes_read' => (int)($statistics['bytes_read'] ?? 0),
            'bytes_written' => (int)($statistics['bytes_written'] ?? 0),
            'statistics' => $statistics,
            'event_loop' => self::$globalEvent !== null ? self::$globalEvent::class : null,
            'connection_details' => $includeConnections ? $connections : null,
            'updated_at' => microtime(true),
        ]);
    }

    protected static function removeWorkerStatusFile(int $pid): void
    {
        if (self::$statusFile !== '') {
            @unlink(self::workerStatusFile($pid));
        }
    }

    protected static function cleanupStatusFiles(): void
    {
        @unlink(self::$statusFile);
        $directory = self::statusDirectory();
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'worker-*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    protected static function statusName(int $status): string
    {
        return match ($status) {
            self::STATUS_INITIAL => 'initial',
            self::STATUS_STARTING => 'starting',
            self::STATUS_RUNNING => 'running',
            self::STATUS_SHUTDOWN => 'shutdown',
            self::STATUS_RELOADING => 'reloading',
            default => 'unknown',
        };
    }

    /** CLI status/connections: обновляет workers через SIGWINCH и собирает snapshots. */
    protected static function displayRuntimeStatus(bool $includeConnections): void
    {
        $master = self::readJsonFile(self::$statusFile) ?? [
            'running' => true,
            'master_pid' => (int)trim((string)@file_get_contents(self::$pidFile)),
            'servers' => [],
        ];

        $pids = [];
        foreach ($master['servers'] ?? [] as $server) {
            foreach ($server['workers'] ?? [] as $worker) {
                if (($worker['pid'] ?? 0) > 0) {
                    $pids[] = (int)$worker['pid'];
                }
            }
        }

        if (defined('SIGWINCH')) {
            $requestedAt = microtime(true);
            foreach ($pids as $pid) {
                @posix_kill($pid, SIGWINCH);
            }
            $deadline = $requestedAt + max(0.05, self::$statusRefreshTimeout);
            do {
                $fresh = 0;
                foreach ($pids as $pid) {
                    $snapshot = self::readJsonFile(self::workerStatusFile($pid));
                    if (($snapshot['updated_at'] ?? 0) >= $requestedAt) {
                        $fresh++;
                    }
                }
                if ($fresh >= count($pids)) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
        }

        $workers = [];
        foreach ($pids as $pid) {
            $snapshot = self::readJsonFile(self::workerStatusFile($pid));
            if ($snapshot !== null) {
                if (!$includeConnections) {
                    unset($snapshot['connection_details']);
                }
                $workers[] = $snapshot;
            }
        }

        $payload = ['master' => $master, 'workers' => $workers];
        if (self::$controlJson) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }

        if (!$includeConnections) {
            printf(
                "Localzet Server %s | master=%d | state=%s | uptime=%s | workers=%d\n",
                $master['version'] ?? self::VERSION,
                $master['master_pid'] ?? 0,
                $master['state'] ?? 'unknown',
                self::formatDuration((float)($master['uptime'] ?? 0)),
                count($workers)
            );
            printf("%-6s %-4s %-20s %-10s %-8s %-10s %-12s %-12s\n", 'PID', 'ID', 'SERVER', 'MEM', 'CONNS', 'MESSAGES', 'READ', 'WRITTEN');
            foreach ($workers as $worker) {
                printf(
                    "%-6d %-4d %-20s %-10s %-8d %-10d %-12s %-12s\n",
                    $worker['pid'] ?? 0,
                    $worker['logical_id'] ?? 0,
                    substr((string)($worker['server_name'] ?? '-'), 0, 20),
                    self::formatBytes((int)($worker['memory'] ?? 0)),
                    $worker['connections'] ?? 0,
                    $worker['processed_messages'] ?? 0,
                    self::formatBytes((int)($worker['bytes_read'] ?? 0)),
                    self::formatBytes((int)($worker['bytes_written'] ?? 0)),
                );
            }
            return;
        }

        printf("%-6s %-5s %-22s %-22s %-12s %-10s %-10s\n", 'PID', 'CID', 'REMOTE', 'LOCAL', 'STATUS', 'READ', 'WRITTEN');
        foreach ($workers as $worker) {
            foreach ($worker['connection_details'] ?? [] as $connection) {
                printf(
                    "%-6d %-5d %-22s %-22s %-12s %-10s %-10s\n",
                    $worker['pid'] ?? 0,
                    $connection['id'] ?? 0,
                    substr((string)($connection['remoteAddress'] ?? '-'), 0, 22),
                    substr((string)($connection['localAddress'] ?? '-'), 0, 22),
                    substr((string)($connection['status'] ?? '-'), 0, 12),
                    self::formatBytes((int)($connection['bytesRead'] ?? 0)),
                    self::formatBytes((int)($connection['bytesWritten'] ?? 0)),
                );
            }
        }
    }

    protected static function readJsonFile(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    protected static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . 'B';
        if ($bytes < 1024 ** 2) return number_format($bytes / 1024, 1) . 'K';
        if ($bytes < 1024 ** 3) return number_format($bytes / 1024 ** 2, 1) . 'M';
        return number_format($bytes / 1024 ** 3, 1) . 'G';
    }

    protected static function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int)$seconds);
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return intdiv($seconds, 60) . 'm' . ($seconds % 60) . 's';
        return intdiv($seconds, 3600) . 'h' . intdiv($seconds % 3600, 60) . 'm';
    }

    /**
     * Создаёт event loop через единую фабрику backend'ов.
     *
     * @param string|null $preferred Alias/FQCN конкретного worker или null для auto.
     */
    protected static function createEventLoop(?string $preferred = null): EventInterface
    {
        return EventLoopFactory::create($preferred ?: self::$eventLoopClass);
    }

    protected static function daemonizeProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid < 0) throw new RuntimeException('Unable to fork for daemon mode.');
        if ($pid > 0) exit(0);
        if (posix_setsid() < 0) throw new RuntimeException('Unable to create daemon session.');
        $pid = pcntl_fork();
        if ($pid < 0) throw new RuntimeException('Unable to fork daemon child.');
        if ($pid > 0) exit(0);
        chdir('/');
        umask(0);
    }

    protected static function dropPrivileges(self $server): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_getuid') || posix_getuid() !== 0) {
            return;
        }
        if ($server->user === '' && $server->group === '') {
            return;
        }

        $user = null;
        if ($server->user !== '') {
            $resolvedUser = posix_getpwnam($server->user);
            if ($resolvedUser === false) {
                throw new RuntimeException("Unable to resolve configured user '{$server->user}'.");
            }
            $user = $resolvedUser;
        }

        if ($server->group !== '') {
            $group = posix_getgrnam($server->group);
            if ($group === false) {
                throw new RuntimeException("Unable to resolve configured group '{$server->group}'.");
            }
            $targetGid = (int)$group['gid'];
        } elseif ($user !== null) {
            // Если указан только user, dropping UID без primary GID оставляет
            // процесс в группе root. Для server process это неожиданно и небезопасно.
            $targetGid = (int)$user['gid'];
        } else {
            $targetGid = null;
        }

        if ($targetGid !== null && !@posix_setgid($targetGid)) {
            throw new RuntimeException("Unable to switch worker group to GID {$targetGid}.");
        }

        if ($user !== null) {
            if (function_exists('posix_initgroups')
                && !@posix_initgroups((string)$user['name'], (int)$user['gid'])) {
                throw new RuntimeException("Unable to initialize supplementary groups for '{$server->user}'.");
            }
            if (!@posix_setuid((int)$user['uid'])) {
                throw new RuntimeException("Unable to switch worker user to '{$server->user}'.");
            }
        }
    }

    protected static function displayStartInfo(): void
    {
        if (self::$daemonize) return;
        fwrite(STDOUT, "Localzet Server " . self::VERSION . " started\n");
        foreach (self::$servers as $server) {
            fwrite(STDOUT, sprintf("  %-16s %-30s processes=%d\n", $server->name, $server->socketName ?: '-', max(1, $server->count)));
        }
    }

    /**
     * Serializes rotation + append across workers. FILE_APPEND|LOCK_EX alone
     * protects only one write and cannot make a preceding size-check/rename atomic.
     */
    protected static function appendLogLine(string $line): void
    {
        $lock = @fopen(self::$logFile . '.lock', 'c');
        if (!is_resource($lock)) {
            @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
            return;
        }

        try {
            if (!@flock($lock, LOCK_EX)) {
                @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
                return;
            }
            self::rotateLogIfNeeded();
            @file_put_contents(self::$logFile, $line, FILE_APPEND);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** Must be called while the log rotation lock is held. */
    protected static function rotateLogIfNeeded(): void
    {
        if (self::$logFile === '' || !is_file(self::$logFile) || self::$logFileMaxSize <= 0) {
            return;
        }
        clearstatcache(true, self::$logFile);
        $size = @filesize(self::$logFile);
        if ($size === false || $size < self::$logFileMaxSize) {
            return;
        }

        $archive = sprintf('%s.%s.%d', self::$logFile, date('Ymd-His'), getmypid() ?: 0);
        @rename(self::$logFile, $archive);
    }

    public function __destruct()
    {
        unset(self::$servers[$this->serverObjectId], self::$pidMap[$this->serverObjectId]);
        $this->unlisten();
    }
}
