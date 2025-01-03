<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2025 Localzet Group
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
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
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\Server\Events\Linux;

use Closure;
use Error;
use localzet\Server\Events\Linux;
use localzet\Server\Events\Linux\Internal\ClosureHelper;
use Throwable;
use function sprintf;
use function str_replace;

/**
 * Финальный класс для обработки неотловленных исключений.
 */
final class UncaughtThrowable extends Error
{
    /**
     * Конструктор класса.
     *
     * @param string $message Сообщение об ошибке.
     * @param Closure $closure Замыкание, в котором произошло исключение.
     * @param Throwable $throwable Предыдущее исключение.
     */
    private function __construct(string $message, Closure $closure, Throwable $throwable)
    {
        parent::__construct(sprintf(
            $message,
            str_replace("\0", '@', $throwable::class), // заменить NUL-байт в имени анонимного класса
            ClosureHelper::getDescription($closure),
            $throwable->getMessage() !== '' ? ': ' . $throwable->getMessage() : ''
        ), 0, $throwable);
    }

    /**
     * Создать экземпляр класса для обратного вызова, выбрасывающего исключение.
     *
     * @param Closure $closure Замыкание, в котором произошло исключение.
     * @param Throwable $throwable Предыдущее исключение.
     * @return self Экземпляр класса.
     */
    public static function throwingCallback(Closure $closure, Throwable $throwable): self
    {
        return new self(
            'Неотловленное %s выброшено в обратном вызове цикла событий %s; используйте ' . Linux::class . '::setErrorHandler() для корректной обработки таких исключений%s',
            $closure,
            $throwable
        );
    }

    /**
     * Создать экземпляр класса для обработчика ошибок, выбрасывающего исключение.
     *
     * @param Closure $closure Замыкание, в котором произошло исключение.
     * @param Throwable $throwable Предыдущее исключение.
     * @return self Экземпляр класса.
     */
    public static function throwingErrorHandler(Closure $closure, Throwable $throwable): self
    {
        return new self("Неотловленное %s выброшено в обработчике ошибок цикла событий %s%s", $closure, $throwable);
    }
}
