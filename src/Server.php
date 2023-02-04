<?php

/**
 * @package     Triangle Server (WebCore)
 * @link        https://github.com/localzet/WebCore
 * @link        https://github.com/Triangle-org/Server
 * 
 * @author      Ivan Zorin (localzet) <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2022 Localzet Group
 * @license     https://www.localzet.com/license GNU GPLv3 License
 */

namespace localzet\Core;

use Exception;
use Throwable;
use localzet\Core\Connection\ConnectionInterface;
use localzet\Core\Connection\TcpConnection;
use localzet\Core\Connection\UdpConnection;
use localzet\Core\Events\Event;
use localzet\Core\Events\EventInterface;
use localzet\Core\Events\Revolt;
use localzet\Core\Events\Select;
use Revolt\EventLoop;


/**
 * Triangle Server
 * Контейнер прослушиваемых портов
 */
#[\AllowDynamicProperties]
class Server
{
    /**
     * Версии
     *
     * @var string
     */
    const WVERSION = '5.0.0';
    const VERSION = '1.1.9';

    /**
     * Статус: запуск
     *
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * Статус: работает
     *
     * @var int
     */
    const STATUS_RUNNING = 2;

    /**
     * Статус: остановка
     *
     * @var int
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * Статус: перезагрузка
     *
     * @var int
     */
    const STATUS_RELOADING = 8;

    /**
     * Backlog по умолчанию. Backlog - максимальная длина очереди ожидающих соединений
     *
     * @var int
     */
    const DEFAULT_BACKLOG = 102400;

    /**
     * Безопасное расстояние для соседних колонок
     *
     * @var int
     */
    const UI_SAFE_LENGTH = 4;

    /**
     * ID Сервера
     *
     * @var int
     */
    public int $id = 0;

    /**
     * Название для серверных процессов
     *
     * @var string
     */
    public string $name = 'none';

    /**
     * Количество серверных процессов
     *
     * @var int
     */
    public int $count = 1;

    /**
     * Unix пользователь (нужен root)
     *
     * @var string
     */
    public string $user = '';

    /**
     * Unix группа (нужен root)
     *
     * @var string
     */
    public string $group = '';

    /**
     * Перезагружаемый экземпляр?
     *
     * @var bool
     */
    public bool $reloadable = true;

    /**
     * Повторно использовать порт?
     *
     * @var bool
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
     * @var callable
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
     * Выполняется при выходе
     *
     * @var ?callable
     */
    public $onServerExit = null;

    /**
     * Протокол транспортного уровня
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * Хранитель всех клиентских соединений
     *
     * @var array
     */
    public array $connections = [];

    /**
     * Протокол уровня приложения
     *
     * @var ?string
     */
    public ?string $protocol = null;

    /**
     * Пауза принятия новых соединений
     *
     * @var bool
     */
    protected bool $pauseAccept = true;

    /**
     * Сервер останавливается?
     * @var bool
     */
    public bool $stopping = false;

    /**
     * В режиме демона?
     *
     * @var bool
     */
    public static bool $daemonize = false;

    /**
     * Файл Stdout
     *
     * @var string
     */
    public static string $stdoutFile = '/dev/null';

    /**
     * Файл для хранения PID мастер-процесса
     *
     * @var string
     */
    public static string $pidFile = '';

    /**
     * Файл, используемый для хранения файла состояния мастер-процесса
     *
     * @var string
     */
    public static string $statusFile = '';

    /**
     * Файл лога
     *
     * @var mixed
     */
    public static mixed $logFile = '';

    /**
     * Глобальная петля событий
     *
     * @var ?EventInterface
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
     * Класс петли событий
     *
     * @var class-string
     */
    public static string $eventLoopClass = '';

    /**
     * Название процесса
     *
     * @var string
     */
    public static string $processTitle = 'Triangle Server';

    /**
     * Таймаут после команды остановки для дочерних процессов
     * Если в течение него они не остановятся - звони киллеру
     *
     * @var int
     */
    public static int $stopTimeout = 2;

    /**
     * Команда
     * @var string
     */
    public static string $command = '';

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static int $masterPid = 0;

    /**
     * Listening socket.
     *
     * @var resource
     */
    protected $mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     *
     * @var string
     */
    protected string $socketName = '';

    /** 
     * parse from socketName avoid parse again in master or server
     * LocalSocket The format is like tcp://0.0.0.0:8080
     * @var ?string
     */
    protected ?string $localSocket = null;

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $context = null;

    /**
     * All server instances.
     *
     * @var Server[]
     */
    protected static array $servers = [];

    /**
     * All server processes pid.
     * The format is like this [serverId=>[pid=>pid, pid=>pid, ..], ..]
     *
     * @var array
     */
    protected static array $pidMap = [];

    /**
     * All server processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static array $pidsToRestart = [];

    /**
     * Mapping from PID to server process ID.
     * The format is like this [serverId=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static array $idMap = [];

    /**
     * Current status.
     *
     * @var int
     */
    protected static int $status = self::STATUS_STARTING;

    /**
     * Maximum length of the server names.
     *
     * @var int
     */
    protected static int $maxServerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static int $maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static int $maxUserNameLength = 12;

    /**
     * Maximum length of the Proto names.
     *
     * @var int
     */
    protected static int $maxProtoNameLength = 4;

    /**
     * Maximum length of the Processes names.
     *
     * @var int
     */
    protected static int $maxProcessesNameLength = 9;

    /**
     * Maximum length of the state names.
     *
     * @var int
     */
    protected static int $maxStateNameLength = 1;

    /**
     * The file to store status info of current server process.
     *
     * @var string
     */
    protected static string $statisticsFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static string $startFile = '';

    /**
     * Processes for windows.
     *
     * @var array
     */
    protected static array $processForWindows = [];

    /**
     * Status info of current server process.
     *
     * @var array
     */
    protected static array $globalStatistics = [
        'start_timestamp' => 0,
        'server_exit_info' => []
    ];

    /**
     * Available event loops.
     *
     * @var array<string, string>
     */
    protected static array $availableEventLoops = [
        'event' => Event::class,
    ];

