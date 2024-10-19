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

namespace localzet;

use AllowDynamicProperties;
use Composer\InstalledVersions;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use localzet\Server\Connection\{ConnectionInterface, TcpConnection, UdpConnection};
use localzet\Server\Events\{EventInterface, Linux, Revolt, Windows};
use localzet\Server\Protocols\ProtocolInterface;
use Revolt\EventLoop;
use RuntimeException;
use stdClass;
use Throwable;
use function array_intersect;
use function current;
use function defined;
use function fflush;
use function floor;
use function function_exists;
use function fwrite;
use function get_resource_type;
use function is_resource;
use function lcfirst;
use function method_exists;
use function register_shutdown_function;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_accept;
use function stream_socket_recvfrom;
use function substr;
use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const LOCK_EX;
use const LOCK_UN;
use const PHP_EOL;
use const PHP_SAPI;
use const PHP_VERSION;
use const SIG_IGN;
use const SIGHUP;
use const SIGINT;
use const SIGIO;
use const SIGIOT;
use const SIGKILL;
use const SIGPIPE;
use const SIGQUIT;
use const SIGTERM;
use const SIGTSTP;
use const SIGUSR1;
use const SIGUSR2;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const STDERR;
use const STDOUT;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const TCP_NODELAY;
use const WUNTRACED;

/**
 * Localzet Server
 *
 * <code>
 * Localzet\Events = [
 *  'Server::Start' => fn($server = null){},
 *  'Server::Stop' => fn($server = null){},
 *  'Server::Reload' => fn($server = null){},
 *  'Server::Exit' => fn(['server', 'status', 'pid'] = []){},
 *
 *  'Server::Master::Stop' => fn(){},
 *  'Server::Master::Reload' => fn(){},
 * ]
 * </code>
 */
#[AllowDynamicProperties]
class Server
{
    /**
     * Version.
     *
     * @var string
     */
    final public const VERSION = '24.06.17';

    /**
     * Статус: запуск
     *
     * @var int
     */
    public const STATUS_STARTING = 1;

    /**
     * Статус: работает
     *
     * @var int
     */
    public const STATUS_RUNNING = 2;

    /**
     * Статус: остановка
     *
     * @var int
     */
    public const STATUS_SHUTDOWN = 4;

    /**
     * Статус: перезагрузка
     *
     * @var int
     */
    public const STATUS_RELOADING = 8;

    /**
     * Backlog по умолчанию. Backlog - максимальная длина очереди ожидающих соединений
     *
     * @var int
     */
    public const DEFAULT_BACKLOG = 102400;

    /**
     * Безопасное расстояние для соседних колонок
     *
     * @var int
     */
    public const UI_SAFE_LENGTH = 4;

    /**
     * Встроенные протоколы
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
     * Встроенные типы ошибок
     *
     * @var array<int,string>
     */
    public const ERROR_TYPE = [
        E_ERROR => 'E_ERROR', // 1
        E_WARNING => 'E_WARNING', // 2
        E_PARSE => 'E_PARSE', // 4
        E_NOTICE => 'E_NOTICE', // 8
        E_CORE_ERROR => 'E_CORE_ERROR', // 16
        E_CORE_WARNING => 'E_CORE_WARNING', // 32
        E_COMPILE_ERROR => 'E_COMPILE_ERROR', // 64
        E_COMPILE_WARNING => 'E_COMPILE_WARNING', // 128
        E_USER_ERROR => 'E_USER_ERROR', // 256
        E_USER_WARNING => 'E_USER_WARNING', // 512
        E_USER_NOTICE => 'E_USER_NOTICE', // 1024
        E_STRICT => 'E_STRICT', // 2048
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        E_DEPRECATED => 'E_DEPRECATED', // 8192
        E_USER_DEPRECATED => 'E_USER_DEPRECATED', // 16384
        // E_ALL => 'E_ALL', // 32767 (не включая E_STRICT)
    ];

    public const CONTEXT_SSL = [
        'LOCALZET_SSL_PEER_NAME' => 'peer_name',                            // Имя узла. Если его значение не задано, тогда имя подставляется основываясь на имени хоста, использованного при открытии потока.
        'LOCALZET_SSL_VERIFY_PEER' => 'verify_peer',                        // Требовать проверки используемого SSL-сертификата.
        'LOCALZET_SSL_VERIFY_PEER_NAME' => 'verify_peer_name',              // Требовать проверки имени узла.
        'LOCALZET_SSL_SELF_SIGNED' => 'allow_self_signed',                  // Разрешить самоподписанные сертификаты.
        'LOCALZET_SSL_CAFILE' => 'cafile',                                  // Расположение файла сертификата в локальной файловой системе, который следует использовать с опцией контекста verify_peer для проверки подлинности удалённого узла.
        'LOCALZET_SSL_CAPATH' => 'capath',                                  // Если параметр cafile не определён или сертификат не найден, осуществляется поиск в директории, указанной в capath. Путь capath должен быть к корректной директории, содержащей сертификаты, имена которых являются хешем от поля subject, указанного в сертификате.
        'LOCALZET_SSL_CERT' => 'local_cert',                                // Путь к локальному сертификату в файловой системе. Это должен быть файл, закодированный в PEM, который содержит ваш сертификат и закрытый ключ. Он дополнительно может содержать открытый ключ эмитента. Закрытый ключ также может содержаться в отдельном файле, заданным local_pk.
        'LOCALZET_SSL_CERT_KEY' => 'local_pk',                              // Путь к локальному файлу с приватным ключом в случае отдельных файлов сертификата (local_cert) и приватного ключа.
        'LOCALZET_SSL_CERT_PASS' => 'passphrase',                           // Идентификационная фраза, с которой ваш файл local_cert был закодирован.
        'LOCALZET_SSL_CERT_VERIFY_DEPTH' => 'verify_depth',                 // Прервать, если цепочка сертификата слишком длинная.
        'LOCALZET_SSL_CIPHERS' => 'ciphers',                                // Устанавливает список доступных алгоритмов шифрования.
        'LOCALZET_SSL_CAPTURE_CERT' => 'capture_peer_cert',                 // Если установлено в true, то будет создана опция контекста peer_certificate, содержащая сертификат удалённого узла.
        'LOCALZET_SSL_CAPTURE_CERT_CHAIN' => 'capture_peer_cert_chain',     // Если установлено в true, то будет создана опция контекста peer_certificate_chain, содержащая цепочку сертификатов.
        'LOCALZET_SSL_SNI' => 'SNI_enabled',                                // Если установлено в true, то будет включено указание имени сервера. Включение SNI позволяет использовать разные сертификаты на одном и том же IP-адресе.
        'LOCALZET_SSL_DISABLE_COMPRESSION' => 'disable_compression',        // Отключает сжатие TLS, что помогает предотвратить атаки типа CRIME.
        'LOCALZET_SSL_SECURITY_LEVEL' => 'security_level',                  // Устанавливает уровень безопасности. Если не указан, используется стандартный уровень безопасности, указанный в библиотеке.
        'LOCALZET_SSL_PEER_FINGERPRINT' => 'peer_fingerprint',              // Прерваться, если дайджест сообщения не совпадает с указанным хешом.
        // Если указана строка (string), то её длина определяет какой алгоритм хеширования будет использован: "md5" (32) или "sha1" (40).
        // Если указан массив (array), то ключи определяют алгоритм хеширования, а каждое соответствующее значение является требуемым хешом.
    ];

    /**
     * ID Сервера
     */
    public int $id = 0;

    /**
     * Название для серверных процессов
     */
    public string $name = 'none';

    /**
     * Количество серверных процессов
     */
    public int $count = 1;

    /**
     * Unix пользователь (нужен root)
     */
    public string $user = '';

    /**
     * Unix группа (нужен root)
     */
    public string $group = '';

    /**
     * Перезагружаемый экземпляр?
     */
    public bool $reloadable = true;

    /**
     * Повторно использовать порт?
     */
    public bool $reusePort = false;

    /**
     * Выполняется при запуске серверных процессов
     *
     * @var ?callable
     */
    public $onServerStart = null;

    /**
     * Выполняется, когда подключение к сокету успешно установлено
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Выполняется, когда завершено рукопожатие веб-сокета (работает только в протоколе ws)
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Выполняется при получении данных
     *
     * @var ?callable
     */
    public $onMessage = null;

    /**
     * Выполняется, когда другой конец сокета отправляет пакет FIN
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Выполняется, когда возникает ошибка с подключением
     *
     * @var ?callable
     */
    public $onError = null;

    /**
     * Выполняется, когда буфер отправки заполняется
     *
     * @var ?callable
     */
    public $onBufferFull = null;

    /**
     * Выполняется, когда буфер отправки становится пустым
     *
     * @var ?callable
     */
    public $onBufferDrain = null;

    /**
     * Выполняется при остановке сервера
     *
     * @var ?callable
     */
    public $onServerStop = null;

    /**
     * Выполняется при перезагрузке
     *
     * @var ?callable
     */
    public $onServerReload = null;

    /**
     * Протокол транспортного уровня
     */
    public string $transport = 'tcp';

    /**
     * Хранитель всех клиентских соединений
     *
     * @var TcpConnection[]
     */
    public array $connections = [];

    /**
     * Протокол уровня приложения
     */
    public ?string $protocol = null;

    /**
     * Пауза принятия новых соединений
     */
    protected bool $pauseAccept = true;

    /**
     * Сервер останавливается?
     */
    public bool $stopping = false;

    /**
     * В режиме демона?
     */
    public static bool $daemonize = false;

    /**
     * Поток стандартного вывода.
     * @var resource
     */
    public static $outputStream;

    /**
     * Файл Stdout
     */
    public static string $stdoutFile = '/dev/null';

    /**
     * Файл для хранения PID мастер-процесса
     */
    public static string $pidFile;

    /**
     * Файл, используемый для хранения файла состояния мастер-процесса
     */
    public static string $statusFile;

    /**
     * Файл лога
     */
    public static mixed $logFile;

