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

namespace localzet\Core\Protocols;

use localzet\Core\Connection\ConnectionInterface;
use function rtrim;
use function strlen;
use function strpos;

/**
 * Текстовый протокол.
 */
class Text
{
    /**
     * Проверим целостность пакета.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input(string $buffer, ConnectionInterface $connection): int
    {
        // Превышает ли длина пакета предел?
        if (isset($connection->maxPackageSize) && strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  Найти положение  "\n".
        $pos = strpos($buffer, "\n");

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
    public static function encode(string $buffer): string
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
    public static function decode(string $buffer): string
    {
        // Удалим "\n"
        return rtrim($buffer, "\r\n");
    }
}