    /**
     * PHP built-in protocols.
     *
     * @var array<string,string>
     */
    const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp'
    ];

    /**
     * PHP built-in error types.
     *
     * @var array<int,string>
     */
    const ERROR_TYPE = [
        \E_ERROR => 'E_ERROR', // 1
        \E_WARNING => 'E_WARNING', // 2
        \E_PARSE => 'E_PARSE', // 4
        \E_NOTICE => 'E_NOTICE', // 8
        \E_CORE_ERROR => 'E_CORE_ERROR', // 16
        \E_CORE_WARNING => 'E_CORE_WARNING', // 32
        \E_COMPILE_ERROR => 'E_COMPILE_ERROR', // 64
        \E_COMPILE_WARNING => 'E_COMPILE_WARNING', // 128
        \E_USER_ERROR => 'E_USER_ERROR', // 256
        \E_USER_WARNING => 'E_USER_WARNING', // 512
        \E_USER_NOTICE => 'E_USER_NOTICE', // 1024
        \E_STRICT => 'E_STRICT', // 2048
        \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        \E_DEPRECATED => 'E_DEPRECATED', // 8192
        \E_USER_DEPRECATED => 'E_USER_DEPRECATED' // 16384
    ];

    /**
     * Graceful stop or not.
     *
     * @var bool
     */
    protected static bool $gracefulStop = false;

    /**
     * Standard output stream
     * @var resource
     */
    protected static $outputStream = null;

    /**
     * If $outputStream support decorated
     * @var bool
     */
    protected static ?bool $outputDecorated = null;

    /**
     * Хэш-идентификатор объекта сервера (уникальный идентификатор)
     *
     * @var ?string
     */
    protected ?string $serverId = null;

    /**
     * Запуск всех экземпляров сервера
     *
     * @return void
     * @throws Exception
     */
    public static function runAll()
    {
        static::checkSapiEnv();
        static::init();
        static::parseCommand();
        static::lock();
        static::daemonize();
        static::initServers();
        static::installSignal();
        static::saveMasterPid();
        static::lock(\LOCK_UN);
        static::displayUI();
        static::forkServers();
        static::resetStd();
        static::monitorServers();
    }

    /**
     * Проверка SAPI
     *
     * @return void
     */
    protected static function checkSapiEnv()
    {
        // Только для CLI
        if (\PHP_SAPI !== 'cli') {
            exit("WebCore запускается только из терминала \n");
        }
    }

    /**
     * Инициализация
     *
     * @return void
     */
    protected static function init()
    {
        \set_error_handler(function ($code, $msg, $file, $line) {
            static::safeEcho("$msg in file $file on line $line\n");
        });

        // Start
        $backtrace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        static::$startFile = end($backtrace)['file'];

        $uniquePrefix = \str_replace('/', '_', static::$startFile);

        // Pid
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$uniquePrefix.pid";
        }

        // Log
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../webcore.log';
        }

        if (!\is_file(static::$logFile)) {
            // if /runtime/logs  default folder not exists
            if (!is_dir(dirname(static::$logFile))) {
                @mkdir(dirname(static::$logFile), 0777, true);
            }
            \touch(static::$logFile);
            \chmod(static::$logFile, 0622);
        }

        // Состояние
        static::$status = static::STATUS_STARTING;

        // Для статистики
        static::$globalStatistics['start_timestamp'] = \time();

        // Название процесса
        static::setProcessTitle(static::$processTitle . ': мастер-процесс  start_file=' . static::$startFile);

        // Init data for server id.
        static::initId();

        // Timer init.
        Timer::init();
    }

    /**
     * Lock.
     *
     * @return void
     */
    protected static function lock($flag = \LOCK_EX)
    {
        static $fd;

        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $lockFile = static::$pidFile . '.lock';
        $fd = $fd ?: \fopen($lockFile, 'a+');

        if ($fd) {
            flock($fd, $flag);
            if ($flag === \LOCK_UN) {
                fclose($fd);
                $fd = null;
                clearstatcache();
                if (\is_file($lockFile)) {
                    unlink($lockFile);
                }
            }
        }
    }

    /**
     * Init All server instances.
     *
     * @return void
     * @throws Exception
     */
    protected static function initServers()
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        static::$statisticsFile = static::$statusFile ?: __DIR__ . '/../webcore-' . posix_getpid() . '.status';

        foreach (static::$servers as $server) {
            // Server name.
            if (empty($server->name)) {
                $server->name = 'none';
            }

            // Get unix user of the server process.
            if (empty($server->user)) {
                $server->user = static::getCurrentUser();
            } else {
                if (\posix_getuid() !== 0 && $server->user !== static::getCurrentUser()) {
                    static::log('Внимание: У вас должен быть root, чтобы изменить UID и GID.');
                }
            }

            // Socket name.
            $server->socket = $server->getSocketName();

            // Status name.
            $server->state = '<g> [OK] </g>';

            // Get column mapping for UI
            foreach (static::getUiColumns() as $columnName => $prop) {
                !isset($server->{$prop}) && $server->{$prop} = 'NNNN';
                $propLength = \strlen($server->{$prop});
                $key = 'max' . \ucfirst(\strtolower($columnName)) . 'NameLength';
                static::$$key = \max(static::$$key, $propLength);
            }

            // Listen.
            if (!$server->reusePort) {
                $server->listen();
            }
        }
    }

    /**
     * Get all server instances.
     *
     * @return Server[]
     */
    public static function getAllServers(): array
    {
        return static::$servers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop(): EventInterface
    {
        return static::$globalEvent;
    }

    /**
     * Get main socket resource
     * @return resource
     */
    public function getMainSocket()
    {
        return $this->mainSocket;
    }

    /**
     * Init idMap.
     *
     * @return void
     */
    protected static function initId()
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
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser(): string
    {
        $userInfo = \posix_getpwuid(\posix_getuid());
        return $userInfo['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        $tmpArgv = static::getArgv();
        if (\in_array('-q', $tmpArgv)) {
            return;
        }
        if (\DIRECTORY_SEPARATOR !== '/') {
            static::safeEcho("----------------------- WEBCORE -----------------------------\r\n");
            static::safeEcho('WebCore version:' . static::VERSION . '          PHP version:' . \PHP_VERSION . "\r\n");
            static::safeEcho("------------------------ SERVERS -------------------------------\r\n");
            static::safeEcho("server                        listen                              processes status\r\n");
            return;
        }

        //show version
        $lineVersion = 'WebCore version:' . static::VERSION . \str_pad('PHP version:', 22, ' ', \STR_PAD_LEFT) . \PHP_VERSION . \str_pad('Event-loop:', 22, ' ', \STR_PAD_LEFT) . static::getEventLoopName() . \PHP_EOL;
        !\defined('LINE_VERSIOIN_LENGTH') && \define('LINE_VERSIOIN_LENGTH', \strlen($lineVersion));
        $totalLength = static::getSingleLineTotalLength();
        $lineOne = '<n>' . \str_pad('<w> WEBCORE </w>', $totalLength + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . '</n>' . \PHP_EOL;
        $lineTwo = \str_pad('<w> SERVERS </w>', $totalLength + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . \PHP_EOL;
        static::safeEcho($lineOne . $lineVersion . $lineTwo);

        //Show title
        $title = '';
        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . \ucfirst(\strtolower($columnName)) . 'NameLength';
            //just keep compatible with listen name
            $columnName === 'socket' && $columnName = 'listen';
            $title .= "<w>{$columnName}</w>" . \str_pad('', static::$$key + static::UI_SAFE_LENGTH - \strlen($columnName));
        }
        $title && static::safeEcho($title . \PHP_EOL);

        //Show content
        foreach (static::$servers as $server) {
            $content = '';
            foreach (static::getUiColumns() as $columnName => $prop) {
                $key = 'max' . \ucfirst(\strtolower($columnName)) . 'NameLength';
                \preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>)/is", $server->{$prop}, $matches);
                $placeHolderLength = !empty($matches) ? \strlen(\implode('', $matches[0])) : 0;
                $content .= \str_pad($server->{$prop}, static::$$key + static::UI_SAFE_LENGTH + $placeHolderLength);
            }
            $content && static::safeEcho($content . \PHP_EOL);
        }

        //Show last line
        $lineLast = \str_pad('', static::getSingleLineTotalLength(), '-') . \PHP_EOL;
        !empty($content) && static::safeEcho($lineLast);

        if (static::$daemonize) {
            global $argv;
            $startFile = $argv[0];
            static::safeEcho('Выполните "php ' . $startFile . ' stop" для остановки. WebCore запущен.' . "\n\n");
        } else {
            static::safeEcho("Нажмите Ctrl+C для остановки. WebCore запущен.\n");
        }
    }

    /**
     * Get UI columns to be shown in terminal
     *
     * 1. $columnMap: ['ui_column_name' => 'clas_property_name']
     * 2. Consider move into configuration in future
     *
     * @return array
     */
    public static function getUiColumns(): array
    {
        return [
            'proto' => 'transport',
            'user' => 'user',
            'server' => 'name',
            'socket' => 'socket',
            'processes' => 'count',
            'state' => 'state',
        ];
    }

    /**
     * Get single line total length for ui
     *
     * @return int
     */
    public static function getSingleLineTotalLength(): int
    {
        $totalLength = 0;

        foreach (static::getUiColumns() as $columnName => $prop) {
            $key = 'max' . \ucfirst(\strtolower($columnName)) . 'NameLength';
            $totalLength += static::$$key + static::UI_SAFE_LENGTH;
        }

        //Keep beauty when show less columns
        !\defined('LINE_VERSIOIN_LENGTH') && \define('LINE_VERSIOIN_LENGTH', 0);
        $totalLength <= LINE_VERSIOIN_LENGTH && $totalLength = LINE_VERSIOIN_LENGTH;

        return $totalLength;
    }

    /**
     * Parse command.
     *
     * @return void
     */
    protected static function parseCommand()
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        global $argv;
        // Check argv;
        $startFile = $argv[0];
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
            if (\in_array($value, $availableCommands)) {
                $command = $value;
            } elseif (\in_array($value, $availableMode)) {
                $mode = $value;
            }
        }

        if (!$command) {
            exit($usage);
        }

        // Start command.
        $modeStr = '';
        if ($command === 'start') {
            if ($mode === '-d' || static::$daemonize) {
                $modeStr = 'в фоновом режиме';
            } else {
                $modeStr = 'в режиме разработки';
            }
        }
        static::log("WebCore [$startFile] $command $modeStr");

        // Get master process PID.
        $masterPid = \is_file(static::$pidFile) ? (int)\file_get_contents(static::$pidFile) : 0;
        // Master is still alive?
        if (static::checkMasterIsAlive($masterPid)) {
            if ($command === 'start') {
                static::log("WebCore [$startFile] уже запущен");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("WebCore [$startFile] не запущен");
            exit;
        }

        $statisticsFile = static::$statusFile ?: __DIR__ . "/../webcore-$masterPid.status";

        // execute command.
        switch ($command) {
            case 'start':
                if ($mode === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                while (1) {
                    if (\is_file($statisticsFile)) {
                        @\unlink($statisticsFile);
                    }
                    // Master process will send SIGIOT signal to all child processes.
                    \posix_kill($masterPid, SIGIOT);
                    // Sleep 1 second.
                    \sleep(1);
                    // Clear terminal.
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m", true);
                    }
                    // Echo status data.
                    static::safeEcho(static::formatStatusData($statisticsFile));
                    if ($mode !== '-d') {
                        @\unlink($statisticsFile);
                        exit(0);
                    }
                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");
                }
            case 'connections':
                if (\is_file($statisticsFile) && \is_writable($statisticsFile)) {
                    \unlink($statisticsFile);
                }
                // Master process will send SIGIO signal to all child processes.
                \posix_kill($masterPid, SIGIO);
                // Waiting amoment.
                \usleep(500000);
                // Display statisitcs data from a disk file.
                if (\is_readable($statisticsFile)) {
                    \readfile($statisticsFile);
                }
                exit(0);
            case 'restart':
            case 'stop':
                if ($mode === '-g') {
                    static::$gracefulStop = true;
                    $sig = \SIGQUIT;
                    static::log("WebCore [$startFile] плавно останавливается ...");
                } else {
                    static::$gracefulStop = false;
                    $sig = \SIGINT;
                    static::log("WebCore [$startFile] останавливается ...");
                }
                // Send stop signal to master process.
                $masterPid && \posix_kill($masterPid, $sig);
                // Timeout.
                $timeout = static::$stopTimeout + 3;
                $startTime = \time();
                // Check master process is still alive?
                while (1) {
                    $masterIsAlive = $masterPid && \posix_kill($masterPid, 0);
                    if ($masterIsAlive) {
                        // Timeout?
                        if (!static::$gracefulStop && \time() - $startTime >= $timeout) {
                            static::log("WebCore [$startFile] не остановлен!");
                            exit;
                        }
                        // Waiting amoment.
                        \usleep(10000);
                        continue;
                    }
                    // Stop success.
                    static::log("WebCore [$startFile] остановлен");
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
                    $sig = \SIGUSR2;
                } else {
                    $sig = \SIGUSR1;
                }
                \posix_kill($masterPid, $sig);
                exit;
            default:
                static::safeEcho('Неизвестная команда: ' . $command . "\n");
                exit($usage);
        }
    }

    /**
     * Get argv.
     *
     * @return mixed
     */
    public static function getArgv(): mixed
    {
        global $argv;
        return isset($argv[1]) ? $argv : (static::$command ? \explode(' ', static::$command) : $argv);
    }

    /**
     * Данные о состоянии
     *
     * @param $statisticsFile
     * @return string
     */
    protected static function formatStatusData($statisticsFile): string
    {
        static $totalRequestCache = [];
        if (!\is_readable($statisticsFile)) {
            return '';
        }
        $info = \file($statisticsFile, \FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }
        $statusStr = '';
        $currentTotalRequest = [];
        $serverInfo = \unserialize($info[0]);
        \ksort($serverInfo, SORT_NUMERIC);
        unset($info[0]);
        $dataWaitingSort = [];
        $readProcessStatus = false;
        $totalRequests = 0;
        $totalQps = 0;
        $totalConnections = 0;
        $totalFails = 0;
        $totalMemory = 0;
        $totalTimers = 0;
        $maxLen1 = static::$maxSocketNameLength;
        $maxLen2 = static::$maxServerNameLength;
        foreach ($info as $value) {
            if (!$readProcessStatus) {
                $statusStr .= $value . "\n";
                if (\preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $readProcessStatus = true;
                }
                continue;
            }
            if (\preg_match('/^[0-9]+/', $value, $pidMath)) {
                $pid = $pidMath[0];
                $dataWaitingSort[$pid] = $value;
                if (\preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    $totalMemory += \intval(\str_ireplace('M', '', $match[1]));
                    $maxLen1 = \max($maxLen1, \strlen($match[2]));
                    $maxLen2 = \max($maxLen2, \strlen($match[3]));
                    $totalConnections += \intval($match[4]);
                    $totalFails += \intval($match[5]);
                    $totalTimers += \intval($match[6]);
                    $currentTotalRequest[$pid] = $match[7];
                    $totalRequests += \intval($match[7]);
                }
            }
        }
        foreach ($serverInfo as $pid => $info) {
            if (!isset($dataWaitingSort[$pid])) {
                $statusStr .= "$pid\t" . \str_pad('N/A', 7) . " "
                    . \str_pad($info['listen'], static::$maxSocketNameLength) . " "
                    . \str_pad($info['name'], static::$maxServerNameLength) . " "
                    . \str_pad('N/A', 11) . " " . \str_pad('N/A', 9) . " "
                    . \str_pad('N/A', 7) . " " . \str_pad('N/A', 13) . " N/A    [занят] \n";
                continue;
            }
            //$qps = isset($totalRequestCache[$pid]) ? $currentTotalRequest[$pid]
            if (!isset($totalRequestCache[$pid]) || !isset($currentTotalRequest[$pid])) {
                $qps = 0;
            } else {
                $qps = $currentTotalRequest[$pid] - $totalRequestCache[$pid];
                $totalQps += $qps;
            }
            $statusStr .= $dataWaitingSort[$pid] . " " . \str_pad($qps, 6) . " [не занят]\n";
        }
        $totalRequestCache = $currentTotalRequest;
        $statusStr .= "----------------------------------------------PROCESS STATUS---------------------------------------------------\n";
        $statusStr .= "Итог\t" . \str_pad($totalMemory . 'M', 7) . " "
            . \str_pad('-', $maxLen1) . " "
            . \str_pad('-', $maxLen2) . " "
            . \str_pad($totalConnections, 11) . " " . \str_pad($totalFails, 9) . " "
            . \str_pad($totalTimers, 7) . " " . \str_pad($totalRequests, 13) . " "
            . \str_pad($totalQps, 6) . " [Итог] \n";
        return $statusStr;
    }


    /**
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal()
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $signals = [\SIGINT, \SIGTERM, \SIGHUP, \SIGTSTP, \SIGQUIT, \SIGUSR1, \SIGUSR2, \SIGIOT, \SIGIO];
        foreach ($signals as $signal) {
            \pcntl_signal($signal, [static::class, 'signalHandler'], false);
        }
        // ignore
        \pcntl_signal(\SIGPIPE, \SIG_IGN, false);
    }

    /**
     * Reinstall signal handler.
     *
     * @return void
     */
    protected static function reinstallSignal()
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $signals = [\SIGINT, \SIGTERM, \SIGHUP, \SIGTSTP, \SIGQUIT, \SIGUSR1, \SIGUSR2, \SIGIOT, \SIGIO];
        foreach ($signals as $signal) {
            \pcntl_signal($signal, \SIG_IGN, false);
            static::$globalEvent->onSignal($signal, [static::class, 'signalHandler']);
        };
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     * @throws Exception
     */
    public static function signalHandler(int $signal)
    {
        switch ($signal) {
                // Stop.
            case \SIGINT:
            case \SIGTERM:
            case \SIGHUP:
            case \SIGTSTP:
                static::$gracefulStop = false;
                static::stopAll();
                break;
                // Graceful stop.
            case \SIGQUIT:
                static::$gracefulStop = true;
                static::stopAll();
                break;
                // Reload.
            case \SIGUSR2:
            case \SIGUSR1:
                if (static::$status === static::STATUS_RELOADING || static::$status === static::STATUS_SHUTDOWN) {
                    return;
                }
                static::$gracefulStop = $signal === \SIGUSR2;
                static::$pidsToRestart = static::getAllServerPids();
                static::reload();
                break;
                // Show status.
            case \SIGIOT:
                static::writeStatisticsToStatusFile();
                break;
                // Show connection status.
            case \SIGIO:
                static::writeConnectionsStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Run as daemon mode.
     *
     * @throws Exception
     */
    protected static function daemonize()
    {
        if (!static::$daemonize || \DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        \umask(0);
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('Ошибка ответвления');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === \posix_setsid()) {
            throw new Exception('Ошибка установки SID');
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('Ошибка ответвления');
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @param bool $throwException
     * @return void
     * @throws Exception
     */
    public static function resetStd(bool $throwException = true)
    {
        if (!static::$daemonize || \DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = \fopen(static::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            \set_error_handler(function () {
            });
            if ($STDOUT) {
                \fclose($STDOUT);
            }
            if ($STDERR) {
                \fclose($STDERR);
            }
            if (\is_resource(\STDOUT)) {
                \fclose(\STDOUT);
            }
            if (\is_resource(\STDERR)) {
                \fclose(\STDERR);
            }
            $STDOUT = \fopen(static::$stdoutFile, "a");
            $STDERR = \fopen(static::$stdoutFile, "a");
            // Fix standard output cannot redirect of PHP 8.1.8's bug
            if (\function_exists('posix_isatty') && \posix_isatty(2)) {
                \ob_start(function ($string) {
                    \file_put_contents(static::$stdoutFile, $string, FILE_APPEND);
                }, 1);
            }
            // change output stream
            static::$outputStream = null;
            static::outputStream($STDOUT);
            \restore_error_handler();
            return;
        }

        if ($throwException) {
            throw new Exception('Не могу открыть stdoutFile ' . static::$stdoutFile);
        }
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        static::$masterPid = \posix_getpid();
        if (false === \file_put_contents(static::$pidFile, static::$masterPid)) {
            throw new Exception('Не могу сохранить PID в  ' . static::$pidFile);
        }
    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName(): string
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        if (\class_exists(EventLoop::class)) {
            static::$eventLoopClass = Revolt::class;
            return static::$eventLoopClass;
        }

        $loopName = '';
        foreach (static::$availableEventLoops as $name => $class) {
            if (\extension_loaded($name)) {
                $loopName = $name;
                break;
            }
        }

        if ($loopName) {
            static::$eventLoopClass = static::$availableEventLoops[$loopName];
        } else {
            static::$eventLoopClass = Select::class;
        }
        return static::$eventLoopClass;
    }

    /**
     * Get all pids of server processes.
     *
     * @return array
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
     * Fork some server processes.
     *
     * @return void
     */
    protected static function forkServers()
    {
        if (\DIRECTORY_SEPARATOR === '/') {
            static::forkServersForLinux();
        } else {
            static::forkServersForWindows();
        }
    }

    /**
     * Fork some server processes.
     *
     * @return void
     * @throws Exception
     */
    protected static function forkServersForLinux()
    {
        foreach (static::$servers as $server) {
            if (static::$status === static::STATUS_STARTING) {
                if (empty($server->name)) {
                    $server->name = $server->getSocketName();
                }
                $serverNameLength = \strlen($server->name);
                if (static::$maxServerNameLength < $serverNameLength) {
                    static::$maxServerNameLength = $serverNameLength;
                }
            }

            while (\count(static::$pidMap[$server->serverId]) < $server->count) {
                static::forkOneServerForLinux($server);
            }
        }
    }

    /**
     * Fork some server processes.
     *
     * @return void
     * @throws Exception
     */
    protected static function forkServersForWindows()
    {
        $files = static::getStartFilesForWindows();
        if (\in_array('-q', static::getArgv()) || \count($files) === 1) {
            if (\count(static::$servers) > 1) {
                static::safeEcho("@@@ Ошибка: инициализация нескольких серверов в одном php-файле не поддерживается @@@\r\n");
            } elseif (\count(static::$servers) <= 0) {
                exit("@@@ Нет сервера @@@\r\n\r\n");
            }

            \reset(static::$servers);
            /** @var Server $server */
            $server = current(static::$servers);

            // Display UI.
            static::safeEcho(\str_pad($server->name, 21) . \str_pad($server->getSocketName(), 36) . \str_pad($server->count, 10) . "[ok]\n");
            $server->listen();
            $server->run();
            exit("@@@ child exit @@@\r\n");
        } else {
            static::$globalEvent = new Select();
            static::$globalEvent->setErrorHandler(function ($exception) {
                static::stopAll(250, $exception);
            });
            Timer::init(static::$globalEvent);
            foreach ($files as $startFile) {
                static::forkOneServerForWindows($startFile);
            }
        }
    }

    /**
     * Get start files for windows.
     *
     * @return array
     */
    public static function getStartFilesForWindows(): array
    {
        $files = [];
        foreach (static::getArgv() as $file) {
            if (\is_file($file)) {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    /**
     * Fork one server process.
     *
     * @param string $startFile
     */
    public static function forkOneServerForWindows(string $startFile)
    {
        $startFile = \realpath($startFile);

        $descriptorspec = array(STDIN, STDOUT, STDOUT);

        $pipes = array();
        $process = \proc_open("php \"$startFile\" -q", $descriptorspec, $pipes);

        if (empty(static::$globalEvent)) {
            static::$globalEvent = new Select();
            static::$globalEvent->setErrorHandler(function ($exception) {
                static::stopAll(250, $exception);
            });
            Timer::init(static::$globalEvent);
        }

        // 保存子进程句柄
        static::$processForWindows[$startFile] = array($process, $startFile);
    }

    /**
     * check server status for windows.
     * @return void
     */
    public static function checkServerStatusForWindows()
    {
        foreach (static::$processForWindows as $processData) {
            $process = $processData[0];
            $startFile = $processData[1];
            $status = \proc_get_status($process);
            if (isset($status['running'])) {
                if (!$status['running']) {
                    static::safeEcho("Процесс $startFile завершен и пытается перезапуститься\n");
                    \proc_close($process);
                    static::forkOneServerForWindows($startFile);
                }
            } else {
                static::safeEcho("Ошибка proc_get_status\n");
            }
        }
    }

    /**
     * Fork one server process.
     *
     * @param self $server
     * @throws Exception
     */
    protected static function forkOneServerForLinux(self $server)
    {
        // Get available server id.
        $id = static::getId($server->serverId, 0);
        $pid = \pcntl_fork();
        // For master process.
        if ($pid > 0) {
            static::$pidMap[$server->serverId][$pid] = $pid;
            static::$idMap[$server->serverId][$id] = $pid;
        }
        // For child processes.
        elseif (0 === $pid) {
            \srand();
            \mt_srand();
            static::$gracefulStop = false;
            if ($server->reusePort) {
                $server->listen();
            }
            if (static::$status === static::STATUS_STARTING) {
                static::resetStd();
            }
            static::$pidsToRestart = static::$pidMap = [];
            // Remove other listener.
            foreach (static::$servers as $key => $oneServer) {
                if ($oneServer->serverId !== $server->serverId) {
                    $oneServer->unlisten();
                    unset(static::$servers[$key]);
                }
            }
            Timer::delAll();
            static::setProcessTitle(self::$processTitle . ': процесс сервера  ' . $server->name . ' ' . $server->getSocketName());
            $server->setUserAndGroup();
            $server->id = $id;
            $server->run();
            if (static::$status !== self::STATUS_SHUTDOWN) {
                $err = new Exception('Ошибка event-loop');
                static::log($err);
                exit(250);
            }
            exit(0);
        } else {
            throw new Exception('Ошибка forkOneServer');
        }
    }

    /**
     * Get server id.
     *
     * @param string $serverId
     * @param int $pid
     *
     * @return false|int|string
     */
    protected static function getId(string $serverId, int $pid): bool|int|string
    {
        return \array_search($pid, static::$idMap[$serverId]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $userInfo = \posix_getpwnam($this->user);
        if (!$userInfo) {
            static::log("Внимание: Пользователь {$this->user} не существует");
            return;
        }
        $uid = $userInfo['uid'];
        // Get gid.
        if ($this->group) {
            $groupInfo = \posix_getgrnam($this->group);
            if (!$groupInfo) {
                static::log("Внимание: Группа {$this->group} не существует");
                return;
            }
            $gid = $groupInfo['gid'];
        } else {
            $gid = $userInfo['gid'];
        }

        // Set uid and gid.
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($userInfo['name'], $gid) || !\posix_setuid($uid)) {
                static::log('Внимание: Ошибка изменения GID или UID');
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle(string $title)
    {
        \set_error_handler(function () {
        });

        \cli_set_process_title($title);
        \restore_error_handler();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorServers()
    {
        if (\DIRECTORY_SEPARATOR === '/') {
            static::monitorServersForLinux();
        } else {
            static::monitorServersForWindows();
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     * @throws Exception
     */
    protected static function monitorServersForLinux()
    {
        static::$status = static::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            \pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            $pid = \pcntl_wait($status, \WUNTRACED);
            // Calls signal handlers for pending signals again.
            \pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out which server process exited.
                foreach (static::$pidMap as $serverId => $serverPidArray) {
                    if (isset($serverPidArray[$pid])) {
                        $server = static::$servers[$serverId];
                        // Fix exit with status 2 for php8.2
                        if ($status === \SIGINT && static::$status === static::STATUS_SHUTDOWN) {
                            $status = 0;
                        }
                        // Exit status.
                        if ($status !== 0) {
                            static::log("WebCore [{$server->name}:$pid] завершился со статусом $status");
                        }

                        // onServerExit
                        if ($server->onServerExit) {
                            try {
                                ($server->onServerExit)($server, $status, $pid);
                            } catch (Throwable $exception) {
                                static::log("WebCore [{$server->name}] onServerExit $exception");
                            }
                        }

                        // For Statistics.
                        if (!isset(static::$globalStatistics['server_exit_info'][$serverId][$status])) {
                            static::$globalStatistics['server_exit_info'][$serverId][$status] = 0;
                        }
                        ++static::$globalStatistics['server_exit_info'][$serverId][$status];

                        // Clear process data.
                        unset(static::$pidMap[$serverId][$pid]);

                        // Mark id is available.
                        $id = static::getId($serverId, $pid);
                        static::$idMap[$serverId][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new server process.
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::forkServers();
                    // If reloading continue.
                    if (isset(static::$pidsToRestart[$pid])) {
                        unset(static::$pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }

            // If shutdown state and all child processes exited then master process exit.
            if (static::$status === static::STATUS_SHUTDOWN && !static::getAllServerPids()) {
                static::exitAndClearAll();
            }
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorServersForWindows()
    {
        Timer::add(1, "\\localzet\\Core\\Server::checkServerStatusForWindows");

        static::$globalEvent->run();
    }

    /**
     * Exit current process.
     */
    protected static function exitAndClearAll()
    {
        foreach (static::$servers as $server) {
            $socketName = $server->getSocketName();
            if ($server->transport === 'unix' && $socketName) {
                list(, $address) = \explode(':', $socketName, 2);
                $address = substr($address, strpos($address, '/') + 2);
                @\unlink($address);
            }
        }
        @\unlink(static::$pidFile);
        static::log("WebCore [" . \basename(static::$startFile) . "] был остановлен");
        if (static::$onMasterStop) {
            \call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     * @throws Exception
     */
    protected static function reload()
    {
        // For master process.
        if (static::$masterPid === \posix_getpid()) {
            $sig = static::$gracefulStop ? \SIGUSR2 : \SIGUSR1;
            // Set reloading state.
            if (static::$status !== static::STATUS_RELOADING && static::$status !== static::STATUS_SHUTDOWN) {
                static::log("WebCore [" . \basename(static::$startFile) . "] обновляется");
                static::$status = static::STATUS_RELOADING;

                static::resetStd(false);
                // Try to emit onMasterReload callback.
                if (static::$onMasterReload) {
                    try {
                        \call_user_func(static::$onMasterReload);
                    } catch (Throwable $e) {
                        static::stopAll(250, $e);
                    }
                    static::initId();
                }

                // Send reload signal to all child processes.
                $reloadablePidArray = [];
                foreach (static::$pidMap as $serverId => $serverPidArray) {
                    $server = static::$servers[$serverId];
                    if ($server->reloadable) {
                        foreach ($serverPidArray as $pid) {
                            $reloadablePidArray[$pid] = $pid;
                        }
                    } else {
                        foreach ($serverPidArray as $pid) {
                            // Send reload signal to a server process which reloadable is false.
                            \posix_kill($pid, $sig);
                        }
                    }
                }
                // Get all pids that are waiting reload.
                static::$pidsToRestart = \array_intersect(static::$pidsToRestart, $reloadablePidArray);
            }

            // Reload complete.
            if (empty(static::$pidsToRestart)) {
                if (static::$status !== static::STATUS_SHUTDOWN) {
                    static::$status = static::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $oneServerPid = \current(static::$pidsToRestart);
            // Send reload signal to a server process.
            \posix_kill($oneServerPid, $sig);
            // If the process does not exit after stopTimeout seconds try to kill it.
            if (!static::$gracefulStop) {
                Timer::add(static::$stopTimeout, '\posix_kill', [$oneServerPid, \SIGKILL], false);
            }
        }
        // For child processes.
        else {
            \reset(static::$servers);
            $server = \current(static::$servers);
            // Try to emit onServerReload callback.
            if ($server->onServerReload) {
                try {
                    \call_user_func($server->onServerReload, $server);
                } catch (Throwable $e) {
                    static::stopAll(250, $e);
                }
            }

            if ($server->reloadable) {
                static::stopAll();
            } else {
                static::resetStd(false);
            }
        }
    }

    /**
     * Stop all.
     *
     * @param int $code
     * @param mixed $log
     */
    public static function stopAll(int $code = 0, mixed $log = '')
    {
        if ($log) {
            static::log($log);
        }

        static::$status = static::STATUS_SHUTDOWN;
        // For master process.
        if (\DIRECTORY_SEPARATOR === '/' && static::$masterPid === \posix_getpid()) {
            static::log("WebCore [" . \basename(static::$startFile) . "] останавливается ...");
            $serverPidArray = static::getAllServerPids();
            // Send stop signal to all child processes.
            $sig = static::$gracefulStop ? \SIGQUIT : \SIGINT;
            foreach ($serverPidArray as $serverPid) {
                // Fix exit with status 2 for php8.2
                if ($sig === \SIGINT && !static::$daemonize) {
                    Timer::add(1, '\posix_kill', [$serverPid, \SIGINT], false);
                } else {
                    \posix_kill($serverPid, $sig);
                }
                if (!static::$gracefulStop) {
                    Timer::add(ceil(static::$stopTimeout), '\posix_kill', [$serverPid, \SIGKILL], false);
                }
            }
            Timer::add(1, "\\localzet\\Core\\Server::checkIfChildRunning");
            // Remove statistics file.
            if (\is_file(static::$statisticsFile)) {
                @\unlink(static::$statisticsFile);
            }
        }
        // For child processes.
        else {
            // Execute exit.
            foreach (static::$servers as $server) {
                if (!$server->stopping) {
                    $server->stop();
                    $server->stopping = true;
                }
            }
            if (!static::$gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$servers = [];
                if (static::$globalEvent) {
                    static::$globalEvent->stop();
                }

                try {
                    exit($code);
                } catch (Exception $e) {
                }
            }
        }
    }

    /**
     * check if child processes is really running
     */
    public static function checkIfChildRunning()
    {
        foreach (static::$pidMap as $serverId => $serverPidArray) {
            foreach ($serverPidArray as $pid => $serverPid) {
                if (!\posix_kill($pid, 0)) {
                    unset(static::$pidMap[$serverId][$pid]);
                }
            }
        }
    }

    /**
     * Get process status.
     *
     * @return int
     */
    public static function getStatus(): int
    {
        return static::$status;
    }

    /**
     * If stop gracefully.
     *
     * @return bool
     */
    public static function getGracefulStop(): bool
    {
        return static::$gracefulStop;
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // For master process.
        if (static::$masterPid === \posix_getpid()) {
            $allServerInfo = [];
            foreach (static::$pidMap as $serverId => $pidArray) {
                /** @var /localzet/Core/Server $server */
                $server = static::$servers[$serverId];
                foreach ($pidArray as $pid) {
                    $allServerInfo[$pid] = ['name' => $server->name, 'listen' => $server->getSocketName()];
                }
            }

            \file_put_contents(static::$statisticsFile, \serialize($allServerInfo) . "\n", \FILE_APPEND);
            $loadavg = \function_exists('sys_getloadavg') ? \array_map('round', \sys_getloadavg(), [2, 2, 2]) : ['-', '-', '-'];
            \file_put_contents(
                static::$statisticsFile,
                "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                'WebCore version:' . static::VERSION . "          PHP version:" . \PHP_VERSION . "\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                'start time:' . \date(
                    'Y-m-d H:i:s',
                    static::$globalStatistics['start_timestamp']
                ) .
                    '   запущен ' .
                    \floor(
                        (\time() -
                            static::$globalStatistics['start_timestamp']) /
                            (24 * 60 * 60)
                    ) .
                    ' дней ' .
                    \floor(
                        ((\time() -
                            static::$globalStatistics['start_timestamp']) %
                            (24 * 60 * 60)) /
                            (60 * 60)
                    ) .
                    " часов   \n",
                FILE_APPEND
            );
            $loadStr = 'load average: ' . \implode(", ", $loadavg);
            \file_put_contents(
                static::$statisticsFile,
                \str_pad($loadStr, 33) . 'event-loop:' . static::getEventLoopName() . "\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                \count(static::$pidMap) . ' servers       ' . \count(static::getAllServerPids()) . " processes\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                \str_pad('server_name', static::$maxServerNameLength) . " exit_status      exit_count\n",
                \FILE_APPEND
            );
            foreach (static::$pidMap as $serverId => $serverPidArray) {
                $server = static::$servers[$serverId];
                if (isset(static::$globalStatistics['server_exit_info'][$serverId])) {
                    foreach (static::$globalStatistics['server_exit_info'][$serverId] as $serverExitStatus => $serverExitCount) {
                        \file_put_contents(
                            static::$statisticsFile,
                            \str_pad($server->name, static::$maxServerNameLength) . " " . \str_pad(
                                $serverExitStatus,
                                16
                            ) . " $serverExitCount\n",
                            \FILE_APPEND
                        );
                    }
                } else {
                    \file_put_contents(
                        static::$statisticsFile,
                        \str_pad($server->name, static::$maxServerNameLength) . " " . \str_pad(0, 16) . " 0\n",
                        \FILE_APPEND
                    );
                }
            }
            \file_put_contents(
                static::$statisticsFile,
                "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                "pid\tmemory  " . \str_pad('listening', static::$maxSocketNameLength) . " " . \str_pad(
                    'server_name',
                    static::$maxServerNameLength
                ) . " connections " . \str_pad('send_fail', 9) . " "
                    . \str_pad('timers', 8) . \str_pad('total_request', 13) . " qps    status\n",
                \FILE_APPEND
            );

            \chmod(static::$statisticsFile, 0722);

            foreach (static::getAllServerPids() as $serverPid) {
                \posix_kill($serverPid, \SIGIOT);
            }
            return;
        }

        // For child processes.
        \gc_collect_cycles();
        if (\function_exists('gc_mem_caches')) {
            \gc_mem_caches();
        }
        \reset(static::$servers);
        /** @var static $server */
        $server = current(static::$servers);
        $serverStatusStr = \posix_getpid() . "\t" . \str_pad(round(memory_get_usage() / (1024 * 1024), 2) . "M", 7)
            . " " . \str_pad($server->getSocketName(), static::$maxSocketNameLength) . " "
            . \str_pad(($server->name === $server->getSocketName() ? 'none' : $server->name), static::$maxServerNameLength)
            . " ";
        $serverStatusStr .= \str_pad(ConnectionInterface::$statistics['connection_count'], 11)
            . " " . \str_pad(ConnectionInterface::$statistics['send_fail'], 9)
            . " " . \str_pad(static::$globalEvent->getTimerCount(), 7)
            . " " . \str_pad(ConnectionInterface::$statistics['total_request'], 13) . "\n";
        \file_put_contents(static::$statisticsFile, $serverStatusStr, \FILE_APPEND);
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeConnectionsStatisticsToStatusFile()
    {
        // For master process.
        if (static::$masterPid === \posix_getpid()) {
            \file_put_contents(
                static::$statisticsFile,
                "--------------------------------------------------------------------- WEBCORE CONNECTION STATUS --------------------------------------------------------------------------------\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$statisticsFile,
                "PID      Server          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n",
                \FILE_APPEND
            );
            \chmod(static::$statisticsFile, 0722);
            foreach (static::getAllServerPids() as $serverPid) {
                \posix_kill($serverPid, \SIGIO);
            }
            return;
        }

        // For child processes.
        $bytesFormat = function ($bytes) {
            if ($bytes > 1024 * 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024 * 1024), 1) . 'TB';
            }
            if ($bytes > 1024 * 1024 * 1024) {
                return round($bytes / (1024 * 1024 * 1024), 1) . 'GB';
            }
            if ($bytes > 1024 * 1024) {
                return round($bytes / (1024 * 1024), 1) . 'MB';
            }
            if ($bytes > 1024) {
                return round($bytes / 1024, 1) . 'KB';
            }
            return $bytes . 'B';
        };

        $pid = \posix_getpid();
        $str = '';
        \reset(static::$servers);
        $currentServer = current(static::$servers);
        $defaultServerName = $currentServer->name;

        /** @var static $server */
        foreach (TcpConnection::$connections as $connection) {
            /** @var \localzet\Core\Connection\TcpConnection $connection */
            $transport = $connection->transport;
            $ipv4 = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6 = $connection->isIpV6() ? ' 1' : ' 0';
            $recvQ = $bytesFormat($connection->getRecvBufferQueueSize());
            $sendQ = $bytesFormat($connection->getSendBufferQueueSize());
            $localAddress = \trim($connection->getLocalAddress());
            $remoteAddress = \trim($connection->getRemoteAddress());
            $state = $connection->getStatus(false);
            $bytesRead = $bytesFormat($connection->bytesRead);
            $bytesWritten = $bytesFormat($connection->bytesWritten);
            $id = $connection->id;
            $protocol = $connection->protocol ? $connection->protocol : $connection->transport;
            $pos = \strrpos($protocol, '\\');
            if ($pos) {
                $protocol = \substr($protocol, $pos + 1);
            }
            if (\strlen($protocol) > 15) {
                $protocol = \substr($protocol, 0, 13) . '..';
            }
            $serverName = isset($connection->server) ? $connection->server->name : $defaultServerName;
            if (\strlen($serverName) > 14) {
                $serverName = \substr($serverName, 0, 12) . '..';
            }
            $str .= \str_pad($pid, 9) . \str_pad($serverName, 16) . \str_pad($id, 10) . \str_pad($transport, 8)
                . \str_pad($protocol, 16) . \str_pad($ipv4, 7) . \str_pad($ipv6, 7) . \str_pad($recvQ, 13)
                . \str_pad($sendQ, 13) . \str_pad($bytesRead, 13) . \str_pad($bytesWritten, 13) . ' '
                . \str_pad($state, 14) . ' ' . \str_pad($localAddress, 22) . ' ' . \str_pad($remoteAddress, 22) . "\n";
        }
        if ($str) {
            \file_put_contents(static::$statisticsFile, $str, \FILE_APPEND);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        if (static::STATUS_SHUTDOWN !== static::$status) {
            $errorMsg = \DIRECTORY_SEPARATOR === '/' ? 'WebCore [' . \posix_getpid() . '] процесс завершен' : 'Серверный процесс завершен';
            $errors = error_get_last();
            if (
                $errors && ($errors['type'] === \E_ERROR ||
                    $errors['type'] === \E_PARSE ||
                    $errors['type'] === \E_CORE_ERROR ||
                    $errors['type'] === \E_COMPILE_ERROR ||
                    $errors['type'] === \E_RECOVERABLE_ERROR)
            ) {
                $errorMsg .= ' с ошибкой: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} в файле {$errors['file']} на {$errors['line']} строке\"";
            }
            static::log($errorMsg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param int $type
     * @return string
     */
    protected static function getErrorType(int $type): string
    {
        return self::ERROR_TYPE[$type] ?? '';
    }

    /**
     * Log.
     *
     * @param mixed $msg
     * @return void
     */
    public static function log(mixed $msg)
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg);
        }
        \file_put_contents(static::$logFile, \date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (\DIRECTORY_SEPARATOR === '/' ? \posix_getpid() : 1) . ' ' . $msg, \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Safe Echo.
     * @param string $msg
     * @param bool $decorated
     * @return bool
     */
    public static function safeEcho(string $msg, bool $decorated = false): bool
    {
        $stream = static::outputStream();
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = \str_replace(['<n>', '<w>', '<g>'], [$line, $white, $green], $msg);
            $msg = \str_replace(['</n>', '</w>', '</g>'], $end, $msg);
        } elseif (!static::$outputDecorated) {
            return false;
        }
        \fwrite($stream, $msg);
        \fflush($stream);
        return true;
    }

    /**
     * @param resource|null $stream
     * @return false|resource
     */
    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$outputStream ?: \STDOUT;
        }
        if (!$stream || !\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            return false;
        }
        $stat = \fstat($stream);
        if (!$stat) {
            return false;
        }

        if (($stat['mode'] & 0170000) === 0100000) {
            static::$outputDecorated = false;
        } else {
            static::$outputDecorated =
                \DIRECTORY_SEPARATOR === '/' && // linux or unix
                \function_exists('posix_isatty') &&
                \posix_isatty($stream); // whether is interactive terminal
        }
        return static::$outputStream = $stream;
    }

    /**
     * Construct.
     *
     * @param string|null $socketName
     * @param array $contextOption
     */
    public function __construct(string $socketName = null, array $contextOption = [])
    {
        // Save all server instances.
        $this->serverId = \spl_object_hash($this);
        static::$servers[$this->serverId] = $this;
        static::$pidMap[$this->serverId] = [];

        // Context for socket.
        if ($socketName) {
            $this->socketName = $socketName;
            if (!isset($contextOption['socket']['backlog'])) {
                $contextOption['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->context = \stream_context_create($contextOption);
        }

        // Try to turn reusePort on.
        /*if (\DIRECTORY_SEPARATOR === '/'  // if linux
            && $socketName
            && \version_compare(php_uname('r'), '3.9', 'ge') // if kernel >=3.9
            && \strtolower(\php_uname('s')) !== 'darwin' // if not Mac OS
            && strpos($socketName,'unix') !== 0 // if not unix socket
            && strpos($socketName,'udp') !== 0) { // if not udp socket
            
            $address = \parse_url($socketName);
            if (isset($address['host']) && isset($address['port'])) {
                try {
                    \set_error_handler(function(){});
                    // If address not in use, turn reusePort on automatically.
                    $server = stream_socket_server("tcp://{$address['host']}:{$address['port']}");
                    if ($server) {
                        $this->reusePort = true;
                        fclose($server);
                    }
                    \restore_error_handler();
                } catch (\Throwable $e) {}
            }
        }*/
    }

    /**
     * Listen.
     *
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->socketName) {
            return;
        }

        if (!$this->mainSocket) {

            $localSocket = $this->parseSocketAddress();

            // Flag.
            $flags = $this->transport === 'udp' ? \STREAM_SERVER_BIND : \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                \stream_context_set_option($this->context, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            $this->mainSocket = \stream_socket_server($localSocket, $errno, $errmsg, $flags, $this->context);
            if (!$this->mainSocket) {
                throw new Exception($errmsg);
            }

            if ($this->transport === 'ssl') {
                \stream_socket_enable_crypto($this->mainSocket, false);
            } elseif ($this->transport === 'unix') {
                $socketFile = \substr($localSocket, 7);
                if ($this->user) {
                    \chown($socketFile, $this->user);
                }
                if ($this->group) {
                    \chgrp($socketFile, $this->group);
                }
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && self::BUILD_IN_TRANSPORTS[$this->transport] === 'tcp') {
                \set_error_handler(function () {
                });
                $socket = \socket_import_stream($this->mainSocket);
                \socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
                \restore_error_handler();
            }

            // Non blocking.
            \stream_set_blocking($this->mainSocket, false);
        }

        $this->resumeAccept();
    }

    /**
     * Unlisten.
     *
     * @return void
     */
    public function unlisten()
    {
        $this->pauseAccept();
        if ($this->mainSocket) {
            \set_error_handler(function () {
            });
            \fclose($this->mainSocket);
            \restore_error_handler();
            $this->mainSocket = null;
        }
    }

    /**
     * Parse local socket address.
     *
     * @throws Exception
     */
    protected function parseSocketAddress(): ?string
    {
        if (!$this->socketName) {
            return null;
        }
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $this->socketName, 2);
        // Check application layer protocol class.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = \ucfirst($scheme);
            $this->protocol = \substr($scheme, 0, 1) === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "localzet\\Core\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("Класс \\Protocols\\$scheme Не существует");
                }
            }

            if (!isset(self::BUILD_IN_TRANSPORTS[$this->transport])) {
                throw new Exception('Некорректный server->transport ' . \var_export($this->transport, true));
            }
        } else {
            if ($this->transport === 'tcp') {
                $this->transport = $scheme;
            }
        }
        //local socket
        return self::BUILD_IN_TRANSPORTS[$this->transport] . ":" . $address;
    }

    /**
     * Pause accept new connections.
     *
     * @return void
     */
    public function pauseAccept()
    {
        if (static::$globalEvent && false === $this->pauseAccept && $this->mainSocket) {
            static::$globalEvent->offReadable($this->mainSocket);
            $this->pauseAccept = true;
        }
    }

    /**
     * Resume accept new connections.
     *
     * @return void
     */
    public function resumeAccept()
    {
        // Register a listener to be notified when server socket is ready to read.
        if (static::$globalEvent && true === $this->pauseAccept && $this->mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->onReadable($this->mainSocket, [$this, 'acceptTcpConnection']);
            } else {
                static::$globalEvent->onReadable($this->mainSocket, [$this, 'acceptUdpConnection']);
            }
            $this->pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName(): string
    {
        return $this->socketName ? \lcfirst($this->socketName) : 'none';
    }

    /**
     * Run server instance.
     *
     * @return void
     */
    public function run()
    {
        //Update process state.
        static::$status = static::STATUS_RUNNING;

        // Register shutdown function for checking errors.
        \register_shutdown_function(["\\localzet\\Core\\Server", 'checkErrors']);

        // Create a global event loop.
        if (!static::$globalEvent) {
            $eventLoopClass = static::getEventLoopName();
            static::$globalEvent = new $eventLoopClass;
            static::$globalEvent->setErrorHandler(function ($exception) {
                static::stopAll(250, $exception);
            });
            $this->resumeAccept();
        }

        // Reinstall signal.
        static::reinstallSignal();

        // Init Timer.
        Timer::init(static::$globalEvent);

        // Set an empty onMessage callback.
        if (empty($this->onMessage)) {
            $this->onMessage = function () {
            };
        }

        \restore_error_handler();

        // Try to emit onServerStart callback.
        if ($this->onServerStart) {
            try {
                ($this->onServerStart)($this);
            } catch (Throwable $e) {
                // Avoid rapid infinite loop exit.
                sleep(1);
                static::stopAll(250, $e);
            }
        }

        // Main loop.
        static::$globalEvent->run();
    }

    /**
     * Stop current server instance.
     *
     * @return void
     */
    public function stop()
    {
        // Try to emit onServerStop callback.
        if ($this->onServerStop) {
            try {
                ($this->onServerStop)($this);
            } catch (Throwable $e) {
                static::log($e);
            }
        }
        // Remove listener for server socket.
        $this->unlisten();
        // Close all connections for the server.
        if (!static::$gracefulStop) {
            foreach ($this->connections as $connection) {
                $connection->close();
            }
        }
        // Clear callback.
        $this->onMessage = $this->onClose = $this->onError = $this->onBufferDrain = $this->onBufferFull = null;
    }

    /**
     * Accept a connection.
     *
     * @param resource $socket
     * @return void
     */
    public function acceptTcpConnection($socket)
    {
        // Accept a connection on server socket.
        \set_error_handler(function () {
        });
        $newSocket = \stream_socket_accept($socket, 0, $remoteAddress);
        \restore_error_handler();

        // Thundering herd.
        if (!$newSocket) {
            return;
        }

        // TcpConnection.
        $connection = new TcpConnection(static::$globalEvent, $newSocket, $remoteAddress);
        $this->connections[$connection->id] = $connection;
        $connection->server = $this;
        $connection->protocol = $this->protocol;
        $connection->transport = $this->transport;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        $connection->onBufferDrain = $this->onBufferDrain;
        $connection->onBufferFull = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($connection);
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdpConnection($socket): bool
    {
        \set_error_handler(function () {
        });
        $recvBuffer = \stream_socket_recvfrom($socket, UdpConnection::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        \restore_error_handler();
        if (false === $recvBuffer || empty($remoteAddress)) {
            return false;
        }
        // UdpConnection.
        $connection = new UdpConnection($socket, $remoteAddress);
        $connection->protocol = $this->protocol;
        $messageCb = $this->onMessage;
        if ($messageCb) {
            try {
                if ($this->protocol !== null) {
                    /** @var \localzet\Core\Protocols\ProtocolInterface $parser */
                    $parser = $this->protocol;
                    if ($parser && \method_exists($parser, 'input')) {
                        while ($recvBuffer !== '') {
                            $len = $parser::input($recvBuffer, $connection);
                            if ($len === 0) {
                                return true;
                            }
                            $package = \substr($recvBuffer, 0, $len);
                            $recvBuffer = \substr($recvBuffer, $len);
                            $data = $parser::decode($package, $connection);
                            if ($data === false) {
                                continue;
                            }
                            $messageCb($connection, $data);
                        }
                    } else {
                        $data = $parser::decode($recvBuffer, $connection);
                        // Discard bad packets.
                        if ($data === false) {
                            return true;
                        }
                        $messageCb($connection, $data);
                    }
                } else {
                    $messageCb($connection, $recvBuffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (Throwable $e) {
                static::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Check master process is alive
     *
     * @param int $masterPid
     * @return bool
     */
    protected static function checkMasterIsAlive(int $masterPid): bool
    {
        if (empty($masterPid)) {
            return false;
        }

        $masterIsAlive = $masterPid && \posix_kill($masterPid, 0) && \posix_getpid() !== $masterPid;
        if (!$masterIsAlive) {
            return false;
        }

        $cmdline = "/proc/{$masterPid}/cmdline";
        if (!is_readable($cmdline) || empty(static::$processTitle)) {
            return true;
        }

        $content = file_get_contents($cmdline);
        if (empty($content)) {
            return true;
        }

        return stripos($content, static::$processTitle) !== false || stripos($content, 'php') !== false;
    }
}
