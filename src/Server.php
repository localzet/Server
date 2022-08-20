<?php

/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io/webcore
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core;

require_once __DIR__ . '/Lib/Constants.php';

use localzet\Core\Events\EventInterface;
use localzet\Core\Connection\ConnectionInterface;
use localzet\Core\Connection\TcpConnection;
use localzet\Core\Connection\UdpConnection;
use localzet\Core\Timer;
use localzet\Core\Events\Select;
use \Exception;

/**
 * WebCore Server
 * Контейнер прослушиваемых портов
 */
class Server
{
    /**
     * Версии
     *
     * @var string
     */
    const WVERSION = '4.1.0';
    const VERSION = '1.1.3';

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
     * Максимальный размер пакета UDP
     *
     * @var int
     */
    const MAX_UDP_PACKAGE_SIZE = 65535;

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
    public $id = 0;

    /**
     * Название для серверных процессов
     *
     * @var string
     */
    public $name = 'none';

    /**
     * Количество серверных процессов
     *
     * @var int
     */
    public $count = 1;

    /**
     * Unix user of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $user = '';

    /**
     * Unix group of processes, needs appropriate privileges (usually root).
     *
     * @var string
     */
    public $group = '';

    /**
     * reloadable.
     *
     * @var bool
     */
    public $reloadable = true;

    /**
     * reuse port.
     *
     * @var bool
     */
    public $reusePort = false;

    /**
     * Emitted when server processes start.
     *
     * @var callable
     */
    public $onServerStart = null;

    /**
     * Emitted when a socket connection is successfully established.
     *
     * @var callable
     */
    public $onConnect = null;

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * Emitted when server processes stoped.
     *
     * @var callable
     */
    public $onServerStop = null;

    /**
     * Emitted when server processes get reload signal.
     *
     * @var callable
     */
    public $onServerReload = null;

    /**
     * Emitted when server processes exited.
     *
     * @var callable
     */
    public $onServerExit = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Store all connections of clients.
     *
     * @var array
     */
    public $connections = array();

    /**
     * Application layer protocol.
     *
     * @var string
     */
    public $protocol = null;

    /**
     * Root path for autoload.
     *
     * @var string
     */
    protected $_autoloadRootPath = '';

    /**
     * Pause accept new connections or not.
     *
     * @var bool
     */
    protected $_pauseAccept = true;

    /**
     * Is server stopping ?
     * @var bool
     */
    public $stopping = false;

    /**
     * Daemonize.
     *
     * @var bool
     */
    public static $daemonize = false;

    /**
     * Stdout file.
     *
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID.
     *
     * @var string
     */
    public static $pidFile = '';

    /**
     * The file used to store the master process status file.
     *
     * @var string
     */
    public static $statusFile = '';

    /**
     * Log file.
     *
     * @var mixed
     */
    public static $logFile = '';

    /**
     * Global event loop.
     *
     * @var EventInterface
     */
    public static $globalEvent = null;

    /**
     * Emitted when the master process get reload signal.
     *
     * @var callable
     */
    public static $onMasterReload = null;

    /**
     * Emitted when the master process terminated.
     *
     * @var callable
     */
    public static $onMasterStop = null;

    /**
     * EventLoopClass
     *
     * @var string
     */
    public static $eventLoopClass = '';

    /**
     * Process title
     *
     * @var string
     */
    public static $processTitle = 'WebCore Server';

    /**
     * After sending the stop command to the child process stopTimeout seconds,
     * if the process is still living then forced to kill.
     *
     * @var int
     */
    public static $stopTimeout = 2;

    /**
     * The PID of master process.
     *
     * @var int
     */
    protected static $_masterPid = 0;

    /**
     * Listening socket.
     *
     * @var resource
     */
    protected $_mainSocket = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80 .
     *
     * @var string
     */
    protected $_socketName = '';

    /** parse from _socketName avoid parse again in master or server
     * LocalSocket The format is like tcp://0.0.0.0:8080
     * @var string
     */

    protected $_localSocket = null;

    /**
     * Context of socket.
     *
     * @var resource
     */
    protected $_context = null;

    /**
     * All server instances.
     *
     * @var Server[]
     */
    protected static $_servers = array();

    /**
     * All server processes pid.
     * The format is like this [server_id=>[pid=>pid, pid=>pid, ..], ..]
     *
     * @var array
     */
    protected static $_pidMap = array();

    /**
     * All server processes waiting for restart.
     * The format is like this [pid=>pid, pid=>pid].
     *
     * @var array
     */
    protected static $_pidsToRestart = array();

    /**
     * Mapping from PID to server process ID.
     * The format is like this [server_id=>[0=>$pid, 1=>$pid, ..], ..].
     *
     * @var array
     */
    protected static $_idMap = array();

    /**
     * Current status.
     *
     * @var int
     */
    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the server names.
     *
     * @var int
     */
    protected static $_maxServerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static $_maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;

    /**
     * Maximum length of the Proto names.
     *
     * @var int
     */
    protected static $_maxProtoNameLength = 4;

    /**
     * Maximum length of the Processes names.
     *
     * @var int
     */
    protected static $_maxProcessesNameLength = 9;

    /**
     * Maximum length of the Status names.
     *
     * @var int
     */
    protected static $_maxStatusNameLength = 1;

    /**
     * The file to store status info of current server process.
     *
     * @var string
     */
    protected static $_statisticsFile = '';

    /**
     * Start file.
     *
     * @var string
     */
    protected static $_startFile = '';

    /**
     * OS.
     *
     * @var string
     */
    protected static $_OS = \OS_TYPE_LINUX;

    /**
     * Processes for windows.
     *
     * @var array
     */
    protected static $_processForWindows = array();

    /**
     * Status info of current server process.
     *
     * @var array
     */
    protected static $_globalStatistics = array(
        'start_timestamp'  => 0,
        'server_exit_info' => array()
    );

