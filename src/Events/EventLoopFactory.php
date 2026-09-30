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

namespace localzet\Server\Events;

use RuntimeException;

/**
 * Фабрика event-loop backend'ов Localzet Server.
 *
 * Задача класса — отделить выбор runtime от Server supervisor. Явно запрошенный
 * backend никогда не подменяется другим молча: если пользователь попросил
 * `event`, `ev`, `swoole`, `swow` или `revolt`, но соответствующей возможности
 * нет, запуск завершается понятной ошибкой. Автоматический режим, напротив,
 * выбирает безопасный доступный backend и всегда имеет Select fallback.
 */
final class EventLoopFactory
{
    /**
     * Создаёт event loop по alias, имени класса или автоматически.
     *
     * Поддерживаемые aliases: auto, linux, select, windows, revolt, fiber,
     * event, ev, swoole, swow.
     *
     * @param string|null $preferred Явно запрошенный backend или FQCN.
     * @return EventInterface Готовая петля событий.
     * @throws RuntimeException Если явно запрошенный backend недоступен.
     */
    public static function create(?string $preferred = null): EventInterface
    {
        $preferred = trim((string)$preferred);
        if ($preferred === '' || strtolower($preferred) === 'auto') {
            return self::createAutomatic();
        }

        if (class_exists($preferred) && is_a($preferred, EventInterface::class, true)) {
            /** @var class-string<EventInterface> $preferred */
            return new $preferred();
        }

        $alias = strtolower($preferred);
        return match ($alias) {
            'select' => new Select(),
            'windows' => new Windows(),
            'fiber', 'revolt' => self::requireBackend('revolt', Fiber::class),
            'event' => self::requireBackend('event', Event::class),
            'ev' => self::requireBackend('ev', Ev::class),
            'swoole' => self::requireBackend('swoole', Swoole::class),
            'swow' => self::requireBackend('swow', Swow::class),
            // `linux` — compatibility alias старого Localzet API. Он означает
            // "лучший безопасный backend для Unix", а не конкретную extension.
            'linux' => self::createUnixAutomatic(),
            default => throw new RuntimeException("Unknown Localzet event loop '{$preferred}'."),
        };
    }

    /**
     * Возвращает runtime capabilities без создания event loop.
     *
     * @return array<string,bool>
     */
    public static function capabilities(): array
    {
        return [
            'select' => true,
            'revolt' => class_exists(\Revolt\EventLoop::class),
            'event' => extension_loaded('event') && class_exists(\EventBase::class),
            'ev' => extension_loaded('ev') && class_exists(\Ev::class),
            'swoole' => extension_loaded('swoole') && class_exists(\Swoole\Event::class),
            'swow' => extension_loaded('swow') && class_exists(\Swow\Coroutine::class),
        ];
    }

    /**
     * Проверяет доступность backend'а по alias.
     */
    public static function isAvailable(string $backend): bool
    {
        $backend = strtolower(trim($backend));
        if ($backend === 'fiber') {
            $backend = 'revolt';
        }
        return self::capabilities()[$backend] ?? false;
    }

    /**
     * Автовыбор не должен неожиданно включать coroutine hooks глобально.
     * Поэтому Swoole/OpenSwoole/Swow используются только при явном выборе.
     */
    private static function createAutomatic(): EventInterface
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return new Windows();
        }
        return self::createUnixAutomatic();
    }

    /**
     * Выбирает Unix backend без глобального изменения PHP I/O semantics.
     */
    private static function createUnixAutomatic(): EventInterface
    {
        if (self::isAvailable('event')) {
            return new Event();
        }
        if (self::isAvailable('ev')) {
            return new Ev();
        }
        if (self::isAvailable('revolt')) {
            return new Fiber();
        }
        return new Select();
    }

    /**
     * Создаёт явно выбранный backend либо сообщает, почему он недоступен.
     *
     * @template T of EventInterface
     * @param string $alias Имя backend'а для сообщения пользователю.
     * @param class-string<T> $class Реализация Localzet EventInterface.
     * @return T
     */
    private static function requireBackend(string $alias, string $class): EventInterface
    {
        if (!self::isAvailable($alias)) {
            throw new RuntimeException("Localzet event loop '{$alias}' is not available in this PHP runtime.");
        }
        return new $class();
    }
}
