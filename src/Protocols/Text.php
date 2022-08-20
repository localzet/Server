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

namespace localzet\Core\Protocols;

use localzet\Core\Connection\ConnectionInterface;

/**
 * Текстовый протокол.
 */
class Text
{
    /**
     * Проверим целостность пакета.
     *
     * @param string        $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // Превышает ли длина пакета предел?
        if (isset($connection->maxPackageSize) && \strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  Найти положение  "\n".
        $pos = \strpos($buffer, "\n");

        // Нет "\n", длина пакета неизвестна, продолжаем ждать данных, поэтому вернём 0.
        if ($pos === false) {
            return 0;
        }

        // Вернём текущую длину пакета.
        return $pos + 1;
    }

    /**
     * Шифруем данные
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        // Добавим "\n"
        return $buffer . "\n";
    }

    /**
     * Дешифруем данные
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        // Удалим "\n"
        return \rtrim($buffer, "\r\n");
    }
}