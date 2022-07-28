<?php

/**
 * @package     WebCore Server
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Core\Events;

use localzet\Core\Server;

/**
 * Libevent Eventloop
 */
class Event implements EventInterface
{
    /**
     * Event base.
     * @var object
     */
    protected $_eventBase = null;

    /**
     * Обработчики для чтения/записи событий.
     * @var array
     */
    protected $_allEvents = array();

    /**
     * Обработчики сигнала.
     * @var array
     */
    protected $_eventSignal = array();

    /**
     * Все таймеры обработчиков событий.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * id таймера.
     * @var int
     */
    protected static $_timerId = 1;

    /**
     * @return void
     */
    public function __construct()
    {
        // Задел на будущее
        // Если надумаю собирать свою событийную базу
        if (\class_exists('\\\\EventBase', false)) {
            $class_name = '\\\\EventBase';
        } else {
            $class_name = '\EventBase';
        }

        $this->_eventBase = new $class_name();

        // final class EventBase {

        //     /* Константы */
        //     const int LOOP_ONCE = 1;
        //     const int LOOP_NONBLOCK = 2;
        //     const int NOLOCK = 1;
        //     const int STARTUP_IOCP = 4;
        //     const int NO_CACHE_TIME = 8;
        //     const int EPOLL_USE_CHANGELIST = 16;

        //     /* Методы */
        //     public __construct( EventConfig $cfg = ?)
        //     public dispatch(): void
        //     public exit( float $timeout = ?): bool
        //     public free(): void
        //     public getFeatures(): int
        //     public getMethod(): string
        //     public getTimeOfDayCached(): float
        //     public gotExit(): bool
        //     public gotStop(): bool
        //     public loop( int $flags = ?): bool
        //     public priorityInit( int $n_priorities ): bool
        //     public reInit(): bool
        //     public stop(): bool

        // }
    }

    /**
     * Добавление события
     * 
     * @see EventInterface::add()
     */
    public function add($fd, $flag, $func, $args = array())
    {
        // Да, тут по сути в каждом событии будет функция добавления нового события
        // В формате того же класса Event
        if (\class_exists('\\\\Event', false)) {
            $class_name = '\\\\Event';
        } else {
            $class_name = '\Event';
        }

        switch ($flag) {
            case self::EV_SIGNAL:

                $fd_key = (int)$fd;
                $event = $class_name::signal($this->_eventBase, $fd, $func);
                if (!$event || !$event->add()) {
                    return false;
                }
                $this->_eventSignal[$fd_key] = $event;
                return true;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:

                $param = array($func, (array)$args, $flag, $fd, self::$_timerId);
                $event = new $class_name($this->_eventBase, -1, $class_name::TIMEOUT | $class_name::PERSIST, array($this, "timerCallback"), $param);
                if (!$event || !$event->addTimer($fd)) {
                    return false;
                }
                $this->_eventTimer[self::$_timerId] = $event;
                return self::$_timerId++;

            default:
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? $class_name::READ | $class_name::PERSIST : $class_name::WRITE | $class_name::PERSIST;
                $event = new $class_name($this->_eventBase, $fd, $real_flag, $func, $fd);
                if (!$event || !$event->add()) {
                    return false;
                }
                $this->_allEvents[$fd_key][$flag] = $event;
                return true;
        }
    }

    /**
     * Удаление события
     * 
     * @see Events\EventInterface::del()
     */
    public function del($fd, $flag)
    {
        switch ($flag) {

            case self::EV_READ:
            case self::EV_WRITE:

                $fd_key = (int)$fd;
                if (isset($this->_allEvents[$fd_key][$flag])) {
                    $this->_allEvents[$fd_key][$flag]->del();
                    unset($this->_allEvents[$fd_key][$flag]);
                }
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                break;

            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->_eventSignal[$fd_key])) {
                    $this->_eventSignal[$fd_key]->del();
                    unset($this->_eventSignal[$fd_key]);
                }
                break;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if (isset($this->_eventTimer[$fd])) {
                    $this->_eventTimer[$fd]->del();
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }
        return true;
    }

    /**
     * @param int|null $fd
     * @param int $what
     * @param int $timer_id
     */
    public function timerCallback($fd, $what, $param)
    {
        $timer_id = $param[4];

        if ($param[2] === self::EV_TIMER_ONCE) {
            $this->_eventTimer[$timer_id]->del();
            unset($this->_eventTimer[$timer_id]);
        }

        try {
            \call_user_func_array($param[0], $param[1]);
        } catch (\Exception $e) {
            Server::stopAll(250, $e);
        } catch (\Error $e) {
            Server::stopAll(250, $e);
        }
    }

    /**
     * @see Events\EventInterface::clearAllTimer() 
     * @return void
     */
    public function clearAllTimer()
    {
        foreach ($this->_eventTimer as $event) {
            $event->del();
        }
        $this->_eventTimer = array();
    }


    /**
     * @see EventInterface::loop()
     */
    public function loop()
    {
        $this->_eventBase->loop();
    }

    /**
     * Разорвать цикл.
     *
     * @return void
     */
    public function destroy()
    {
        $this->_eventBase->exit();
    }

    /**
     * Кол-во таймеров.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