    /**
     * Available event loops.
     *
     * @var array
     */
    protected static $_availableEventLoops = array(
        'event'    => '\localzet\Core\Events\Event',
        'libevent' => '\localzet\Core\Events\Libevent'
    );

    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'tcp'
    );

    /**
     * PHP built-in error types.
     *
     * @var array
     */
    protected static $_errorType = array(
        \E_ERROR             => 'E_ERROR',             // 1
        \E_WARNING           => 'E_WARNING',           // 2
        \E_PARSE             => 'E_PARSE',             // 4
        \E_NOTICE            => 'E_NOTICE',            // 8
        \E_CORE_ERROR        => 'E_CORE_ERROR',        // 16
        \E_CORE_WARNING      => 'E_CORE_WARNING',      // 32
        \E_COMPILE_ERROR     => 'E_COMPILE_ERROR',     // 64
        \E_COMPILE_WARNING   => 'E_COMPILE_WARNING',   // 128
        \E_USER_ERROR        => 'E_USER_ERROR',        // 256
        \E_USER_WARNING      => 'E_USER_WARNING',      // 512
        \E_USER_NOTICE       => 'E_USER_NOTICE',       // 1024
        \E_STRICT            => 'E_STRICT',            // 2048
        \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR', // 4096
        \E_DEPRECATED        => 'E_DEPRECATED',        // 8192
        \E_USER_DEPRECATED   => 'E_USER_DEPRECATED'   // 16384
    );

    /**
     * Graceful stop or not.
     *
     * @var bool
     */
    protected static $_gracefulStop = false;

    /**
     * Standard output stream
     * @var resource
     */
    protected static $_outputStream = null;

    /**
     * If $outputStream support decorated
     * @var bool
     */
    protected static $_outputDecorated = null;

    /**
     * Запуск всех экземпляров сервера
     *
     * @return void
     */
    public static function runAll()
    {
        static::checkSapiEnv();
        static::init();
        static::parseCommand();
        static::daemonize();
        static::initServers();
        static::installSignal();
        static::saveMasterPid();
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
        if (\PHP_SAPI !== 'cli') {
            exit("WebCore запускается из терминала \n");
        }
        if (\DIRECTORY_SEPARATOR === '\\') {
            self::$_OS = \OS_TYPE_WINDOWS;
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
            Server::safeEcho("$msg in file $file on line $line\n");
        });

        // Start 
        $backtrace = \debug_backtrace();
        static::$_startFile = $backtrace[\count($backtrace) - 1]['file'];


        $unique_prefix = \str_replace('/', '_', static::$_startFile);

        // Pid
        if (empty(static::$pidFile)) {
            static::$pidFile = __DIR__ . "/../$unique_prefix.pid";
        }

        // Log
        if (empty(static::$logFile)) {
            static::$logFile = __DIR__ . '/../webcore.log';
        }
        $log_file = (string)static::$logFile;
        if (!\is_file($log_file)) {
            \touch($log_file);
            \chmod($log_file, 0622);
        }

        // Состояние
        static::$_status = static::STATUS_STARTING;

        // Для статистики
        static::$_globalStatistics['start_timestamp'] = \time();

        // Название процесса
        static::setProcessTitle(static::$processTitle . ': мастер-процесс  start_file=' . static::$_startFile);

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
    protected static function lock()
    {
        $fd = \fopen(static::$_startFile, 'r');
        if ($fd && !flock($fd, LOCK_EX)) {
            static::log('WebCore [' . static::$_startFile . '] уже запущенн.');
            exit;
        }
    }

    /**
     * Unlock.
     *
     * @return void
     */
    protected static function unlock()
    {
        $fd = \fopen(static::$_startFile, 'r');
        $fd && flock($fd, \LOCK_UN);
    }

    /**
     * Init All server instances.
     *
     * @return void
     */
    protected static function initServers()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }

        static::$_statisticsFile =  static::$statusFile ? static::$statusFile : __DIR__ . '/../webcore-' . posix_getpid() . '.status';

        foreach (static::$_servers as $server) {
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
            $server->status = '<g> [OK] </g>';

            // Get column mapping for UI
            foreach (static::getUiColumns() as $column_name => $prop) {
                !isset($server->{$prop}) && $server->{$prop} = 'NNNN';
                $prop_length = \strlen((string) $server->{$prop});
                $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
                static::$$key = \max(static::$$key, $prop_length);
            }

            // Listen.
            if (!$server->reusePort) {
                $server->listen();
            }
        }
    }

    /**
     * Reload all server instances.
     *
     * @return void
     */
    public static function reloadAllServers()
    {
        static::init();
        static::initServers();
        static::displayUI();
        static::$_status = static::STATUS_RELOADING;
    }

    /**
     * Get all server instances.
     *
     * @return array
     */
    public static function getAllServers()
    {
        return static::$_servers;
    }

    /**
     * Get global event-loop instance.
     *
     * @return EventInterface
     */
    public static function getEventLoop()
    {
        return static::$globalEvent;
    }

    /**
     * Get main socket resource
     * @return resource
     */
    public function getMainSocket()
    {
        return $this->_mainSocket;
    }

    /**
     * Init idMap.
     * return void
     */
    protected static function initId()
    {
        foreach (static::$_servers as $server_id => $server) {
            $new_id_map = array();
            $server->count = $server->count < 1 ? 1 : $server->count;
            for ($key = 0; $key < $server->count; $key++) {
                $new_id_map[$key] = isset(static::$_idMap[$server_id][$key]) ? static::$_idMap[$server_id][$key] : 0;
            }
            static::$_idMap[$server_id] = $new_id_map;
        }
    }

    /**
     * Get unix user of current porcess.
     *
     * @return string
     */
    protected static function getCurrentUser()
    {
        $user_info = \posix_getpwuid(\posix_getuid());
        return $user_info['name'];
    }

    /**
     * Display staring UI.
     *
     * @return void
     */
    protected static function displayUI()
    {
        global $argv;
        if (\in_array('-q', $argv)) {
            return;
        }
        if (static::$_OS !== \OS_TYPE_LINUX) {
            static::safeEcho("----------------------- WEBCORE -----------------------------\r\n");
            static::safeEcho('WebCore version:' . static::VERSION . '          PHP version:' . \PHP_VERSION . "\r\n");
            static::safeEcho("------------------------ WORKERS -------------------------------\r\n");
            static::safeEcho("server                        listen                              processes status\r\n");
            return;
        }

        //show version
        $line_version = 'WebCore version:' . static::VERSION . \str_pad('PHP version:', 22, ' ', \STR_PAD_LEFT) . \PHP_VERSION;
        $line_version .= \str_pad('Event-Loop:', 22, ' ', \STR_PAD_LEFT) . static::getEventLoopName() . \PHP_EOL;
        if (!\defined('LINE_VERSIOIN_LENGTH')) \define('LINE_VERSIOIN_LENGTH', \strlen($line_version));
        $total_length = static::getSingleLineTotalLength();
        $line_one = '<n>' . \str_pad('<w> WEBCORE </w>', $total_length + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . '</n>' . \PHP_EOL;
        $line_two = \str_pad('<w> WORKERS </w>', $total_length  + \strlen('<w></w>'), '-', \STR_PAD_BOTH) . \PHP_EOL;
        static::safeEcho($line_one . $line_version . $line_two);

        //Show title
        $title = '';
        foreach (static::getUiColumns() as $column_name => $prop) {
            $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
            //just keep compatible with listen name
            $column_name === 'socket' && $column_name = 'listen';
            $title .= "<w>{$column_name}</w>"  .  \str_pad('', static::$$key + static::UI_SAFE_LENGTH - \strlen($column_name));
        }
        $title && static::safeEcho($title . \PHP_EOL);

        //Show content
        foreach (static::$_servers as $server) {
            $content = '';
            foreach (static::getUiColumns() as $column_name => $prop) {
                $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
                \preg_match_all("/(<n>|<\/n>|<w>|<\/w>|<g>|<\/g>)/is", (string) $server->{$prop}, $matches);
                $place_holder_length = !empty($matches) ? \strlen(\implode('', $matches[0])) : 0;
                $content .= \str_pad((string) $server->{$prop}, static::$$key + static::UI_SAFE_LENGTH + $place_holder_length);
            }
            $content && static::safeEcho($content . \PHP_EOL);
        }

        //Show last line
        $line_last = \str_pad('', static::getSingleLineTotalLength(), '-') . \PHP_EOL;
        !empty($content) && static::safeEcho($line_last);

        if (static::$daemonize) {
            $tmpArgv = $argv;
            foreach ($tmpArgv as $index => $value) {
                if ($value == '-d') {
                    unset($tmpArgv[$index]);
                } elseif ($value == 'start' || $value == 'restart') {
                    $tmpArgv[$index] = 'stop';
                }
            }
            static::safeEcho("Input \"php " . implode(' ', $tmpArgv) . "\" to stop. Start success.\n\n");
        } else {
            static::safeEcho("Press Ctrl+C to stop. Start success.\n");
        }
    }

    /**
     * Get UI columns to be shown in terminal
     *
     * 1. $column_map: array('ui_column_name' => 'clas_property_name')
     * 2. Consider move into configuration in future
     *
     * @return array
     */
    public static function getUiColumns()
    {
        return array(
            'proto'     =>  'transport',
            'user'      =>  'user',
            'server'    =>  'name',
            'socket'    =>  'socket',
            'processes' =>  'count',
            'status'    =>  'status',
        );
    }

    /**
     * Get single line total length for ui
     *
     * @return int
     */
    public static function getSingleLineTotalLength()
    {
        $total_length = 0;

        foreach (static::getUiColumns() as $column_name => $prop) {
            $key = '_max' . \ucfirst(\strtolower($column_name)) . 'NameLength';
            $total_length += static::$$key + static::UI_SAFE_LENGTH;
        }

        //keep beauty when show less colums
        if (!\defined('LINE_VERSIOIN_LENGTH')) \define('LINE_VERSIOIN_LENGTH', 0);
        $total_length <= LINE_VERSIOIN_LENGTH && $total_length = LINE_VERSIOIN_LENGTH;

        return $total_length;
    }

    /**
     * Parse command.
     *
     * @return void
     */
    protected static function parseCommand()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        global $argv;
        // Check argv;
        $start_file = $argv[0];
        $usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart server in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop server.\n\t\tUse mode -g to stop gracefully.\nrestart\t\tRestart servers.\n\t\tUse mode -d to start in DAEMON mode.\n\t\tUse mode -g to stop gracefully.\nreload\t\tReload codes.\n\t\tUse mode -g to reload gracefully.\nstatus\t\tGet server status.\n\t\tUse mode -d to show live status.\nconnections\tGet server connections.\n";
        $available_commands = array(
            'start',
            'stop',
            'restart',
            'reload',
            'status',
            'connections',
        );
        $available_mode = array(
            '-d',
            '-g'
        );
        $command = $mode = '';
        foreach ($argv as $value) {
            if (\in_array($value, $available_commands)) {
                $command = $value;
            } elseif (\in_array($value, $available_mode)) {
                $mode = $value;
            }
        }

        if (!$command) {
            exit($usage);
        }

        // Start command.
        $mode_str = '';
        if ($command === 'start') {
            if ($mode === '-d' || static::$daemonize) {
                $mode_str = 'in DAEMON mode';
            } else {
                $mode_str = 'in DEBUG mode';
            }
        }
        static::log("WebCore [$start_file] $command $mode_str");

        // Get master process PID.
        $master_pid      = \is_file(static::$pidFile) ? (int)\file_get_contents(static::$pidFile) : 0;
        // Master is still alive?
        if (static::checkMasterIsAlive($master_pid)) {
            if ($command === 'start') {
                static::log("WebCore [$start_file] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            static::log("WebCore [$start_file] not run");
            exit;
        }

        $statistics_file =  static::$statusFile ? static::$statusFile : __DIR__ . "/../webcore-$master_pid.status";

        // execute command.
        switch ($command) {
            case 'start':
                if ($mode === '-d') {
                    static::$daemonize = true;
                }
                break;
            case 'status':
                while (1) {
                    if (\is_file($statistics_file)) {
                        @\unlink($statistics_file);
                    }
                    // Master process will send SIGIOT signal to all child processes.
                    \posix_kill($master_pid, SIGIOT);
                    // Sleep 1 second.
                    \sleep(1);
                    // Clear terminal.
                    if ($mode === '-d') {
                        static::safeEcho("\33[H\33[2J\33(B\33[m", true);
                    }
                    // Echo status data.
                    static::safeEcho(static::formatStatusData($statistics_file));
                    if ($mode !== '-d') {
                        exit(0);
                    }
                    static::safeEcho("\nPress Ctrl+C to quit.\n\n");
                }
                exit(0);
            case 'connections':
                if (\is_file($statistics_file) && \is_writable($statistics_file)) {
                    \unlink($statistics_file);
                }
                // Master process will send SIGIO signal to all child processes.
                \posix_kill($master_pid, SIGIO);
                // Waiting amoment.
                \usleep(500000);
                // Display statisitcs data from a disk file.
                if (\is_readable($statistics_file)) {
                    \readfile($statistics_file);
                }
                exit(0);
            case 'restart':
            case 'stop':
                if ($mode === '-g') {
                    static::$_gracefulStop = true;
                    $sig = \SIGQUIT;
                    static::log("WebCore [$start_file] is gracefully stopping ...");
                } else {
                    static::$_gracefulStop = false;
                    $sig = \SIGINT;
                    static::log("WebCore [$start_file] is stopping ...");
                }
                // Send stop signal to master process.
                $master_pid && \posix_kill($master_pid, $sig);
                // Timeout.
                $timeout    = static::$stopTimeout + 3;
                $start_time = \time();
                // Check master process is still alive?
                while (1) {
                    $master_is_alive = $master_pid && \posix_kill((int) $master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout?
                        if (!static::$_gracefulStop && \time() - $start_time >= $timeout) {
                            static::log("WebCore [$start_file] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        \usleep(10000);
                        continue;
                    }
                    // Stop success.
                    static::log("WebCore [$start_file] stop success");
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
                \posix_kill($master_pid, $sig);
                exit;
            default:
                if (isset($command)) {
                    static::safeEcho('Unknown command: ' . $command . "\n");
                }
                exit($usage);
        }
    }

    /**
     * Format status data.
     *
     * @param $statistics_file
     * @return string
     */
    protected static function formatStatusData($statistics_file)
    {
        static $total_request_cache = array();
        if (!\is_readable($statistics_file)) {
            return '';
        }
        $info = \file($statistics_file, \FILE_IGNORE_NEW_LINES);
        if (!$info) {
            return '';
        }
        $status_str = '';
        $current_total_request = array();
        $server_info = \unserialize($info[0]);
        \ksort($server_info, SORT_NUMERIC);
        unset($info[0]);
        $data_waiting_sort = array();
        $read_process_status = false;
        $total_requests = 0;
        $total_qps = 0;
        $total_connections = 0;
        $total_fails = 0;
        $total_memory = 0;
        $total_timers = 0;
        $maxLen1 = static::$_maxSocketNameLength;
        $maxLen2 = static::$_maxServerNameLength;
        foreach ($info as $key => $value) {
            if (!$read_process_status) {
                $status_str .= $value . "\n";
                if (\preg_match('/^pid.*?memory.*?listening/', $value)) {
                    $read_process_status = true;
                }
                continue;
            }
            if (\preg_match('/^[0-9]+/', $value, $pid_math)) {
                $pid = $pid_math[0];
                $data_waiting_sort[$pid] = $value;
                if (\preg_match('/^\S+?\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?(\S+?)\s+?/', $value, $match)) {
                    $total_memory += \intval(\str_ireplace('M', '', $match[1]));
                    $maxLen1 = \max($maxLen1, \strlen($match[2]));
                    $maxLen2 = \max($maxLen2, \strlen($match[3]));
                    $total_connections += \intval($match[4]);
                    $total_fails += \intval($match[5]);
                    $total_timers += \intval($match[6]);
                    $current_total_request[$pid] = $match[7];
                    $total_requests += \intval($match[7]);
                }
            }
        }
        foreach ($server_info as $pid => $info) {
            if (!isset($data_waiting_sort[$pid])) {
                $status_str .= "$pid\t" . \str_pad('N/A', 7) . " "
                    . \str_pad($info['listen'], static::$_maxSocketNameLength) . " "
                    . \str_pad($info['name'], static::$_maxServerNameLength) . " "
                    . \str_pad('N/A', 11) . " " . \str_pad('N/A', 9) . " "
                    . \str_pad('N/A', 7) . " " . \str_pad('N/A', 13) . " N/A    [busy] \n";
                continue;
            }
            //$qps = isset($total_request_cache[$pid]) ? $current_total_request[$pid]
            if (!isset($total_request_cache[$pid]) || !isset($current_total_request[$pid])) {
                $qps = 0;
            } else {
                $qps = $current_total_request[$pid] - $total_request_cache[$pid];
                $total_qps += $qps;
            }
            $status_str .= $data_waiting_sort[$pid] . " " . \str_pad($qps, 6) . " [idle]\n";
        }
        $total_request_cache = $current_total_request;
        $status_str .= "----------------------------------------------PROCESS STATUS---------------------------------------------------\n";
        $status_str .= "Summary\t" . \str_pad($total_memory . 'M', 7) . " "
            . \str_pad('-', $maxLen1) . " "
            . \str_pad('-', $maxLen2) . " "
            . \str_pad($total_connections, 11) . " " . \str_pad($total_fails, 9) . " "
            . \str_pad($total_timers, 7) . " " . \str_pad($total_requests, 13) . " "
            . \str_pad($total_qps, 6) . " [Summary] \n";
        return $status_str;
    }


    /**
     * Install signal handler.
     *
     * @return void
     */
    protected static function installSignal()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        $signalHandler = '\localzet\Core\Server::signalHandler';
        // stop
        \pcntl_signal(\SIGINT, $signalHandler, false);
        // stop
        \pcntl_signal(\SIGTERM, $signalHandler, false);
        // stop
        \pcntl_signal(\SIGHUP, $signalHandler, false);
        // stop
        \pcntl_signal(\SIGTSTP, $signalHandler, false);
        // graceful stop
        \pcntl_signal(\SIGQUIT, $signalHandler, false);
        // reload
        \pcntl_signal(\SIGUSR1, $signalHandler, false);
        // graceful reload
        \pcntl_signal(\SIGUSR2, $signalHandler, false);
        // status
        \pcntl_signal(\SIGIOT, $signalHandler, false);
        // connection status
        \pcntl_signal(\SIGIO, $signalHandler, false);
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
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        $signalHandler = '\localzet\Core\Server::signalHandler';
        // uninstall stop signal handler
        \pcntl_signal(\SIGINT, \SIG_IGN, false);
        // uninstall stop signal handler
        \pcntl_signal(\SIGTERM, \SIG_IGN, false);
        // uninstall stop signal handler
        \pcntl_signal(\SIGHUP, \SIG_IGN, false);
        // uninstall stop signal handler
        \pcntl_signal(\SIGTSTP, \SIG_IGN, false);
        // uninstall graceful stop signal handler
        \pcntl_signal(\SIGQUIT, \SIG_IGN, false);
        // uninstall reload signal handler
        \pcntl_signal(\SIGUSR1, \SIG_IGN, false);
        // uninstall graceful reload signal handler
        \pcntl_signal(\SIGUSR2, \SIG_IGN, false);
        // uninstall status signal handler
        \pcntl_signal(\SIGIOT, \SIG_IGN, false);
        // uninstall connections status signal handler
        \pcntl_signal(\SIGIO, \SIG_IGN, false);
        // reinstall stop signal handler
        static::$globalEvent->add(\SIGINT, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful stop signal handler
        static::$globalEvent->add(\SIGQUIT, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful stop signal handler
        static::$globalEvent->add(\SIGHUP, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful stop signal handler
        static::$globalEvent->add(\SIGTSTP, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall reload signal handler
        static::$globalEvent->add(\SIGUSR1, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall graceful reload signal handler
        static::$globalEvent->add(\SIGUSR2, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall status signal handler
        static::$globalEvent->add(\SIGIOT, EventInterface::EV_SIGNAL, $signalHandler);
        // reinstall connection status signal handler
        static::$globalEvent->add(\SIGIO, EventInterface::EV_SIGNAL, $signalHandler);
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public static function signalHandler($signal)
    {
        switch ($signal) {
                // Stop.
            case \SIGINT:
            case \SIGTERM:
            case \SIGHUP:
            case \SIGTSTP;
                static::$_gracefulStop = false;
                static::stopAll();
                break;
                // Graceful stop.
            case \SIGQUIT:
                static::$_gracefulStop = true;
                static::stopAll();
                break;
                // Reload.
            case \SIGUSR2:
            case \SIGUSR1:
                static::$_gracefulStop = $signal === \SIGUSR2;
                static::$_pidsToRestart = static::getAllServerPids();
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
        if (!static::$daemonize || static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }
        \umask(0);
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('Fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === \posix_setsid()) {
            throw new Exception("Setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception("Fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    /**
     * Redirect standard input and output.
     *
     * @throws Exception
     */
    public static function resetStd()
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
            if (\posix_isatty(2)) {
                \ob_start(function ($string) {
                    \file_put_contents(static::$stdoutFile, $string, FILE_APPEND);
                }, 1);
            }
            // change output stream
            static::$_outputStream = null;
            static::outputStream($STDOUT);
            \restore_error_handler();
            return;
        }

        throw new Exception('Can not open stdoutFile ' . static::$stdoutFile);
    }

    /**
     * Save pid.
     *
     * @throws Exception
     */
    protected static function saveMasterPid()
    {
        if (static::$_OS !== \OS_TYPE_LINUX) {
            return;
        }

        static::$_masterPid = \posix_getpid();
        if (false === \file_put_contents(static::$pidFile, static::$_masterPid)) {
            throw new Exception('can not save pid to ' . static::$pidFile);
        }
    }

    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName()
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        if (!\class_exists('\Swoole\Event', false)) {
            unset(static::$_availableEventLoops['swoole']);
        }

        $loop_name = '';
        foreach (static::$_availableEventLoops as $name => $class) {
            if (\extension_loaded($name)) {
                $loop_name = $name;
                break;
            }
        }

        if ($loop_name) {
            static::$eventLoopClass = static::$_availableEventLoops[$loop_name];
        } else {
            static::$eventLoopClass =  '\localzet\Core\Events\Select';
        }
        return static::$eventLoopClass;
    }

    /**
     * Get all pids of server processes.
     *
     * @return array
     */
    protected static function getAllServerPids()
    {
        $pid_array = array();
        foreach (static::$_pidMap as $server_pid_array) {
            foreach ($server_pid_array as $server_pid) {
                $pid_array[$server_pid] = $server_pid;
            }
        }
        return $pid_array;
    }

    /**
     * Fork some server processes.
     *
     * @return void
     */
    protected static function forkServers()
    {
        if (static::$_OS === \OS_TYPE_LINUX) {
            static::forkServersForLinux();
        } else {
            static::forkServersForWindows();
        }
    }

    /**
     * Fork some server processes.
     *
     * @return void
     */
    protected static function forkServersForLinux()
    {

        foreach (static::$_servers as $server) {
            if (static::$_status === static::STATUS_STARTING) {
                if (empty($server->name)) {
                    $server->name = $server->getSocketName();
                }
                $server_name_length = \strlen($server->name);
                if (static::$_maxServerNameLength < $server_name_length) {
                    static::$_maxServerNameLength = $server_name_length;
                }
            }

            while (\count(static::$_pidMap[$server->serverId]) < $server->count) {
                static::forkOneServerForLinux($server);
            }
        }
    }

    /**
     * Fork some server processes.
     *
     * @return void
     */
    protected static function forkServersForWindows()
    {
        $files = static::getStartFilesForWindows();
        global $argv;
        if (\in_array('-q', $argv) || \count($files) === 1) {
            if (\count(static::$_servers) > 1) {
                static::safeEcho("@@@ Error: multi servers init in one php file are not support @@@\r\n");
            } elseif (\count(static::$_servers) <= 0) {
                exit("@@@no server inited@@@\r\n\r\n");
            }

            \reset(static::$_servers);
            /** @var Server $server */
            $server = current(static::$_servers);

            // Display UI.
            static::safeEcho(\str_pad($server->name, 30) . \str_pad($server->getSocketName(), 36) . \str_pad($server->count, 10) . "[ok]\n");
            $server->listen();
            $server->run();
            exit("@@@child exit@@@\r\n");
        } else {
            static::$globalEvent = new \localzet\Core\Events\Select();
            Timer::init(static::$globalEvent);
            foreach ($files as $start_file) {
                static::forkOneServerForWindows($start_file);
            }
        }
    }

    /**
     * Get start files for windows.
     *
     * @return array
     */
    public static function getStartFilesForWindows()
    {
        global $argv;
        $files = array();
        foreach ($argv as $file) {
            if (\is_file($file)) {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    /**
     * Fork one server process.
     *
     * @param string $start_file
     */
    public static function forkOneServerForWindows($start_file)
    {
        $start_file = \realpath($start_file);

        $descriptorspec = array(
            STDIN, STDOUT, STDOUT
        );

        $pipes       = array();
        $process     = \proc_open("php \"$start_file\" -q", $descriptorspec, $pipes);

        if (empty(static::$globalEvent)) {
            static::$globalEvent = new Select();
            Timer::init(static::$globalEvent);
        }

        // 保存子进程句柄
        static::$_processForWindows[$start_file] = array($process, $start_file);
    }

    /**
     * check server status for windows.
     * @return void
     */
    public static function checkServerStatusForWindows()
    {
        foreach (static::$_processForWindows as $process_data) {
            $process = $process_data[0];
            $start_file = $process_data[1];
            $status = \proc_get_status($process);
            if (isset($status['running'])) {
                if (!$status['running']) {
                    static::safeEcho("process $start_file terminated and try to restart\n");
                    \proc_close($process);
                    static::forkOneServerForWindows($start_file);
                }
            } else {
                static::safeEcho("proc_get_status fail\n");
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
        if ($id === false) {
            return;
        }
        $pid = \pcntl_fork();
        // For master process.
        if ($pid > 0) {
            static::$_pidMap[$server->serverId][$pid] = $pid;
            static::$_idMap[$server->serverId][$id]   = $pid;
        } // For child processes.
        elseif (0 === $pid) {
            \srand();
            \mt_srand();
            if ($server->reusePort) {
                $server->listen();
            }
            if (static::$_status === static::STATUS_STARTING) {
                static::resetStd();
            }
            static::$_pidMap  = array();
            // Remove other listener.
            foreach (static::$_servers as $key => $one_server) {
                if ($one_server->serverId !== $server->serverId) {
                    $one_server->unlisten();
                    unset(static::$_servers[$key]);
                }
            }
            Timer::delAll();
            static::setProcessTitle(self::$processTitle . ': server process  ' . $server->name . ' ' . $server->getSocketName());
            $server->setUserAndGroup();
            $server->id = $id;
            $server->run();
            if (strpos(static::$eventLoopClass, 'localzet\Core\Events\Swoole') !== false) {
                exit(0);
            }
            $err = new Exception('event-loop exited');
            static::log($err);
            exit(250);
        } else {
            throw new Exception("forkOneServer fail");
        }
    }

    /**
     * Get server id.
     *
     * @param string $server_id
     * @param int $pid
     *
     * @return integer
     */
    protected static function getId($server_id, $pid)
    {
        return \array_search($pid, static::$_idMap[$server_id]);
    }

    /**
     * Set unix user and group for current process.
     *
     * @return void
     */
    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = \posix_getpwnam($this->user);
        if (!$user_info) {
            static::log("Warning: User {$this->user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = \posix_getgrnam($this->group);
            if (!$group_info) {
                static::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($user_info['name'], $gid) || !\posix_setuid($uid)) {
                static::log("Warning: change gid or uid fail.");
            }
        }
    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    protected static function setProcessTitle($title)
    {
        \set_error_handler(function () {
        });

        // >=php 5.5
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title($title);
        }

        \restore_error_handler();
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorServers()
    {
        if (static::$_OS === \OS_TYPE_LINUX) {
            static::monitorServersForLinux();
        } else {
            static::monitorServersForWindows();
        }
    }

    /**
     * Monitor all child processes.
     *
     * @return void
     */
    protected static function monitorServersForLinux()
    {
        static::$_status = static::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            \pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            $pid    = \pcntl_wait($status, \WUNTRACED);
            // Calls signal handlers for pending signals again.
            \pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out which server process exited.
                foreach (static::$_pidMap as $server_id => $server_pid_array) {
                    if (isset($server_pid_array[$pid])) {
                        $server = static::$_servers[$server_id];
                        // Exit status.
                        if ($status !== 0) {
                            static::log("server[{$server->name}:$pid] exit with status $status");
                        }

                        // onServerExit
                        if ($server->onServerExit) {
                            try {
                                call_user_func($server->onServerExit, $server, $status, $pid);
                            } catch (\Exception $e) {
                                static::log("server[{$server->name}] onServerExit $e");
                            } catch (\Error $e) {
                                static::log("server[{$server->name}] onServerExit $e");
                            }
                        }

                        // For Statistics.
                        if (!isset(static::$_globalStatistics['server_exit_info'][$server_id][$status])) {
                            static::$_globalStatistics['server_exit_info'][$server_id][$status] = 0;
                        }
                        ++static::$_globalStatistics['server_exit_info'][$server_id][$status];

                        // Clear process data.
                        unset(static::$_pidMap[$server_id][$pid]);

                        // Mark id is available.
                        $id                              = static::getId($server_id, $pid);
                        static::$_idMap[$server_id][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new server process.
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::forkServers();
                    // If reloading continue.
                    if (isset(static::$_pidsToRestart[$pid])) {
                        unset(static::$_pidsToRestart[$pid]);
                        static::reload();
                    }
                }
            }

            // If shutdown state and all child processes exited then master process exit.
            if (static::$_status === static::STATUS_SHUTDOWN && !static::getAllServerPids()) {
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

        static::$globalEvent->loop();
    }

    /**
     * Exit current process.
     *
     * @return void
     */
    protected static function exitAndClearAll()
    {
        foreach (static::$_servers as $server) {
            $socket_name = $server->getSocketName();
            if ($server->transport === 'unix' && $socket_name) {
                list(, $address) = \explode(':', $socket_name, 2);
                $address = substr($address, strpos($address, '/') + 2);
                @\unlink($address);
            }
        }
        @\unlink(static::$pidFile);
        static::log("WebCore [" . \basename(static::$_startFile) . "] has been stopped");
        if (static::$onMasterStop) {
            \call_user_func(static::$onMasterStop);
        }
        exit(0);
    }

    /**
     * Execute reload.
     *
     * @return void
     */
    protected static function reload()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            // Set reloading state.
            if (static::$_status !== static::STATUS_RELOADING && static::$_status !== static::STATUS_SHUTDOWN) {
                static::log("WebCore [" . \basename(static::$_startFile) . "] reloading");
                static::$_status = static::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (static::$onMasterReload) {
                    try {
                        \call_user_func(static::$onMasterReload);
                    } catch (\Exception $e) {
                        static::stopAll(250, $e);
                    } catch (\Error $e) {
                        static::stopAll(250, $e);
                    }
                    static::initId();
                }
            }

            if (static::$_gracefulStop) {
                $sig = \SIGUSR2;
            } else {
                $sig = \SIGUSR1;
            }

            // Send reload signal to all child processes.
            $reloadable_pid_array = array();
            foreach (static::$_pidMap as $server_id => $server_pid_array) {
                $server = static::$_servers[$server_id];
                if ($server->reloadable) {
                    foreach ($server_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    foreach ($server_pid_array as $pid) {
                        // Send reload signal to a server process which reloadable is false.
                        \posix_kill($pid, $sig);
                    }
                }
            }

            // Get all pids that are waiting reload.
            static::$_pidsToRestart = \array_intersect(static::$_pidsToRestart, $reloadable_pid_array);

            // Reload complete.
            if (empty(static::$_pidsToRestart)) {
                if (static::$_status !== static::STATUS_SHUTDOWN) {
                    static::$_status = static::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $one_server_pid = \current(static::$_pidsToRestart);
            // Send reload signal to a server process.
            \posix_kill($one_server_pid, $sig);
            // If the process does not exit after static::$stopTimeout seconds try to kill it.
            if (!static::$_gracefulStop) {
                Timer::add(static::$stopTimeout, '\posix_kill', array($one_server_pid, \SIGKILL), false);
            }
        } // For child processes.
        else {
            \reset(static::$_servers);
            $server = \current(static::$_servers);
            // Try to emit onServerReload callback.
            if ($server->onServerReload) {
                try {
                    \call_user_func($server->onServerReload, $server);
                } catch (\Exception $e) {
                    static::stopAll(250, $e);
                } catch (\Error $e) {
                    static::stopAll(250, $e);
                }
            }

            if ($server->reloadable) {
                static::stopAll();
            }
        }
    }

    /**
     * Stop all.
     *
     * @param int $code
     * @param string $log
     */
    public static function stopAll($code = 0, $log = '')
    {
        if ($log) {
            static::log($log);
        }

        static::$_status = static::STATUS_SHUTDOWN;
        // For master process.
        if (\DIRECTORY_SEPARATOR === '/' && static::$_masterPid === \posix_getpid()) {
            static::log("WebCore [" . \basename(static::$_startFile) . "] stopping ...");
            $server_pid_array = static::getAllServerPids();
            // Send stop signal to all child processes.
            if (static::$_gracefulStop) {
                $sig = \SIGQUIT;
            } else {
                $sig = \SIGINT;
            }
            foreach ($server_pid_array as $server_pid) {
                \posix_kill($server_pid, $sig);
                if (!static::$_gracefulStop) {
                    Timer::add(static::$stopTimeout, '\posix_kill', array($server_pid, \SIGKILL), false);
                }
            }
            Timer::add(1, "\\localzet\\Core\\Server::checkIfChildRunning");
            // Remove statistics file.
            if (\is_file(static::$_statisticsFile)) {
                @\unlink(static::$_statisticsFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (static::$_servers as $server) {
                if (!$server->stopping) {
                    $server->stop();
                    $server->stopping = true;
                }
            }
            if (!static::$_gracefulStop || ConnectionInterface::$statistics['connection_count'] <= 0) {
                static::$_servers = array();
                if (static::$globalEvent) {
                    static::$globalEvent->destroy();
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
        foreach (static::$_pidMap as $server_id => $server_pid_array) {
            foreach ($server_pid_array as $pid => $server_pid) {
                if (!\posix_kill($pid, 0)) {
                    unset(static::$_pidMap[$server_id][$pid]);
                }
            }
        }
    }

    /**
     * Get process status.
     *
     * @return number
     */
    public static function getStatus()
    {
        return static::$_status;
    }

    /**
     * If stop gracefully.
     *
     * @return bool
     */
    public static function getGracefulStop()
    {
        return static::$_gracefulStop;
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            $all_server_info = array();
            foreach (static::$_pidMap as $server_id => $pid_array) {
                /** @var /localzet/Core/Server $server */
                $server = static::$_servers[$server_id];
                foreach ($pid_array as $pid) {
                    $all_server_info[$pid] = array('name' => $server->name, 'listen' => $server->getSocketName());
                }
            }

            \file_put_contents(static::$_statisticsFile, \serialize($all_server_info) . "\n", \FILE_APPEND);
            $loadavg = \function_exists('sys_getloadavg') ? \array_map('round', \sys_getloadavg(), array(2, 2, 2)) : array('-', '-', '-');
            \file_put_contents(
                static::$_statisticsFile,
                "----------------------------------------------GLOBAL STATUS----------------------------------------------------\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$_statisticsFile,
                'WebCore version:' . static::VERSION . "          PHP version:" . \PHP_VERSION . "\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$_statisticsFile,
                'start time:' . \date(
                    'Y-m-d H:i:s',
                    static::$_globalStatistics['start_timestamp']
                ) . '   run ' . \floor((\time() - static::$_globalStatistics['start_timestamp']) / (24 * 60 * 60)) . ' days ' . \floor(((\time() - static::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
                FILE_APPEND
            );
            $load_str = 'load average: ' . \implode(", ", $loadavg);
            \file_put_contents(
                static::$_statisticsFile,
                \str_pad($load_str, 33) . 'event-loop:' . static::getEventLoopName() . "\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$_statisticsFile,
                \count(static::$_pidMap) . ' servers       ' . \count(static::getAllServerPids()) . " processes\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$_statisticsFile,
                \str_pad('server_name', static::$_maxServerNameLength) . " exit_status      exit_count\n",
                \FILE_APPEND
            );
            foreach (static::$_pidMap as $server_id => $server_pid_array) {
                $server = static::$_servers[$server_id];
                if (isset(static::$_globalStatistics['server_exit_info'][$server_id])) {
                    foreach (static::$_globalStatistics['server_exit_info'][$server_id] as $server_exit_status => $server_exit_count) {
                        \file_put_contents(
                            static::$_statisticsFile,
                            \str_pad($server->name, static::$_maxServerNameLength) . " " . \str_pad(
                                $server_exit_status,
                                16
                            ) . " $server_exit_count\n",
                            \FILE_APPEND
                        );
                    }
                } else {
                    \file_put_contents(
                        static::$_statisticsFile,
                        \str_pad($server->name, static::$_maxServerNameLength) . " " . \str_pad(0, 16) . " 0\n",
                        \FILE_APPEND
                    );
                }
            }
            \file_put_contents(
                static::$_statisticsFile,
                "----------------------------------------------PROCESS STATUS---------------------------------------------------\n",
                \FILE_APPEND
            );
            \file_put_contents(
                static::$_statisticsFile,
                "pid\tmemory  " . \str_pad('listening', static::$_maxSocketNameLength) . " " . \str_pad(
                    'server_name',
                    static::$_maxServerNameLength
                ) . " connections " . \str_pad('send_fail', 9) . " "
                    . \str_pad('timers', 8) . \str_pad('total_request', 13) . " qps    status\n",
                \FILE_APPEND
            );

            \chmod(static::$_statisticsFile, 0722);

            foreach (static::getAllServerPids() as $server_pid) {
                \posix_kill($server_pid, \SIGIOT);
            }
            return;
        }

        // For child processes.
        \gc_collect_cycles();
        if (\function_exists('gc_mem_caches')) {
            \gc_mem_caches();
        }
        \reset(static::$_servers);
        /** @var \localzet\Core\Server $server */
        $server            = current(static::$_servers);
        $server_status_str = \posix_getpid() . "\t" . \str_pad(round(memory_get_usage(false) / (1024 * 1024), 2) . "M", 7)
            . " " . \str_pad($server->getSocketName(), static::$_maxSocketNameLength) . " "
            . \str_pad(($server->name === $server->getSocketName() ? 'none' : $server->name), static::$_maxServerNameLength)
            . " ";
        $server_status_str .= \str_pad(ConnectionInterface::$statistics['connection_count'], 11)
            . " " .  \str_pad(ConnectionInterface::$statistics['send_fail'], 9)
            . " " . \str_pad(static::$globalEvent->getTimerCount(), 7)
            . " " . \str_pad(ConnectionInterface::$statistics['total_request'], 13) . "\n";
        \file_put_contents(static::$_statisticsFile, $server_status_str, \FILE_APPEND);
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeConnectionsStatisticsToStatusFile()
    {
        // For master process.
        if (static::$_masterPid === \posix_getpid()) {
            \file_put_contents(static::$_statisticsFile, "--------------------------------------------------------------------- WEBCORE CONNECTION STATUS --------------------------------------------------------------------------------\n", \FILE_APPEND);
            \file_put_contents(static::$_statisticsFile, "PID      Server          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address\n", \FILE_APPEND);
            \chmod(static::$_statisticsFile, 0722);
            foreach (static::getAllServerPids() as $server_pid) {
                \posix_kill($server_pid, \SIGIO);
            }
            return;
        }

        // For child processes.
        $bytes_format = function ($bytes) {
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

        $pid = \posix_getpid();
        $str = '';
        \reset(static::$_servers);
        $current_server = current(static::$_servers);
        $default_server_name = $current_server->name;

        /** @var \localzet\Core\Server $server */
        foreach (TcpConnection::$connections as $connection) {
            /** @var \localzet\Core\Connection\TcpConnection $connection */
            $transport      = $connection->transport;
            $ipv4           = $connection->isIpV4() ? ' 1' : ' 0';
            $ipv6           = $connection->isIpV6() ? ' 1' : ' 0';
            $recv_q         = $bytes_format($connection->getRecvBufferQueueSize());
            $send_q         = $bytes_format($connection->getSendBufferQueueSize());
            $local_address  = \trim($connection->getLocalAddress());
            $remote_address = \trim($connection->getRemoteAddress());
            $state          = $connection->getStatus(false);
            $bytes_read     = $bytes_format($connection->bytesRead);
            $bytes_written  = $bytes_format($connection->bytesWritten);
            $id             = $connection->id;
            $protocol       = $connection->protocol ? $connection->protocol : $connection->transport;
            $pos            = \strrpos($protocol, '\\');
            if ($pos) {
                $protocol = \substr($protocol, $pos + 1);
            }
            if (\strlen($protocol) > 15) {
                $protocol = \substr($protocol, 0, 13) . '..';
            }
            $server_name = isset($connection->server) ? $connection->server->name : $default_server_name;
            if (\strlen($server_name) > 14) {
                $server_name = \substr($server_name, 0, 12) . '..';
            }
            $str .= \str_pad($pid, 9) . \str_pad($server_name, 16) .  \str_pad($id, 10) . \str_pad($transport, 8)
                . \str_pad($protocol, 16) . \str_pad($ipv4, 7) . \str_pad($ipv6, 7) . \str_pad($recv_q, 13)
                . \str_pad($send_q, 13) . \str_pad($bytes_read, 13) . \str_pad($bytes_written, 13) . ' '
                . \str_pad($state, 14) . ' ' . \str_pad($local_address, 22) . ' ' . \str_pad($remote_address, 22) . "\n";
        }
        if ($str) {
            \file_put_contents(static::$_statisticsFile, $str, \FILE_APPEND);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public static function checkErrors()
    {
        if (static::STATUS_SHUTDOWN !== static::$_status) {
            $error_msg = static::$_OS === \OS_TYPE_LINUX ? 'Server[' . \posix_getpid() . '] process terminated' : 'Server process terminated';
            $errors    = error_get_last();
            if (
                $errors && ($errors['type'] === \E_ERROR ||
                    $errors['type'] === \E_PARSE ||
                    $errors['type'] === \E_CORE_ERROR ||
                    $errors['type'] === \E_COMPILE_ERROR ||
                    $errors['type'] === \E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= ' with ERROR: ' . static::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            static::log($error_msg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        if (isset(self::$_errorType[$type])) {
            return self::$_errorType[$type];
        }

        return '';
    }

    /**
     * Log.
     *
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!static::$daemonize) {
            static::safeEcho($msg);
        }
        \file_put_contents((string)static::$logFile, \date('Y-m-d H:i:s') . ' ' . 'pid:'
            . (static::$_OS === \OS_TYPE_LINUX ? \posix_getpid() : 1) . ' ' . $msg, \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Safe Echo.
     * @param string $msg
     * @param bool   $decorated
     * @return bool
     */
    public static function safeEcho($msg, $decorated = false)
    {
        $stream = static::outputStream();
        if (!$stream) {
            return false;
        }
        if (!$decorated) {
            $line = $white = $green = $end = '';
            if (static::$_outputDecorated) {
                $line = "\033[1A\n\033[K";
                $white = "\033[47;30m";
                $green = "\033[32;40m";
                $end = "\033[0m";
            }
            $msg = \str_replace(array('<n>', '<w>', '<g>'), array($line, $white, $green), $msg);
            $msg = \str_replace(array('</n>', '</w>', '</g>'), $end, $msg);
        } elseif (!static::$_outputDecorated) {
            return false;
        }
        \fwrite($stream, $msg);
        \fflush($stream);
        return true;
    }

    /**
     * @param resource|null $stream
     * @return bool|resource
     */
    private static function outputStream($stream = null)
    {
        if (!$stream) {
            $stream = static::$_outputStream ? static::$_outputStream : \STDOUT;
        }
        if (!$stream || !\is_resource($stream) || 'stream' !== \get_resource_type($stream)) {
            return false;
        }
        $stat = \fstat($stream);
        if (!$stat) {
            return false;
        }
        if (($stat['mode'] & 0170000) === 0100000) {
            // file
            static::$_outputDecorated = false;
        } else {
            static::$_outputDecorated =
                static::$_OS === \OS_TYPE_LINUX &&
                \function_exists('posix_isatty') &&
                \posix_isatty($stream);
        }
        return static::$_outputStream = $stream;
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name = '', array $context_option = array())
    {
        // Save all server instances.
        $this->serverId                    = \spl_object_hash($this);
        static::$_servers[$this->serverId] = $this;
        static::$_pidMap[$this->serverId]  = array();

        // Get autoload root path.
        $backtrace               = \debug_backtrace();
        $this->_autoloadRootPath = \dirname($backtrace[0]['file']);
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            if (!isset($context_option['socket']['backlog'])) {
                $context_option['socket']['backlog'] = static::DEFAULT_BACKLOG;
            }
            $this->_context = \stream_context_create($context_option);
        }

        // Turn reusePort on.
        /*if (static::$_OS === \OS_TYPE_LINUX  // if linux
            && \version_compare(\PHP_VERSION,'7.0.0', 'ge') // if php >= 7.0.0
            && \version_compare(php_uname('r'), '3.9', 'ge') // if kernel >=3.9
            && \strtolower(\php_uname('s')) !== 'darwin' // if not Mac OS
            && strpos($socket_name,'unix') !== 0) { // if not unix socket

            $this->reusePort = true;
        }*/
    }


    /**
     * Listen.
     *
     * @throws Exception
     */
    public function listen()
    {
        if (!$this->_socketName) {
            return;
        }

        // Autoload.
        Autoloader::setRootPath($this->_autoloadRootPath);

        if (!$this->_mainSocket) {

            $local_socket = $this->parseSocketAddress();

            // Flag.
            $flags = $this->transport === 'udp' ? \STREAM_SERVER_BIND : \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
            $errno = 0;
            $errmsg = '';
            // SO_REUSEPORT.
            if ($this->reusePort) {
                \stream_context_set_option($this->_context, 'socket', 'so_reuseport', 1);
            }

            // Create an Internet or Unix domain server socket.
            $this->_mainSocket = \stream_socket_server($local_socket, $errno, $errmsg, $flags, $this->_context);
            if (!$this->_mainSocket) {
                throw new Exception($errmsg);
            }

            if ($this->transport === 'ssl') {
                \stream_socket_enable_crypto($this->_mainSocket, false);
            } elseif ($this->transport === 'unix') {
                $socket_file = \substr($local_socket, 7);
                if ($this->user) {
                    \chown($socket_file, $this->user);
                }
                if ($this->group) {
                    \chgrp($socket_file, $this->group);
                }
            }

            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && static::$_builtinTransports[$this->transport] === 'tcp') {
                \set_error_handler(function () {
                });
                $socket = \socket_import_stream($this->_mainSocket);
                \socket_set_option($socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($socket, \SOL_TCP, \TCP_NODELAY, 1);
                \restore_error_handler();
            }

            // Non blocking.
            \stream_set_blocking($this->_mainSocket, false);
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
        if ($this->_mainSocket) {
            \set_error_handler(function () {
            });
            \fclose($this->_mainSocket);
            \restore_error_handler();
            $this->_mainSocket = null;
        }
    }

    /**
     * Parse local socket address.
     *
     * @throws Exception
     */
    protected function parseSocketAddress()
    {
        if (!$this->_socketName) {
            return;
        }
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $this->_socketName, 2);
        // Check application layer protocol class.
        if (!isset(static::$_builtinTransports[$scheme])) {
            $scheme         = \ucfirst($scheme);
            $this->protocol = \substr($scheme, 0, 1) === '\\' ? $scheme : 'Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "localzet\\Core\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }

            if (!isset(static::$_builtinTransports[$this->transport])) {
                throw new Exception('Bad server->transport ' . \var_export($this->transport, true));
            }
        } else {
            $this->transport = $scheme;
        }
        //local socket
        return static::$_builtinTransports[$this->transport] . ":" . $address;
    }

    /**
     * Pause accept new connections.
     *
     * @return void
     */
    public function pauseAccept()
    {
        if (static::$globalEvent && false === $this->_pauseAccept && $this->_mainSocket) {
            static::$globalEvent->del($this->_mainSocket, EventInterface::EV_READ);
            $this->_pauseAccept = true;
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
        if (static::$globalEvent && true === $this->_pauseAccept && $this->_mainSocket) {
            if ($this->transport !== 'udp') {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptConnection'));
            } else {
                static::$globalEvent->add($this->_mainSocket, EventInterface::EV_READ, array($this, 'acceptUdpConnection'));
            }
            $this->_pauseAccept = false;
        }
    }

    /**
     * Get socket name.
     *
     * @return string
     */
    public function getSocketName()
    {
        return $this->_socketName ? \lcfirst($this->_socketName) : 'none';
    }

    /**
     * Run server instance.
     *
     * @return void
     */
    public function run()
    {
        //Update process state.
        static::$_status = static::STATUS_RUNNING;

        // Register shutdown function for checking errors.
        \register_shutdown_function(array("\\localzet\\Core\\Server", 'checkErrors'));

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);

        // Create a global event loop.
        if (!static::$globalEvent) {
            $event_loop_class = static::getEventLoopName();
            static::$globalEvent = new $event_loop_class;
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
                \call_user_func($this->onServerStart, $this);
            } catch (\Exception $e) {
                // Avoid rapid infinite loop exit.
                sleep(1);
                static::stopAll(250, $e);
            } catch (\Error $e) {
                // Avoid rapid infinite loop exit.
                sleep(1);
                static::stopAll(250, $e);
            }
        }

        // Main loop.
        static::$globalEvent->loop();
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
                \call_user_func($this->onServerStop, $this);
            } catch (\Exception $e) {
                static::stopAll(250, $e);
            } catch (\Error $e) {
                static::stopAll(250, $e);
            }
        }
        // Remove listener for server socket.
        $this->unlisten();
        // Close all connections for the server.
        if (!static::$_gracefulStop) {
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
    public function acceptConnection($socket)
    {
        // Accept a connection on server socket.
        \set_error_handler(function () {
        });
        $new_socket = \stream_socket_accept($socket, 0, $remote_address);
        \restore_error_handler();

        // Thundering herd.
        if (!$new_socket) {
            return;
        }

        // TcpConnection.
        $connection                         = new TcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->server                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->transport              = $this->transport;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;

        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                \call_user_func($this->onConnect, $connection);
            } catch (\Exception $e) {
                static::stopAll(250, $e);
            } catch (\Error $e) {
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
    public function acceptUdpConnection($socket)
    {
        \set_error_handler(function () {
        });
        $recv_buffer = \stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        \restore_error_handler();
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }
        // UdpConnection.
        $connection           = new UdpConnection($socket, $remote_address);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            try {
                if ($this->protocol !== null) {
                    /** @var \localzet\Core\Protocols\ProtocolInterface $parser */
                    $parser = $this->protocol;
                    if ($parser && \method_exists($parser, 'input')) {
                        while ($recv_buffer !== '') {
                            $len = $parser::input($recv_buffer, $connection);
                            if ($len === 0)
                                return true;
                            $package = \substr($recv_buffer, 0, $len);
                            $recv_buffer = \substr($recv_buffer, $len);
                            $data = $parser::decode($package, $connection);
                            if ($data === false)
                                continue;
                            \call_user_func($this->onMessage, $connection, $data);
                        }
                    } else {
                        $data = $parser::decode($recv_buffer, $connection);
                        // Discard bad packets.
                        if ($data === false)
                            return true;
                        \call_user_func($this->onMessage, $connection, $data);
                    }
                } else {
                    \call_user_func($this->onMessage, $connection, $recv_buffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (\Exception $e) {
                static::stopAll(250, $e);
            } catch (\Error $e) {
                static::stopAll(250, $e);
            }
        }
        return true;
    }

    /**
     * Check master process is alive
     *
     * @param int $master_pid
     * @return bool
     */
    protected static function checkMasterIsAlive($master_pid)
    {
        if (empty($master_pid)) {
            return false;
        }

        $master_is_alive = $master_pid && \posix_kill((int) $master_pid, 0) && \posix_getpid() !== $master_pid;
        if (!$master_is_alive) {
            return false;
        }

        $cmdline = "/proc/{$master_pid}/cmdline";
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