    /**
     * Глобальная петля событий
     */
    public static ?EventInterface $globalEvent = null;

    /**
     * Выполняется при перезагруззке мастер-процесса
     *
     * @var ?callable
     */
    public static $onMasterReload = null;

    /**
     * Выполняется при остановке мастер-процесса
     *
     * @var ?callable
     */
    public static $onMasterStop = null;

    /**
     * Выполняется при выходе
     *
     * @var ?callable
     */
    public static $onServerExit = null;

    /**
     * Класс событийной петли
     *
     * @var class-string<EventInterface>
     */
    public static string $eventLoopClass;

    /**
     * Таймаут после команды остановки для дочерних процессов
     * Если в течение него они не остановятся - звони киллеру
     */
    public static int $stopTimeout = 2;

    /**
     * Команда
     */
    public static string $command = '';

    /**
     * Версия
     */
    protected static ?string $version = null;

    /**
     * PID мастер-процесса.
     */
    protected static int $masterPid = 0;

    /**
     * Слушающий сокет.
     *
     * @var ?resource
     */
    protected $mainSocket = null;

    /**
     * Имя сокета. Формат: http://0.0.0.0:80 .
     */
    protected string $socketName = '';

    /**
     * Контекст сокета.
     *
     * @var resource
     */
    protected $socketContext = null;

    protected stdClass $context;

    /**
     * Все экземпляры сервера.
     *
     * @var Server[]
     */
    protected static array $servers = [];

    /**
     * Все PID процессов серверов.
     * Формат: [идентификатор_сервера => [pid => pid, pid => pid, ...], ...]
     */
    protected static array $pidMap = [];

    /**
     * Все процессы серверов, ожидающие перезапуска.
     * Формат: [pid => pid, pid => pid, ...].
     */
    protected static array $pidsToRestart = [];

    /**
     * Отображение PID на идентификатор сервера.
     * Формат: [serverId => [0 => $pid, 1 => $pid, ...], ...].
     */
    protected static array $idMap = [];

    /**
     * Текущий статус.
     */
    protected static int $status = self::STATUS_STARTING;

    /**
     * Максимальная длина имени сервера.
     */
    protected static int $maxServerNameLength = 12;

    /**
     * Максимальная длина имени сокета.
     */
    protected static int $maxSocketNameLength = 12;

    /**
     * Максимальная длина имени пользователя.
     */
    protected static int $maxUserNameLength = 12;

    /**
     * Максимальная длина имени протокола.
     */
    protected static int $maxProtoNameLength = 4;

    /**
     * Максимальная длина имени процесса.
     */
    protected static int $maxProcessesNameLength = 9;

    /**
     * Максимальная длина имени состояния.
     */
    protected static int $maxStateNameLength = 1;

    /**
     * Файл для хранения информации о статусе текущего процесса сервера.
     */
    protected static string $statisticsFile;

    /**
     * Файл для хранения информации о соединениях.
     */
    protected static string $connectionsFile;

    /**
     * Файл запуска.
     */
    protected static string $startFile;

    /**
     * Процессы для операционных систем Windows.
     */
    protected static array $processForWindows = [];

    /**
     * Информация о статусе текущего процесса сервера.
     */
    protected static array $globalStatistics = [
        'start_timestamp' => 0,
        'server_exit_info' => []
    ];

    /**
     * Остановка сервера с грациозным завершением или нет.
     */
    protected static bool $gracefulStop = false;

    /**
     * Поддерживается ли у потока $outputStream декорация.
     */
    protected static bool $outputDecorated;

    /**
     * Хэш-идентификатор объекта сервера (уникальный идентификатор)
     */
    protected ?string $serverId = null;

    /**
     * Запуск всех экземпляров сервера
     *
     * @throws Throwable
     */
    public static function runAll(): void
    {
        try {
            static::checkSapiEnv();
            static::initStdOut();
            static::init();
            static::parseCommand();
            static::lock();
            static::daemonize();
            static::initServers();
            static::installSignal();
            static::saveMasterPid();
            static::lock(LOCK_UN);
            static::displayUI();
            static::forkServers();
            static::resetStd();
            static::monitorServers();
        } catch (Throwable $throwable) {
            static::log($throwable);
        }
    }

    /**
     * Проверка SAPI
     */
    protected static function checkSapiEnv(): void
    {
        // Только для CLI и Micro
        if (!in_array(PHP_SAPI, ['cli', 'micro'])) {
            exit("Localzet Server запускается только из терминала \n");
        }
    }

    protected static function initStdOut(): void
    {
        $defaultStream = fn() => defined('STDOUT') ? STDOUT : (@fopen('php://stdout', 'w') ?: fopen('php://output', 'w'));
        static::$outputStream ??= $defaultStream(); //@phpstan-ignore-line
        if (!is_resource(self::$outputStream) || get_resource_type(self::$outputStream) !== 'stream') {
            $type = get_debug_type(self::$outputStream);
            static::$outputStream = $defaultStream();
            throw new RuntimeException(sprintf('The $outputStream must to be a stream, %s given', $type));
        }

        static::$outputDecorated ??= self::hasColorSupport();
    }

    /**
     * Borrowed from the symfony console
     * @link https://github.com/symfony/console/blob/0d14a9f6d04d4ac38a8cea1171f4554e325dae92/Output/StreamOutput.php#L92
     */
    private static function hasColorSupport(): bool
    {
        // Follow https://no-color.org/
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('TERM_PROGRAM') === 'Hyper') {
            return true;
        }

        if (!is_unix()) {
            return (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(self::$outputStream))
                || getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('TERM') === 'xterm';
        }

