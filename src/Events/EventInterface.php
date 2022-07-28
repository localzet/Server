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

interface EventInterface
{
    /**
     * Чтение.
     *
     * @var int
     */
    const EV_READ = 1;

    /**
     * Запись.
     *
     * @var int
     */
    const EV_WRITE = 2;

    /**
     * Исключение
     *
     * @var int
     */
    const EV_EXCEPT = 3;

    /**
     * Сигнал.
     *
     * @var int
     */
    const EV_SIGNAL = 4;

    /**
     * Таймер.
     *
     * @var int
     */
    const EV_TIMER = 8;

    /**
     * Одиночный таймер.
     *
     * @var int
     */
    const EV_TIMER_ONCE = 16;

    /**
     * Добавляем обработчик событий в цикл.
     *
     * @param mixed    $fd
     * @param int      $flag
     * @param callable $func
     * @param array    $args
     * @return bool
     */
    public function add($fd, $flag, $func, $args = array());

    /**
     * Удалляем обработчик событий из цикла.
     *
     * @param mixed $fd
     * @param int   $flag
     * @return bool
     */
    public function del($fd, $flag);

    /**
     * Удаляем все таймеры.
     *
     * @return void
     */
    public function clearAllTimer();

    /**
     * Главный цикл.
     *
     * @return void
     */
    public function loop();

    /**
     * Разорвать цикл.
     *
     * @return mixed
     */
    public function destroy();

    /**
     * Кол-во таймеров.
     *
     * @return mixed
     */
    public function getTimerCount();
}