        return stream_isatty(self::$outputStream);
    }

    public static function getVersion(): ?string
    {
        if (!self::$version) {
            self::$version = InstalledVersions::getPrettyVersion('localzet/server');
        }

        return self::$version;
    }

    /**
     * Инициализация
     */
    protected static function init(): void
    {
        Events::on('Server::Start', function (Server $server = null): void {
            if ($server?->onServerStart) {
                try {
                    ($server->onServerStart)($server);
                } catch (Throwable $e) {
                    // Избегаем бесконечного выхода из цикла.
                    sleep(1);
                    static::stopAll(250, $e);
                }
            }
        });

        Events::on('Server::Stop', function (Server $server = null): void {
            if ($server?->onServerStop) {
                try {
                    ($server->onServerStop)($server);
                } catch (Throwable $e) {
                    static::log($e);
                }
            }
        });

        Events::on('Server::Reload', function (Server $server = null): void {
            if ($server?->onServerReload) {
                try {
                    ($server->onServerReload)($server);
                } catch (Throwable $e) {
                    static::stopAll(250, $e);
                }
            }
        });

        Events::on('Server::Exit', function (array $data = []): void {
            extract($data);
            if (static::$onServerExit) {
                try {
                    (static::$onServerExit)($server, $status, $pid);
                } catch (Throwable $exception) {
                    static::log("<magenta>Localzet Server</magenta> <cyan>[$server->name]</cyan> onServerExit $exception");
                }
            }
        });

        Events::on('Server::Master::Stop', function (): void {
            if (static::$onMasterStop) {
                try {
                    (static::$onMasterStop)();
                } catch (Throwable $e) {
                    static::log($e);
                }
            }
        });

        Events::on('Server::Master::Reload', function (): void {
            if (static::$onMasterReload) {
                try {
                    (static::$onMasterReload)();
                } catch (Throwable $e) {
                    static::stopAll(250, $e);
                }

                static::initId();
            }
        });

        // Устанавливаем обработчик ошибок, который будет выводить сообщение об ошибке
        set_error_handler(static function (int $code, string $msg, string $file, int $line): bool {
            static::safeEcho(sprintf("%s \"%s\" в файле %s на строке %d\n", static::getErrorType($code), $msg, $file, $line));
            return true;
        });

        // Начало
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        static::$startFile ??= end($backtrace)['file'];
        $startFilePrefix = hash('xxh64', static::$startFile);

        // PID-файл
        static::$pidFile ??= sprintf('%s/localzet.%s.pid', dirname(__DIR__), $startFilePrefix);

        // Статус-файл
        static::$statusFile ??= sprintf('%s/localzet.%s.status', dirname(__DIR__), $startFilePrefix);
        static::$statisticsFile ??= static::$statusFile . '.statistic';
        static::$connectionsFile ??= static::$statusFile . '.connection';

        // Лог-файл
        static::$logFile ??= sprintf('%s/localzet.log', dirname(__DIR__, 2));

        if (!is_file(static::$logFile) && static::$logFile !== '/dev/null') {
            // Если папка /runtime/logs по умолчанию не существует
            if (!is_dir(dirname((string)static::$logFile))) {
                @mkdir(dirname((string)static::$logFile), 0777, true);
            }

            touch(static::$logFile);
            chmod(static::$logFile, 0644);
        }

        // Устанавливаем состояние в STATUS_STARTING
        static::$status = static::STATUS_STARTING;

        // Инициализация глобального события
        static::initGlobalEvent();

        // Для статистики
        static::$globalStatistics['start_timestamp'] = time();

        // Устанавливаем название процесса
        static::setProcessTitle('Localzet Server: мастер-процесс  start_file=' . static::$startFile);

        // Инициализируем данные для идентификатора сервера
        static::initId();

        // Инициализируем таймер
        Timer::init();
    }

    /**
     * Инициализация глобального события.
     */
    protected static function initGlobalEvent(): void
    {
        if (static::$globalEvent instanceof EventInterface) {
            static::$eventLoopClass = static::$globalEvent::class;
            static::$globalEvent = null;
            return;
        }

        if (!empty(static::$eventLoopClass)) {
            if (!is_subclass_of(static::$eventLoopClass, EventInterface::class)) {
                throw new RuntimeException(sprintf('%s::$eventLoopClass должен реализовывать %s', static::class, EventInterface::class));
            }

            return;
        }

        static::$eventLoopClass = match (true) {
            class_exists(EventLoop::class) => Revolt::class,
            default => is_unix() ? Linux::class : Windows::class
        };
    }

    /**
     * Блокировка.
     *
     * @param int $flag Флаг блокировки (по умолчанию LOCK_EX)
     */
    protected static function lock(int $flag = LOCK_EX): void
    {
        static $fd;

        // Проверяем, что используется UNIX-подобная операционная система
        if (!is_unix()) {
            return;
        }

        $lockFile = static::$pidFile . '.lock';

        // Открываем или создаем файл блокировки
        $fd = $fd ?: fopen($lockFile, 'a+');

        if ($fd) {
            // Блокируем файл
            flock($fd, $flag);

            // Если флаг равен LOCK_UN, то разблокируем файл и удаляем файл блокировки
            if ($flag === LOCK_UN) {
                fclose($fd);
                $fd = null;
                clearstatcache();
                if (is_file($lockFile)) {
                    unlink($lockFile);
                }
            }
        }
    }

    /**
     * Инициализация всех экземпляров сервера.
     *
     * @throws Exception
     */
    protected static function initServers(): void
    {
        // Проверяем, что используется UNIX-подобная операционная система
        if (!is_unix()) {
            return;
        }

        foreach (static::$servers as $server) {
            // Имя сервера.
            if (empty($server->name)) {
                $server->name = 'none';
            }

            // Получаем пользовательское имя UNIX-пользователя для процесса сервера.
            if (empty($server->user)) {
                $server->user = static::getCurrentUser();
            } elseif (posix_getuid() !== 0 && $server->user !== static::getCurrentUser()) {
                static::log('Внимание: Для изменения UID и GID вам нужны права root.');
            }

            // Имя сокета.
            $server->context->statusSocket = $server->getSocketName();

            // Состояние сервера.
            $server->context->statusState = '<green>[OK]</green>';

            // Получаем соответствие столбца для интерфейса пользователя.
            foreach (static::getUiColumns() as $columnName => $prop) {
                if (!isset($server->$prop) && !isset($server->context->$prop)) {
                    $server->context->$prop = 'NNNN';
                }

                $propLength = strlen((string)($server->$prop ?? $server->context->$prop));
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                static::$$key = max(static::$$key, $propLength);
            }

            // Начинаем прослушивание.
            if (!$server->reusePort) {
                $server->listen();
            }
        }
    }

    /**
     * Получить все экземпляры сервера.
     *
     * @return Server[]
     */
    public static function getAllServers(): array
    {
        return static::$servers;
    }

    /**
     * Получить глобальный экземпляр цикла событий.
     */
    public static function getEventLoop(): EventInterface
    {
        return static::$globalEvent;
    }

    /**
     * Получить основной ресурс сокета.
     *
     * @return resource
     */
    public function getMainSocket()
    {
        return $this->mainSocket;
    }

    /**
     * Инициализация idMap.
     */
    protected static function initId(): void
    {
        foreach (static::$servers as $serverId => $server) {
            $newIdMap = [];
            $server->count = max($server->count, 1);
            for ($key = 0; $key < $server->count; $key++) {
                $newIdMap[$key] = static::$idMap[$serverId][$key] ?? 0;
            }

            static::$idMap[$serverId] = $newIdMap;
        }
    }

    /**
     * Получить имя UNIX-пользователя текущего процесса.
     */
    protected static function getCurrentUser(): string
    {
        $userInfo = posix_getpwuid(posix_getuid());
        return $userInfo['name'] ?? 'неизвестно';
    }

    /**
     * Отображение начального интерфейса пользователя.
     */
    protected static function displayUI(): void
    {
        $tmpArgv = static::getArgv();
        if (in_array('-q', $tmpArgv)) {
            return;
        }

        if (!is_unix()) {
            static::safeEcho("---------------------------------------------- Localzet Server -----------------------------------------------\r\n");
            static::safeEcho('Server version:' . static::getVersion() . '          PHP version:' . PHP_VERSION . "\r\n");
            static::safeEcho("----------------------------------------------- SERVERS ------------------------------------------------\r\n");
            static::safeEcho("server                                          listen                              processes   status\r\n");
            return;
        }

        // Показать версию
        $lineVersion = str_pad('Server version: <cyan>' . static::getVersion() . '</cyan>', 39);
        $lineVersion .= str_pad('PHP version: <cyan>' . PHP_VERSION . '</cyan>', 35);
        $lineVersion .= str_pad('Event-loop: <cyan>' . get_event_loop_name() . '</cyan>', 45);
        $lineVersion .= PHP_EOL;

        !defined('LINE_VERSION_LENGTH') && define('LINE_VERSION_LENGTH', strlen($lineVersion) - (strlen('<cyan></cyan>') * 3));
        $totalLength = static::getSingleLineTotalLength();

        $lineOne = '<n>' . str_pad('<magenta> Localzet Server </magenta>', $totalLength + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH) . '</n>' . PHP_EOL;
        $lineTwo = '<n>' . str_pad('<magenta> SERVERS </magenta>', $totalLength + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH) . '</n>' . PHP_EOL;

        static::safeEcho($lineOne . $lineVersion . $lineTwo);

        // Показать заголовок
        $title = '';
        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            // Совместимость с названием слушателя
            if (strtolower($columnName) === 'socket') {
                $columnName = 'listen';
            }

            $title .= "<blue>" . strtoupper($columnName) . "</blue>" . str_pad('', static::$$key + static::UI_SAFE_LENGTH - strlen($columnName));
        }

        $title && static::safeEcho($title . PHP_EOL);

        // Показать содержимое
        foreach (static::$servers as $server) {
            $content = '';
            foreach (static::getUiColumns() as $columnName => $prop) {
                $propValue = (string)($server->$prop ?? $server->context->$prop);
                $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
                preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>|<black>|<\/black>|<red>|<\/red>|<green>|<\/green>|<yellow>|<\/yellow>|<blue>|<\/blue>|<magenta>|<\/magenta>|<cyan>|<\/cyan>|<white>|<\/white>)/i", $propValue, $matches);
                $placeHolderLength = empty($matches) ? 0 : strlen(implode('', $matches[0]));
                $content .= str_pad($propValue, static::$$key + static::UI_SAFE_LENGTH + $placeHolderLength);
            }

            $content && static::safeEcho($content . PHP_EOL);
        }

        // Показать последнюю строку
        $lineLast = str_pad('', static::getSingleLineTotalLength(), '-') . PHP_EOL;
        if (!empty($content)) {
            static::safeEcho($lineLast);
        }

        if (static::$daemonize) {
            static::safeEcho('Выполните "php ' . basename(static::$startFile) . ' stop" для остановки. Сервер запущен.' . "\n\n");
        } elseif (!empty(static::$command)) {
            static::safeEcho("Localzet Server запущен.\n");
        } else {
            static::safeEcho("Нажмите Ctrl+C для остановки. Localzet Server запущен.\n");
        }
    }

    /**
     * Получить столбцы для отображения в терминале интерфейса пользователя (UI).
     *
     * 1. $columnMap: ['ui_column_name' => 'clas_property_name']
     * 2. В будущем можно перенести в конфигурацию.
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

    /**
     * Получить общую длину строки для интерфейса.
     */
    public static function getSingleLineTotalLength(): int
    {
        $totalLength = 0;

        foreach (array_keys(static::getUiColumns()) as $columnName) {
            $key = 'max' . ucfirst(strtolower($columnName)) . 'NameLength';
            $totalLength += static::$$key + static::UI_SAFE_LENGTH;
        }

        // Сохранить красоту при отображении меньшего количества столбцов
        !defined('LINE_VERSION_LENGTH') && define('LINE_VERSION_LENGTH', 0);
        if ($totalLength <= LINE_VERSION_LENGTH) {
            return LINE_VERSION_LENGTH;
        }

        return $totalLength;
    }

    /**
     * Разбор команды.
     */
    protected static function parseCommand(): void
    {
        if (!is_unix()) {
            return;
        }

        $startFile = basename(static::$startFile);
        $usage = "Пример: php start.php <команда> [флаг]\nКоманды: \nstart\t\tЗапуск сервера в режиме разработки.\n\t\tИспользуй флаг -d для запуска в фоновом режиме.\nstop\t\tОстановка сервера.\n\t\tИспользуй флаг -g для плавной остановки.\nrestart\t\tПерезагрузка сервера.\n\t\tИспользуй флаг -d для запуска в фоновом режиме.\n\t\tИспользуй флаг -g для плавной остановки.\nreload\t\tОбновить код.\n\t\tИспользуй флаг -g для плавной остановки.\nstatus\t\tСтатус сервера.\n\t\tИспользуй флаг -d для показа в реальном времени.\nconnections\tПоказать текущие соединения.\n";
        $availableCommands = [
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        ];
        $availableMode = [
            '-d',
            '-g'
        ];
        $command = $mode = '';
        foreach (static::getArgv() as $value) {
            if (!$command && in_array($value, $availableCommands)) {
                $command = $value;
            }

            if (!$mode && in_array($value, $availableMode)) {
                $mode = $value;
            }
        }

        if (!$command) {
            exit($usage);
        }

        // Команда "start".
        $modeStr = '';
        if ($command === 'start' && ($mode === '-d' || static::$daemonize)) {
            $modeStr = '(daemon)';
        }

        static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> $command $modeStr");

        // Получение PID мастер-процесса.
        $masterPid = is_file(static::$pidFile) ? (int)file_get_contents(static::$pidFile) : 0;
        // Мастер-процесс всё ещё активен?
        if (static::checkMasterIsAlive($masterPid)) {
            if ($command === 'start') {
                static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> уже запущен");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> не запущен");
            exit;
        }

        // Выполнение команды.
        switch ($command) {
            case 'start':
                if ($mode === '-d') {
                    static::$daemonize = true;
                }

                break;
            case 'status':
                register_shutdown_function(unlink(...), static::$statisticsFile);
                while (1) {
                    // Мастер-процесс отправит сигнал SIGIOT всем дочерним процессам
                    static::sendSignal($masterPid, SIGIOT);

                    // Пауза
                    usleep(500000);

                    // Очистка терминала
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m");
                    }

                    // Вывод данных о состоянии
                    static::safeEcho(static::formatProcessStatusData());
                    if ($mode !== '-d') {
                        exit(0);
                    }

                    static::safeEcho("\Нажмите Ctrl+C для выхода.\n\n");
                }
            case 'connections':
                register_shutdown_function(unlink(...), static::$connectionsFile);

                // Мастер-процесс отправит сигнал SIGIO всем дочерним процессам.
                static::sendSignal($masterPid, SIGIO);

                // Пауза на короткое время.
                usleep(500000);

                // Вывод данных о соединениях из файла на диске.
                static::safeEcho(static::formatConnectionStatusData());
                exit(0);
            case 'restart':
            case 'stop':
                if ($mode === '-g') {
                    static::$gracefulStop = true;
                    $sig = SIGQUIT;
                    static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> плавно останавливается...");
                } else {
                    static::$gracefulStop = false;
                    $sig = SIGINT;
                    static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> останавливается...");
                }

                // Отправка сигнала остановки мастер-процессу.
                $masterPid && static::sendSignal($masterPid, $sig);

                // Тайм-аут.
                $timeout = static::$stopTimeout + 3;
                $startTime = time();

                // Проверка активности мастер-процесса.
                while (1) {
                    $masterIsAlive = $masterPid && posix_kill($masterPid, 0);
                    if ($masterIsAlive) {
                        // Превышение тайм-аута?
                        if (!static::getGracefulStop() && time() - $startTime >= $timeout) {
                            static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> не остановлен!");
                            exit;
                        }

                        // Пауза.
                        usleep(10000);
                        continue;
                    }

                    // Остановка успешна.
                    static::log("<magenta>Localzet Server</magenta> <cyan>[$startFile]</cyan> остановлен");
                    if ($command === 'stop') {
                        exit(0);
                    }

                    if ($mode === '-d') {
                        static::$daemonize = true;
                    }

                    break;
                }

                break;
            case 'reload':
                if ($mode === '-g') {
                    $sig = SIGUSR2;
                } else {
                    $sig = SIGUSR1;
                }

                static::sendSignal($masterPid, $sig);
                exit;
            default:
                static::safeEcho('Неизвестная команда: ' . $command . "\n");
                exit($usage);
        }
    }

    /**
     * Получение массива argv.
     */
    public static function getArgv(): array
    {
        global $argv;
        return static::$command ? [...$argv, ...explode(' ', static::$command)] : $argv;
    }

    /**
     * Данные о состоянии
     */
    protected static function formatProcessStatusData(): string
    {
        static $totalRequestCache = [];
        if (!is_readable(static::$statisticsFile)) {
            return '';
        }

        $info = file(static::$statisticsFile, FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }

        $statusStr = '';
        $currentTotalRequest = [];
        $serverInfo = [];
        try {
            $serverInfo = unserialize($info[0], ['allowed_classes' => false]);
        } catch (Throwable) {
            // :)
        }

        if (!is_array($serverInfo)) {
            $serverInfo = [];
        }

        ksort($serverInfo, SORT_NUMERIC);
        unset($info[0]);
        $dataWaitingSort = [];
        $readProcessStatus = false;
        $totalRequests = 0;
        $totalQps = 0;
        $totalConnections = 0;
        $totalFails = 0;
        $totalMemory = 0;
        $totalTimers = 0;
        $maxLen1 = 20;
        $maxLen2 = 16;
        foreach ($info as $value) {
            if (!$readProcessStatus) {
                $statusStr .= $value . "\n";
                if (preg_match('/^<blue>PID<\/blue>.*?<blue>MEM<\/blue>.*?<blue>LISTEN<\/blue>/', $value)) {
                    $readProcessStatus = true;
                }

                continue;
            }

            if (preg_match('/^\d+/', $value, $pidMath)) {
                $pid = $pidMath[0];
                $dataWaitingSort[$pid] = $value;
                if (preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    $totalMemory += (float)str_ireplace('M', '', $match[1]);
                    $maxLen1 = max($maxLen1, strlen($match[2]));
                    $maxLen2 = max($maxLen2, strlen($match[3]));
                    $totalConnections += (int)$match[4];
                    $totalFails += (int)$match[5];
                    $totalTimers += (int)$match[6];
                    $currentTotalRequest[$pid] = $match[7];
                    $totalRequests += (int)$match[7];
                }
            }
        }

        foreach ($serverInfo as $pid => $info) {
            if (!isset($dataWaitingSort[$pid])) {
                $statusStr .=
                    "$pid"
                    . "\t" . str_pad('<red>N/A</red>', 7 + strlen('<red></red>'))
                    . " " . str_pad((string)$info['listen'], $maxLen1)
                    . " " . str_pad((string)$info['name'], $maxLen2)
                    . " " . str_pad('<red>N/A</red>', 11 + strlen('<red></red>'))
                    . " " . str_pad('<red>N/A</red>', 9 + strlen('<red></red>'))
                    . " " . str_pad('<red>N/A</red>', 8 + strlen('<red></red>'))
                    . " " . str_pad('<red>N/A</red>', 13 + strlen('<red></red>'))
                    . " " . str_pad('<red>N/A</red>', 6 + strlen('<red></red>'))
                    . " " . str_pad('<yellow>[занят]</yellow>', 10 + strlen('<yellow></yellow>'))
                    . "\n";
                continue;
            }

            //$qps = isset($totalRequestCache[$pid]) ? $currentTotalRequest[$pid]
            if (!isset($totalRequestCache[$pid], $currentTotalRequest[$pid])) {
                $qps = 0;
            } else {
                $qps = $currentTotalRequest[$pid] - $totalRequestCache[$pid];
                $totalQps += $qps;
            }

            $statusStr .= $dataWaitingSort[$pid] . " " . str_pad((string)$qps, 6) . " <green>[не занят]</green>\n";
        }

        $totalRequestCache = $currentTotalRequest;
        $statusStr .= str_pad('<magenta>PROCESS STATUS</magenta>', 116 + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH) . "\n";
        return $statusStr . ("<blue>Итог</blue>"
                . "\t" . str_pad('<cyan>' . $totalMemory . 'M' . '</cyan>', 7 + strlen('<cyan></cyan>'))
                . " " . str_pad('', $maxLen1)
                . " " . str_pad('', $maxLen2)
                . " " . str_pad('<cyan>' . $totalConnections . '</cyan>', 11 + strlen('<cyan></cyan>'))
                . " " . str_pad('<cyan>' . $totalFails . '</cyan>', 9 + strlen('<cyan></cyan>'))
                . " " . str_pad('<cyan>' . $totalTimers . '</cyan>', 8 + strlen('<cyan></cyan>'))
                . " " . str_pad('<cyan>' . $totalRequests . '</cyan>', 13 + strlen('<cyan></cyan>'))
                . " " . str_pad('<cyan>' . $totalQps . '</cyan>', 6 + strlen('<cyan></cyan>'))
                . " " . str_pad('<blue>[Итог]</blue>', 10 + strlen('<blue></blue>'))
                . "\n");
    }

    protected static function formatConnectionStatusData(): string
    {
        return file_get_contents(static::$connectionsFile);
    }

    /**
     * Установить обработчик сигналов.
     */
    protected static function installSignal(): void
    {
        if (!is_unix()) {
            return;
        }

        $signals = [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO];
        foreach ($signals as $signal) {
            pcntl_signal($signal, static::signalHandler(...), false);
        }

        // - А мне ∏∅⨉ на ваш SIGPIPE!
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    /**
     * Переустановить обработчик сигнала.
     *
     * @throws Throwable
     */
    protected static function reinstallSignal(): void
    {
        if (!is_unix()) {
            return;
        }

        $signals = [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO];
        foreach ($signals as $signal) {
            static::$globalEvent->onSignal($signal, static::signalHandler(...));
        }
    }

    /**
     * Обработчик сигнала.
     *
     * @throws Throwable
     */
    public static function signalHandler(int $signal): void
    {
        switch ($signal) {
            // Остановка.
            case SIGINT:
            case SIGTERM:
            case SIGHUP:
            case SIGTSTP:
                static::$gracefulStop = false;
                static::stopAll();
                break;
            // Плавная остановка.
            case SIGQUIT:
                static::$gracefulStop = true;
                static::stopAll();
                break;
            // Перезагрузка.
            case SIGUSR2:
            case SIGUSR1:
                if (static::$status === static::STATUS_RELOADING || static::$status === static::STATUS_SHUTDOWN) {
                    return;
                }

                static::$gracefulStop = $signal === SIGUSR2;
                static::$pidsToRestart = static::getAllServerPids();
                static::reload();
                break;
            // Статус.
            case SIGIOT:
                static::writeStatisticsToStatusFile();
                break;
            // Текущие соединения.
            case SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Запустить в режиме демона.
     *
     * @throws Exception
     */
    protected static function daemonize(): void
    {
        if (!static::$daemonize || !is_unix()) {
            return;
        }

        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException('Ошибка форка');
        }
        if ($pid > 0) {
            exit(0);
        }

        if (-1 === posix_setsid()) {
            throw new RuntimeException('Ошибка установки SID');
        }

        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException('Ошибка форка');
        }
        if (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Перенаправление стандартного ввода и вывода.
     */
    public static function resetStd(): void
    {
        if (!static::$daemonize || !is_unix()) {
            return;
        }

        if (is_resource(STDOUT)) {
            fclose(STDOUT);
        }

        if (is_resource(STDERR)) {
            fclose(STDERR);
        }

        if (is_resource(static::$outputStream)) {
            fclose(static::$outputStream);
        }

        set_error_handler(static fn(): bool => true);
        $stdOutStream = fopen(static::$stdoutFile, 'a');
        restore_error_handler();

        if ($stdOutStream === false) {
            return;
        }

        static::$outputStream = $stdOutStream;

        // Исправление ошибки PHP 8.1.8, связанной с невозможностью перенаправления стандартного вывода
        if (function_exists('posix_isatty') && posix_isatty(2)) {
            ob_start(function (string $string): void {
                file_put_contents(static::$stdoutFile, $string, FILE_APPEND);
            }, 1);
        }
    }

    /**
     * Сохранить PID мастер-процесса.
     *
     * @throws Exception
     */
    protected static function saveMasterPid(): void
    {
        if (!is_unix()) {
            return;
        }

        static::$masterPid = posix_getpid();
        if (false === file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new RuntimeException('Не удалось сохранить PID в ' . static::$pidFile);
        }
    }

    protected static function getEventLoopName(): string
    {
        return static::$eventLoopClass;
    }

    /**
     * Получить все PID процессов сервера.
     */
    protected static function getAllServerPids(): array
    {
        $pidArray = [];
        foreach (static::$pidMap as $serverPidArray) {
            foreach ($serverPidArray as $serverPid) {
                $pidArray[$serverPid] = $serverPid;
            }
        }

        return $pidArray;
    }

    /**
     * Создать процессы для серверов.
     *
     * @throws Throwable
     */
    protected static function forkServers(): void
    {
        if (is_unix()) {
            static::forkServersForLinux();
        } else {
            static::forkServersForWindows();
        }
    }

    /**
     * Создать процессы для серверов (Linux).
     *
     * @throws Throwable
     */
    protected static function forkServersForLinux(): void
    {
        foreach (static::$servers as $server) {
            if (static::$status === static::STATUS_STARTING) {
                if (empty($server->name)) {
                    $server->name = $server->getSocketName();
                }

                $serverNameLength = strlen($server->name);
                if (static::$maxServerNameLength < $serverNameLength) {
                    static::$maxServerNameLength = $serverNameLength;
                }
            }

            while (count(static::$pidMap[$server->serverId]) < $server->count) {
                static::forkOneServerForLinux($server);
            }
        }
    }

    /**
     * Форкнуть несколько процессов сервера для Windows.
     *
     * @throws Throwable
     */
    protected static function forkServersForWindows(): void
    {
        $files = static::getStartFilesForWindows();
        if (count($files) === 1 || in_array('-q', static::getArgv())) {
            if (count(static::$servers) > 1) {
                static::safeEcho("@@@ Ошибка: инициализация нескольких серверов в одном php-файле не поддерживается @@@\r\n");
            } elseif (count(static::$servers) <= 0) {
                exit("@@@ Нет сервера @@@\r\n\r\n");
            }

            reset(static::$servers);
            /** @var Server $server */
            $server = current(static::$servers);

            Timer::delAll();

            // Обновить состояние процесса.
            static::$status = static::STATUS_RUNNING;

            // Зарегистрировать функцию проверки ошибок.
            register_shutdown_function(static::checkErrors(...));

            // Создать глобальный цикл событий.
            if (!static::$globalEvent instanceof EventInterface) {
                $eventLoopClass = static::getEventLoopName();
                static::$globalEvent = new $eventLoopClass();
                static::$globalEvent->setErrorHandler(function ($exception): void {
                    static::stopAll(250, $exception);
                });
            }

            // Переустановить обработчик.
            static::reinstallSignal();

            // Инициализация.
            Timer::init(static::$globalEvent);

            restore_error_handler();

            // Добавить пустой таймер, чтобы предотвратить выход из цикла событий.
            Timer::add(1000000, function (): void {
            });

            // Отобразить пользовательский интерфейс (UI).
            static::safeEcho(str_pad($server->name, 48) . str_pad($server->getSocketName(), 36) . str_pad("1", 10) . "[OK]\n");
            $server->listen();
            $server->run();
            static::$globalEvent->run();
            if (static::$status !== self::STATUS_SHUTDOWN) {
                $err = new Exception('event-loop exited');
                static::log($err);
                exit(250);
            }

            exit(0);
        }

        static::$globalEvent = new Windows();
        static::$globalEvent->setErrorHandler(function ($exception): void {
            static::stopAll(250, $exception);
        });
        Timer::init(static::$globalEvent);
        foreach ($files as $file) {
            static::forkOneServerForWindows($file);
        }
    }

    /**
     * Получить файлы запуска для Windows.
     */
    public static function getStartFilesForWindows(): array
    {
        $files = [];
        foreach (static::getArgv() as $file) {
            if (is_file($file)) {
                $files[$file] = $file;
            }
        }

        return $files;
    }

    /**
     * Форкнуть один процесс сервера для Windows.
     */
    public static function forkOneServerForWindows(string $startFile): void
    {
        $startFile = realpath($startFile);
        $descriptorSpec = [STDIN, STDOUT, STDOUT];
        $pipes = [];
        $process = proc_open('"' . PHP_BINARY . '" ' . " \"$startFile\" -q", $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (!static::$globalEvent instanceof EventInterface) {
            static::$globalEvent = new Windows();
            static::$globalEvent->setErrorHandler(function ($exception): void {
                static::stopAll(250, $exception);
            });
            Timer::init(static::$globalEvent);
        }

        // Сохранить дескриптор процесса
        static::$processForWindows[$startFile] = [$process, $startFile];
    }

    /**
     * Проверка статуса сервера для Windows.
     */
    public static function checkServerStatusForWindows(): void
    {
        foreach (static::$processForWindows as $processForWindow) {
            $process = $processForWindow[0];
            $startFile = $processForWindow[1];
            $status = proc_get_status($process);
            if (!$status['running']) {
                static::safeEcho("Процесс $startFile завершен и пытается перезапуститься\n");
                proc_close($process);
                static::forkOneServerForWindows($startFile);
            }
        }
    }

    /**
     * Создать один процесс сервера.
     *
     * @throws Exception|RuntimeException|Throwable
     */
    protected static function forkOneServerForLinux(self $server): void
    {
        // Получить доступный идентификатор сервера.
        $id = static::getId($server->serverId, 0);
        $pid = pcntl_fork();
        // Для основного процесса.
        if ($pid > 0) {
            static::$pidMap[$server->serverId][$pid] = $pid;
            static::$idMap[$server->serverId][$id] = $pid;
        } // Для дочерних процессов.
        elseif (0 === $pid) {
            mt_srand();
            mt_srand();
            static::$gracefulStop = false;
            if (static::$status === static::STATUS_STARTING) {
                static::resetStd();
            }

            static::$pidsToRestart = static::$pidMap = [];
            // Удалить других слушателей.
            foreach (static::$servers as $key => $oneServer) {
                if ($oneServer->serverId !== $server->serverId) {
                    $oneServer->unlisten();
                    unset(static::$servers[$key]);
                }
            }

            Timer::delAll();

            // Обновить состояние процесса.
            static::$status = static::STATUS_RUNNING;

            // Зарегистрировать функцию завершения для проверки ошибок.
            register_shutdown_function(static::checkErrors(...));

            // Создать глобальный цикл событий.
            if (!static::$globalEvent instanceof EventInterface) {
                $eventLoopClass = static::getEventLoopName();
                static::$globalEvent = new $eventLoopClass();
                static::$globalEvent->setErrorHandler(function ($exception): void {
                    static::stopAll(250, $exception);
                });
            }

            // Переустановить сигналы.
            static::reinstallSignal();

            // Инициализировать таймер.
            Timer::init(static::$globalEvent);

            restore_error_handler();

            static::setProcessTitle('Localzet Server: процесс сервера ' . $server->name . ' ' . $server->getSocketName());
            $server->setUserAndGroup();
            $server->id = $id;
            $server->run();

            // Основная петля.
            static::$globalEvent->run();

            if (static::$status !== self::STATUS_SHUTDOWN) {
                $err = new Exception('Ошибка event-loop');
                static::log($err);
                exit(250);
            }

            exit(0);
        } else {
            throw new RuntimeException('Ошибка forkOneServer');
        }
    }

    /**
     * Получить идентификатор сервера.
     *
     *
     * @return false|int|string
     */
    protected static function getId(string $serverId, int $pid): bool|int|string
    {
        return array_search($pid, static::$idMap[$serverId], true);
    }

    /**
     * Установить пользовательскую группу и пользователя для текущего процесса.
     */
    public function setUserAndGroup(): void
    {
        // Получить UID.
        $userInfo = posix_getpwnam($this->user);
        if (!$userInfo) {
            static::log("Внимание: Пользователь $this->user не существует");
            return;
        }

        $uid = $userInfo['uid'];
        // Получить GID.
        if ($this->group) {
            $groupInfo = posix_getgrnam($this->group);
            if (!$groupInfo) {
                static::log("Внимание: Группа $this->group не существует");
                return;
            }

            $gid = $groupInfo['gid'];
        } else {
            $gid = $userInfo['gid'];
        }

        // Установить UID и GID.
        if (($uid !== posix_getuid() || $gid !== posix_getgid()) && (!posix_setgid($gid) || !posix_initgroups($userInfo['name'], $gid) || !posix_setuid($uid))) {
            static::log('Внимание: Ошибка изменения GID или UID');
        }
    }

    /**
     * Установка имени процесса.
     */
    protected static function setProcessTitle(string $title): void
    {
        set_error_handler(static fn(): bool => true);
        cli_set_process_title($title);
        restore_error_handler();
    }

    /**
     * Отправка сигнала процессу.
     */
    protected static function sendSignal(int $process_id, int $signal): void
    {
        set_error_handler(static fn(): bool => true);
        posix_kill($process_id, $signal);
        restore_error_handler();
    }

    /**
     * Мониторинг всех дочерних процессов.
     *
     * @throws Throwable
     */
    protected static function monitorServers(): void
    {
        if (is_unix()) {
            static::monitorServersForLinux();
        } else {
            static::monitorServersForWindows();
        }
    }

    /**
     * Мониторинг всех дочерних процессов для Linux.
     *
     * @throws Throwable
     */
    protected static function monitorServersForLinux(): void
    {
        static::$status = static::STATUS_RUNNING;

        while (1) {
            // Вызываем обработчики сигналов для ожидающих сигналов.
            pcntl_signal_dispatch();

            // Ожидаем завершения дочернего процесса или получения сигнала.
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);

            // Вызываем обработчики сигналов для ожидающих сигналов еще раз.
            pcntl_signal_dispatch();

            // Если дочерний процесс уже завершился.
            if ($pid > 0) {
                // Находим серверный процесс, который завершился.
                foreach (static::$pidMap as $serverId => $serverPidArray) {
                    if (isset($serverPidArray[$pid])) {
                        $server = static::$servers[$serverId];

                        // Исправляем завершение с кодом 2 для php8.2
                        if ($status === SIGINT && static::$status === static::STATUS_SHUTDOWN) {
                            $status = 0;
                        }

                        // Статус завершения процесса.
                        if ($status !== 0) {
                            static::log("<magenta>Localzet Server</magenta> <cyan>[$server->name:$pid]</cyan> завершился со статусом $status");
                        }

                        // onServerExit
                        Events::emit('Server::Exit', ['server' => $server, 'status' => $status, 'pid' => $pid]);

                        // Для статистики.
                        static::$globalStatistics['server_exit_info'][$serverId][$status] ??= 0;
                        static::$globalStatistics['server_exit_info'][$serverId][$status]++;

                        // Очищаем данные процесса.
                        unset(static::$pidMap[$serverId][$pid]);

                        // Отмечаем идентификатор как доступный.
                        $id = static::getId($serverId, $pid);
                        static::$idMap[$serverId][$id] = 0;

                        break;
                    }
                }

                // Если процесс не в состоянии остановки, то форкаем новый серверный процесс.
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::forkServers();

                    // Если перезагрузка, то продолжаем.
                    if (isset(static::$pidsToRestart[$pid])) {
                        unset(static::$pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }

            // Если в состоянии остановки и все дочерние процессы завершились, то мастер-процесс выходит.
            if (static::$status === static::STATUS_SHUTDOWN && !static::getAllServerPids()) {
                static::exitAndClearAll();
            }
        }
    }

    /**
     * Мониторинг всех дочерних процессов.
     *
     * @throws Throwable
     */
    protected static function monitorServersForWindows(): void
    {
        Timer::add(1, static::checkServerStatusForWindows(...));

        static::$globalEvent->run();
    }

    /**
     * Выход из текущего процесса.
     */
    #[NoReturn]
    protected static function exitAndClearAll(): void
    {
        foreach (static::$servers as $server) {
            $socketName = $server->getSocketName();
            if ($server->transport === 'unix' && $socketName) {
                [, $address] = explode(':', $socketName, 2);
                $address = substr($address, strpos($address, '/') + 2);
                @unlink($address);
            }
        }

        @unlink(static::$pidFile);
        static::log("<magenta>Localzet Server</magenta> <cyan>[" . basename(static::$startFile) . "]</cyan> был остановлен");
        Events::emit('Server::Master::Stop', null);
        exit(0);
    }

    /**
     * Выполнить перезагрузку сервера.
     *
     * @throws Throwable
     */
    protected static function reload(): void
    {
        // Для мастер-процесса.
        if (static::$masterPid === posix_getpid()) {
            $sig = static::$gracefulStop ? SIGUSR2 : SIGUSR1;

            // Устанавливаем состояние перезагрузки.
            if (static::$status !== static::STATUS_RELOADING && static::$status !== static::STATUS_SHUTDOWN) {
                static::log("<magenta>Localzet Server</magenta> <cyan>[" . basename(static::$startFile) . "]</cyan> обновляется");
                static::$status = static::STATUS_RELOADING;

                // Сбросить стандартные ввод и вывод.
                static::resetStd();

                Events::emit('Server::Master::Reload', null);

                // Отправляем сигнал перезагрузки всем дочерним процессам.
                $reloadablePidArray = [];
                foreach (static::$pidMap as $serverId => $serverPidArray) {
                    $server = static::$servers[$serverId];
                    if ($server->reloadable) {
                        foreach ($serverPidArray as $pid) {
                            $reloadablePidArray += $serverPidArray;
                        }
                    }

                    // Отправляем сигнал перезагрузки процессу, для которого reloadable равно false.
                    array_walk($serverPidArray, static fn($pid): bool => posix_kill($pid, $sig));
                }

                // Получаем все pid, которые ожидают перезагрузки.
                static::$pidsToRestart = array_intersect(static::$pidsToRestart, $reloadablePidArray);
            }

            // Перезагрузка завершена.
            if (empty(static::$pidsToRestart)) {
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::$status = static::STATUS_RUNNING;
                }

                return;
            }

            // Продолжаем перезагрузку.
            $oneServerPid = current(static::$pidsToRestart);

            // Отправляем сигнал перезагрузки процессу.
            static::sendSignal($oneServerPid, $sig);

            // Если процесс не завершится после stopTimeout секунд, пытаемся убить его.
            if (!static::$gracefulStop) {
                Timer::add(static::$stopTimeout, posix_kill(...), [$oneServerPid, SIGKILL], false);
            }
        } // Для дочерних процессов.
        else {
            reset(static::$servers);
            $server = current(static::$servers);

            Events::emit('Server::Reload', $server);

            // Если процесс reloadable равен true, то останавливаем все процессы.
            if ($server->reloadable) {
                static::stopAll();
            } else {
                static::resetStd();
            }
        }
    }

    /**
     * Остановить все.
     *
     * @throws Throwable
     */
    public static function stopAll(int $code = 0, mixed $log = ''): void
    {
        if ($log) {
            static::log($log);
        }

        static::$status = static::STATUS_SHUTDOWN;
        // Для процесса-мастера.
        if (is_unix() && static::$masterPid === posix_getpid()) {
            static::log("<magenta>Localzet Server</magenta> <cyan>[" . basename(static::$startFile) . "]</cyan> останавливается...");
            $serverPidArray = static::getAllServerPids();
            // Отправить сигнал остановки всем дочерним процессам.
            $sig = static::$gracefulStop ? SIGQUIT : SIGINT;
            foreach ($serverPidArray as $serverPid) {
                // Исправить выход с кодом 2 для PHP 8.2.
                if ($sig === SIGINT && !static::$daemonize) {
                    Timer::add(1, posix_kill(...), [$serverPid, SIGINT], false);
                } else {
                    static::sendSignal($serverPid, $sig);
                }

                if (!static::$gracefulStop) {
                    Timer::add(ceil(static::$stopTimeout), posix_kill(...), [$serverPid, SIGKILL], false);
                }
            }

            Timer::add(1, static::checkIfChildRunning(...));
        } // Для дочерних процессов.
        else {
            // Выполнить выход.
            $servers = array_reverse(static::$servers);
            array_walk($servers, static fn(Server $server) => $server->stop());

            if (!static::$gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$servers = [];
                static::$globalEvent?->stop();

                try {
                    exit($code);
                    /** @phpstan-ignore-next-line */
                } catch (Exception) {
                    // :)
                }
            }
        }
    }

    /**
     * Проверка, запущен ли дочерний процесс
     */
    public static function checkIfChildRunning(): void
    {
        foreach (static::$pidMap as $serverId => $serverPidArray) {
            foreach ($serverPidArray as $pid => $serverPid) {
                if (!posix_kill($pid, 0)) {
                    unset(static::$pidMap[$serverId][$pid]);
                }
            }
        }
    }

    /**
     * Статус процесса.
     */
    public static function getStatus(): int
    {
        return static::$status;
    }

    /**
     * Плавная остановка.
     */
    public static function getGracefulStop(): bool
    {
        return static::$gracefulStop;
    }

    /**
     * Запись данных статистики на диск.
     */
    protected static function writeStatisticsToStatusFile(): void
    {
        // Для мастер-процесса.
        if (static::$masterPid === posix_getpid()) {
            $allServerInfo = [];
            foreach (static::$pidMap as $serverId => $pidArray) {
                $server = static::$servers[$serverId];
                foreach ($pidArray as $pid) {
                    $allServerInfo[$pid] = ['name' => $server->name, 'listen' => $server->getSocketName()];
                }
            }

            file_put_contents(static::$statisticsFile, '');
            chmod(static::$statisticsFile, 0722);
            file_put_contents(static::$statisticsFile, serialize($allServerInfo) . "\n", FILE_APPEND);
            $loadavg = function_exists('sys_getloadavg') ? array_map(round(...), sys_getloadavg(), [2, 2, 2]) : ['-', '-', '-'];

            file_put_contents(static::$statisticsFile,
                '<yellow>' . (static::$daemonize ? "Сервер запущен в фоновом режиме" : "Сервер запущен в режиме разработки") . '</yellow>'
                . "\n", FILE_APPEND);


            file_put_contents(static::$statisticsFile,
                str_pad('<magenta>GLOBAL STATUS</magenta>', 116 + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH)
                . "\n", FILE_APPEND);

            file_put_contents(static::$statisticsFile,
                str_pad('Server version: <cyan>' . static::getVersion() . '</cyan>', 40)
                . str_pad('PHP version: <cyan>' . PHP_VERSION . '</cyan>', 36)
                . str_pad('Event-loop: <cyan>' . get_event_loop_name() . '</cyan>', 73)
                . "\n", FILE_APPEND);

            file_put_contents(static::$statisticsFile,
                str_pad('Start time: <cyan>' . date('Y-m-d H:i:s', static::$globalStatistics['start_timestamp']) . '</cyan>', 63)
                . str_pad('Uptime: <cyan>' . floor((time() - static::$globalStatistics['start_timestamp']) / (24 * 60 * 60)) . '</cyan>' . ' days ' . '<cyan>' . floor(((time() - static::$globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . '</cyan>' . ' hours', 86)
                . "\n", FILE_APPEND);

            file_put_contents(static::$statisticsFile,
                str_pad('Load average: <cyan>' . implode(", ", $loadavg) . '</cyan>', 63)
                . str_pad('Started: <cyan>' . count(static::$pidMap) . '</cyan>' . ' servers ' . '<cyan>' . count(static::getAllServerPids()) . '</cyan>' . ' processes', 86)
                . "\n", FILE_APPEND);


            file_put_contents(static::$statisticsFile,
                str_pad('<magenta>STATISTICS</magenta>', 116 + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH)
                . "\n", FILE_APPEND);

            file_put_contents(static::$statisticsFile,
                str_pad('<blue>SERVER</blue>', 63)
                . str_pad('<blue>STATUS</blue>', 38)
                . str_pad('<blue>COUNT</blue>', 38)
                . "\n", FILE_APPEND);

            foreach (array_keys(static::$pidMap) as $serverId) {
                $server = static::$servers[$serverId];
                if (isset(static::$globalStatistics['server_exit_info'][$serverId])) {
                    foreach (static::$globalStatistics['server_exit_info'][$serverId] as $serverExitStatus => $serverExitCount) {
                        file_put_contents(static::$statisticsFile,
                            str_pad($server->name, 50)
                            . str_pad((string)$serverExitStatus, 25)
                            . str_pad((string)$serverExitCount, 25)
                            . "\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(static::$statisticsFile,
                        str_pad($server->name, 50)
                        . str_pad('0', 25)
                        . str_pad('0', 25)
                        . "\n", FILE_APPEND);
                }
            }


            file_put_contents(static::$statisticsFile,
                str_pad('<magenta>PROCESS STATUS</magenta>', 116 + strlen('<magenta></magenta>'), '-', STR_PAD_BOTH)
                . "\n", FILE_APPEND);

            file_put_contents(static::$statisticsFile,
                '<blue>PID</blue>	' . str_pad("<blue>MEM</blue>", 7 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>LISTEN</blue>', 20 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>SERVER</blue>', 16 + strlen('<blue></blue>'))
                . " " . str_pad("<blue>CONNECTIONS</blue>", 11 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>FAILS</blue>', 9 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>TIMERS</blue>', 8 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>REQUESTS</blue>', 13 + strlen('<blue></blue>'))
                . " " . str_pad('<blue>QPS</blue>', 6 + strlen('<blue></blue>'))
                . " " . str_pad("<blue>STATUS</blue>", 10 + strlen('<blue></blue>'))
                . "\n", FILE_APPEND);

            foreach (static::getAllServerPids() as $serverPid) {
                static::sendSignal($serverPid, SIGIOT);
            }

            return;
        }

        // Для дочерних процессов.
        gc_collect_cycles();
        gc_mem_caches();
        reset(static::$servers);
        /** @var static $server */
        $server = current(static::$servers);
        file_put_contents(static::$statisticsFile,
            posix_getpid()
            . "\t" . str_pad(round(memory_get_usage() / (1024 * 1024), 2) . "M", 7)
            . " " . str_pad($server->getSocketName(), 20)
            . " " . ($server->name === $server->getSocketName() ? str_pad('<red>none</red>', 16 + strlen('<red></red>')) : str_pad($server->name, 16))
            . " " . str_pad((string)ConnectionInterface::$statistics['connection_count'], 11)
            . " " . str_pad((string)ConnectionInterface::$statistics['send_fail'], 9)
            . " " . str_pad((string)static::$globalEvent->getTimerCount(), 8)
            . " " . str_pad((string)ConnectionInterface::$statistics['total_request'], 13)
            . "\n", FILE_APPEND);
    }

    /**
     * Запись данных статистики соединений на диск.
     */
    protected static function writeConnectionsStatisticsToStatusFile(): void
    {
        // Для мастер-процесса.
        if (static::$masterPid === posix_getpid()) {
            file_put_contents(static::$connectionsFile, '');
            chmod(static::$connectionsFile, 0722);
            file_put_contents(static::$connectionsFile, "--------------------------------------------------------------------- SERVER CONNECTION STATUS --------------------------------------------------------------------------------\n", FILE_APPEND);
            file_put_contents(static::$connectionsFile, "PID      Server          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n", FILE_APPEND);
            foreach (static::getAllServerPids() as $serverPid) {
                static::sendSignal($serverPid, SIGIO);
            }

            return;
        }

        // Для дочерних процессов.
        $bytesFormat = function ($bytes): string {
            if ($bytes > 1024 * 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024 * 1024), 1) . "TB";
            }

            if ($bytes > 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024), 1) . "GB";
            }

            if ($bytes > 1024 * 1024) {
                return round($bytes / (1024 * 1024), 1) . "MB";
            }

            if ($bytes > 1024) {
                return round($bytes / (1024), 1) . "KB";
            }

            return $bytes . "B";
        };

        $pid = posix_getpid();
        $str = '';
        reset(static::$servers);
        $currentServer = current(static::$servers);
        $defaultServerName = $currentServer->name;

        foreach (TcpConnection::$connections as $connection) {
            /** @var TcpConnection $connection */
            $transport = $connection->transport;
            $ipv4 = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6 = $connection->isIpV6() ? ' 1' : ' 0';
            $recvQ = $bytesFormat($connection->getRecvBufferQueueSize());
            $sendQ = $bytesFormat($connection->getSendBufferQueueSize());
            $localAddress = trim($connection->getLocalAddress());
            $remoteAddress = trim($connection->getRemoteAddress());
            $state = $connection->getStatus(false);
            $bytesRead = $bytesFormat($connection->bytesRead);
            $bytesWritten = $bytesFormat($connection->bytesWritten);
            $id = $connection->id;
            $protocol = $connection->protocol ?: $connection->transport;
            $pos = strrpos($protocol, '\\');
            if ($pos) {
                $protocol = substr($protocol, $pos + 1);
            }

            if (strlen($protocol) > 15) {
                $protocol = substr($protocol, 0, 13) . '..';
            }

            $serverName = $connection->server !== null ? $connection->server->name : $defaultServerName;
            if (strlen($serverName) > 14) {
                $serverName = substr($serverName, 0, 12) . '..';
            }

            $str .= str_pad((string)$pid, 9) . str_pad($serverName, 16) . str_pad((string)$id, 10) . str_pad($transport, 8)
                . str_pad($protocol, 16) . str_pad($ipv4, 7) . str_pad($ipv6, 7) . str_pad($recvQ, 13)
                . str_pad($sendQ, 13) . str_pad($bytesRead, 13) . str_pad($bytesWritten, 13) . ' '
                . str_pad($state, 14) . ' ' . str_pad($localAddress, 22) . ' ' . str_pad($remoteAddress, 22) . "\n";
        }

        if ($str) {
            file_put_contents(static::$connectionsFile, $str, FILE_APPEND);
        }
    }

    /**
     * Проверка ошибок при завершении дочернего процесса.
     */
    public static function checkErrors(): void
    {
        if (static::STATUS_SHUTDOWN !== static::$status) {
            $errorMsg = is_unix() ? '<magenta>Localzet Server</magenta> <cyan>[' . posix_getpid() . ']</cyan> процесс завершен' : 'Серверный процесс завершен';
            $errors = error_get_last();
            if (
                $errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $errorMsg .= ' с ошибкой: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} в файле {$errors['file']} на {$errors['line']} строке\"";
            }

            static::log($errorMsg);
        }
    }

    /**
     * Сообщение об ошибке по коду ошибки.
     */
    protected static function getErrorType(int $type): string
    {
        return self::ERROR_TYPE[$type] ?? '';
    }

    /**
     * Журналирование.
     */
    public static function log(mixed $msg, bool $decorated = true): void
    {
        $msg = trim((string)$msg);

        if (!static::$daemonize) {
            static::safeEcho("$msg\n", $decorated);
        }

        if (isset(static::$logFile)) {
            $pid = is_unix() ? posix_getpid() : 1;
            file_put_contents(static::$logFile, sprintf("%s pid:%d %s\n", date('Y-m-d H:i:s'), $pid, $msg), FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Безопасный вывод.
     */
    public static function safeEcho(string $msg, bool $decorated = true): void
    {
        if ((static::$outputDecorated ?? false) && $decorated) {
            /**
             * Цвета в терминале строятся след. образом:
             * "\033" + [ ($background ? '4' : '3') + COLOR + m
             * "\033" + [ ($background ? '10' : '9') + BRIGHT_COLOR + m
             *
             * COLOR = ['black' => 0, 'red' => 1, 'green' => 2, 'yellow' => 3, 'blue' => 4, 'magenta' => 5, 'cyan' => 6, 'white' => 7, 'default' => 9];
             * BRIGHT_COLOR = ['gray' => 0, 'bright-red' => 1, 'bright-green' => 2, 'bright-yellow' => 3, 'bright-blue' => 4, 'bright-magenta' => 5, 'bright-cyan' => 6, 'bright-white' => 7];
             */

            $black = "\033[30m";
            $red = "\033[31m";
            $green = "\033[32m";
            $yellow = "\033[33m";
            $blue = "\033[34m";
            $magenta = "\033[35m";
            $cyan = "\033[36m";
            $white = "\033[37m";
            $default = "\033[39m";

            $line = "\033[1A\n\033[K";
            $end = "\033[0m";
        } else {
            $black = "";
            $red = "";
            $green = "";
            $yellow = "";
            $blue = "";
            $magenta = "";
            $cyan = "";
            $white = "";
            $who = "";
            $default = "";

            $line = '';
            $end = '';
        }

        $msg = str_replace(['<black>', '<red>', '<green>', '<yellow>', '<blue>', '<magenta>', '<cyan>', '<white>'], [$black, $red, $green, $yellow, $blue, $magenta, $cyan, $white], $msg);
        $msg = str_replace(['</black>', '</red>', '</green>', '</yellow>', '</blue>', '</magenta>', '</cyan>', '</white>'], $end, $msg);

        $msg = str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
        $msg = str_replace(['</n>', '</w>', '</g>'], $end, $msg);

        set_error_handler(static fn(): bool => true);
        if (!feof(self::$outputStream)) {
            fwrite(self::$outputStream, $msg);
            fflush(self::$outputStream);
        }

        restore_error_handler();
    }

    /**
     * Конструктор.
     */
    public function __construct(?string $socketName = null, array $socketContext = [])
    {
        // Сохранение всех экземпляров сервера.
        $this->serverId = spl_object_hash($this);
        $this->context = new stdClass();
        static::$servers[$this->serverId] = $this;
        static::$pidMap[$this->serverId] = [];

        // Контекст для сокета.
        if ($socketName) {
            $this->socketName = $socketName;
            $socketContext['socket']['backlog'] ??= static::DEFAULT_BACKLOG;

            foreach (self::CONTEXT_SSL as $const => $key) {
                if (!isset($socketContext['ssl'][$key])) {
                    if (function_exists('env')) {
                        $envConst = env($const);
                        $envServerConst = env(str_replace('LOCALZET', 'SERVER', $const));

                        if ($envConst !== null) {
                            $socketContext['ssl'][$key] = $envConst;
                        } elseif ($envServerConst !== null) {
                            $socketContext['ssl'][$key] = $envServerConst;
                        }
                    } elseif (defined($const)) {
                        $socketContext['ssl'][$key] = constant($const);
                    }
                }
            }

            $this->socketContext = stream_context_create($socketContext);
        }

        // Попытка включить опцию reusePort.
        /*if (is_unix()  // если это Linux
            && $socketName
            && version_compare(php_uname('r'), '3.9', 'ge') // если версия ядра >= 3.9
            && strtolower(php_uname('s')) !== 'darwin' // если не Mac OS
            && strpos($socketName, 'unix') !== 0 // если не unix-сокет
            && strpos($socketName, 'udp') !== 0) { // если не udp-сокет

            $address = parse_url($socketName);
            if (isset($address['host']) && isset($address['port'])) {
                try {
                    set_error_handler(static fn (): bool => true);
                    // Если адрес не используется, автоматически включаем опцию reusePort.
                    $server = stream_socket_server("tcp://{$address['host']}:{$address['port']}");
                    if ($server) {
                        $this->reusePort = true;
                        fclose($server);
                    }
                    restore_error_handler();
                } catch (Throwable $e) {}
            }
        }*/
    }

    /**
     * Слушать (начать прослушивание соединений).
     *
     * @throws Exception
     */
    public function listen(): void
    {
        if (!$this->socketName) {
            return;
        }

        if (!$this->mainSocket) {

            $localSocket = $this->parseSocketAddress();

            // Флаги.
            $flags = $this->transport === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                stream_context_set_option($this->socketContext, 'socket', 'so_reuseport', 1);
            }

            // Создать сокет сервера для интернета или домена Unix.
            $this->mainSocket = stream_socket_server($localSocket, $errno, $errmsg, $flags, $this->socketContext);
            if (!$this->mainSocket) {
                throw new Exception($errmsg);
            }

            if ($this->transport === 'ssl') {
                stream_socket_enable_crypto($this->mainSocket, false);
            } elseif ($this->transport === 'unix') {
                $socketFile = substr((string)$localSocket, 7);
                if ($this->user) {
                    chown($socketFile, $this->user);
                }

                if ($this->group) {
                    chgrp($socketFile, $this->group);
                }
            }

            // Попытка открыть keepalive для TCP и отключить алгоритм Nagle.
            if (function_exists('socket_import_stream') && self::BUILD_IN_TRANSPORTS[$this->transport] === 'tcp') {
                set_error_handler(static fn(): bool => true);
                $socket = socket_import_stream($this->mainSocket);
                socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
                restore_error_handler();
            }

            // Неблокирующий режим.
            stream_set_blocking($this->mainSocket, false);
        }

        $this->resumeAccept();
    }

    /**
     * Отключить прослушивание.
     */
    public function unlisten(): void
    {
        $this->pauseAccept();
        if ($this->mainSocket) {
            set_error_handler(static fn(): bool => true);
            fclose($this->mainSocket);
            restore_error_handler();
            $this->mainSocket = null;
        }
    }

    /**
     * Разбор локального адреса сокета.
     *
     * @throws Exception
     */
    protected function parseSocketAddress(): ?string
    {
        if (!$this->socketName) {
            return null;
        }

        // Получить протокол обмена данными и адрес прослушивания.
        [$scheme, $address] = explode(':', $this->socketName, 2);
        // Проверить класс протокола обмена данными.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = ucfirst($scheme);
            $this->protocol = $scheme[0] === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "localzet\\Server\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new RuntimeException("Класс \\Protocols\\$scheme не существует");
                }
            }

            if (!isset(self::BUILD_IN_TRANSPORTS[$this->transport])) {
                throw new RuntimeException('Некорректное значение server->transport: ' . var_export($this->transport, true));
            }
        } elseif ($this->transport === 'tcp') {
            $this->transport = $scheme;
        }

        // Локальный сокет
        return self::BUILD_IN_TRANSPORTS[$this->transport] . ":" . $address;
    }

    /**
     * Приостановить принятие новых соединений.
     */
    public function pauseAccept(): void
    {
        if (static::$globalEvent instanceof EventInterface && $this->pauseAccept === false && $this->mainSocket !== null) {
            static::$globalEvent->offReadable($this->mainSocket);
            $this->pauseAccept = true;
        }
    }

    /**
     * Возобновить прием новых соединений.
     */
    public function resumeAccept(): void
    {
        // Зарегистрировать слушателя для оповещения о готовности серверного сокета к чтению.
        if (static::$globalEvent instanceof EventInterface && $this->pauseAccept && $this->mainSocket !== null) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->onReadable($this->mainSocket, $this->acceptTcpConnection(...));
            } else {
                static::$globalEvent->onReadable($this->mainSocket, $this->acceptUdpConnection(...));
            }

            $this->pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     */
    public function getSocketName(): string
    {
        return $this->socketName ? lcfirst($this->socketName) : 'none';
    }

    /**
     * Запустить экземпляр сервера.
     *
     * @throws Throwable
     */
    public function run(): void
    {
        $this->listen();
        Events::emit('Server::Start', $this);
    }

    /**
     * Остановить текущий экземпляр сервера.
     *
     * @throws Throwable
     */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        Events::emit('Server::Stop', $this);
        $this->unlisten();

        // Закрыть все соединения для сервера.
        if (static::$gracefulStop) {
            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }

        // Remove server.
        foreach (static::$servers as $key => $one_server) {
            if ($one_server->serverId === $this->serverId) {
                unset(static::$servers[$key]);
            }
        }

        // Очистить обратные вызовы.
        $this->onMessage = $this->onClose = $this->onError = $this->onBufferDrain = $this->onBufferFull = null;
        $this->stopping = true;
    }

    /**
     * Принять TCP-Соединение.
     *
     * @param resource $socket
     * @throws Throwable
     */
    public function acceptTcpConnection($socket): void
    {
        // Принять соединение на сокете сервера.
        set_error_handler(static fn(): bool => true);
        $newSocket = stream_socket_accept($socket, 0, $remoteAddress);
        restore_error_handler();

        // "Громовое стадо".
        if (!$newSocket) {
            return;
        }

        // TCP-Соединение.
        $tcpConnection = new TcpConnection(static::$globalEvent, $newSocket, $remoteAddress);
        $this->connections[$tcpConnection->id] = $tcpConnection;
        $tcpConnection->server = $this;
        $tcpConnection->protocol = $this->protocol;
        $tcpConnection->transport = $this->transport;
        $tcpConnection->onMessage = $this->onMessage;
        $tcpConnection->onClose = $this->onClose;
        $tcpConnection->onError = $this->onError;
        $tcpConnection->onBufferDrain = $this->onBufferDrain;
        $tcpConnection->onBufferFull = $this->onBufferFull;

        // Попытка вызвать обратный вызов onConnect.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($tcpConnection);
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
    }

    /**
     * Принять UPD-Соединение.
     *
     * @param resource $socket
     * @throws Throwable
     */
    public function acceptUdpConnection($socket): bool
    {
        // Принять соединение на сокете сервера.
        set_error_handler(static fn(): bool => true);
        $recvBuffer = stream_socket_recvfrom($socket, UdpConnection::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        restore_error_handler();
        if (false === $recvBuffer || empty($remoteAddress)) {
            return false;
        }

        // UPD-Соединение.
        $udpConnection = new UdpConnection($socket, $remoteAddress);
        $udpConnection->protocol = $this->protocol;
        $messageCallback = $this->onMessage;
        if ($messageCallback) {
            try {
                if ($this->protocol !== null) {
                    /** @var ProtocolInterface $parser */
                    $parser = $this->protocol;
                    // @phpstan-ignore-next-line Left side of && is always true.
                    if ($parser && method_exists($parser, 'input')) {
                        while ($recvBuffer !== '') {
                            $len = $parser::input($recvBuffer, $udpConnection);
                            if ($len === 0) {
                                return true;
                            }

                            $package = substr($recvBuffer, 0, $len);
                            $recvBuffer = substr($recvBuffer, $len);
                            $data = $parser::decode($package, $udpConnection);
                            if ($data === false) {
                                continue;
                            }

                            $messageCallback($udpConnection, $data);
                        }
                    } else {
                        $data = $parser::decode($recvBuffer, $udpConnection);
                        // Отбрасывать плохие пакеты.
                        if ($data === false) {
                            return true;
                        }

                        $messageCallback($udpConnection, $data);
                    }
                } else {
                    $messageCallback($udpConnection, $recvBuffer);
                }

                ConnectionInterface::$statistics['total_request']++;
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }

        return true;
    }

    /**
     * Проверка, жив ли мастер-процесс.
     */
    protected static function checkMasterIsAlive(int $masterPid): bool
    {
        if (empty($masterPid)) {
            return false;
        }

        $masterIsAlive = posix_kill($masterPid, 0) && posix_getpid() !== $masterPid;
        if (!$masterIsAlive) {
            static::log("Мастер-процесс pid:$masterPid уже не жив");
            return false;
        }

        $cmdline = "/proc/$masterPid/cmdline";
        if (!is_readable($cmdline)) {
            return true;
        }

        $content = file_get_contents($cmdline);
        if (empty($content)) {
            return true;
        }

        return str_contains($content, 'Localzet Server') || str_contains($content, 'php');
    }
}
