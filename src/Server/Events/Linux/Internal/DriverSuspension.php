<?php declare(strict_types=1);

/**
 * @package     Localzet Server
 * @link        https://github.com/localzet/Server
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2023 Localzet Group
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

namespace localzet\Server\Events\Linux\Internal;

use Closure;
use Error;
use Fiber;
use FiberError;
use localzet\Server\Events\Linux\Suspension;
use ReflectionFiber;
use Throwable;
use WeakMap;
use WeakReference;
use function array_keys;
use function array_map;
use function assert;
use function gc_collect_cycles;
use function implode;
use const DEBUG_BACKTRACE_IGNORE_ARGS;


/**
 * @internal
 *
 * @template T
 * @implements Suspension<T>
 */
final class DriverSuspension implements Suspension
{
    /**
     * @var Fiber|null
     */
    private ?Fiber $suspendedFiber = null;

    /** @var WeakReference<Fiber>|null */
    private readonly ?WeakReference $fiberRef;

    /**
     * @var FiberError|null
     */
    private ?FiberError $fiberError = null;

    /**
     * @var bool
     */
    private bool $pending = false;

    /**
     * @param Closure $run
     * @param Closure $queue
     * @param Closure $interrupt
     * @param WeakMap $suspensions
     */
    public function __construct(
        private readonly Closure $run,
        private readonly Closure $queue,
        private readonly Closure $interrupt,
        private readonly WeakMap $suspensions,
    )
    {
        $fiber = Fiber::getCurrent();

        $this->fiberRef = $fiber ? WeakReference::create($fiber) : null;
    }

    /**
     * @param mixed|null $value
     * @return void
     */
    public function resume(mixed $value = null): void
    {
        if (!$this->pending) {
            throw $this->fiberError ?? new Error('Необходимо вызвать suspend() перед вызовом resume()');
        }

        $this->pending = false;

        /** @var Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            ($this->queue)($fiber->resume(...), $value);
        } else {
            // Приостановить выполнение основного цикла событий.
            ($this->interrupt)(static fn() => $value);
        }
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function suspend(): mixed
    {
        if ($this->pending) {
            throw new Error('Необходимо вызвать resume() или throw() перед повторным вызовом suspend()');
        }

        $fiber = $this->fiberRef?->get();

        if ($fiber !== Fiber::getCurrent()) {
            throw new Error('Нельзя вызывать suspend() из другого волокна');
        }

        $this->pending = true;

        // Ожидание внутри волокна.
        if ($fiber) {
            $this->suspendedFiber = $fiber;

            try {
                return Fiber::suspend();
            } catch (FiberError $exception) {
                $this->pending = false;
                $this->fiberError = $exception;

                throw $exception;
            } finally {
                $this->suspendedFiber = null;
            }
        }

        // Ожидание в {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition Поле pending должно измениться при возобновлении. */
        if ($this->pending) {
            $this->pending = false;
            $result && $result(); // Распаковка любых необработанных исключений из цикла событий
            gc_collect_cycles(); // Сбор циклических ссылок перед выводом ожидающих приостановок.
            $info = '';

            foreach ($this->suspensions as $suspensionRef) {
                if ($suspension = $suspensionRef->get()) {
                    assert($suspension instanceof self);
                    $fiber = $suspension->fiberRef?->get();
                    if ($fiber === null) {
                        continue;
                    }
                    $reflectionFiber = new ReflectionFiber($fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }
            throw new Error('Цикл событий завершился без возобновления текущей приостановки (причиной может быть либо тупик волокон, либо неправильно отмененный/нессылочный наблюдатель)');
        }

        return $result();
    }

    /**
     * @param array $trace
     * @return string
     */
    private function formatStacktrace(array $trace): string
    {
        // Форматирование стека вызовов.
        return implode("\n", array_map(static function ($e, $i) {
            $line = "#$i ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, array_keys($trace)));
    }

    /**
     * @param Throwable $throwable
     * @return void
     */
    public function throw(Throwable $throwable): void
    {
        // Бросить исключение.
        if (!$this->pending) {
            throw $this->fiberError ?? new Error('Необходимо вызвать suspend() перед вызовом throw()');
        }

        $this->pending = false;

        /** @var Fiber|null $fiber */
        $fiber = $this->fiberRef?->get();

        if ($fiber) {
            // Передать исключение в очередь.
            ($this->queue)($fiber->throw(...), $throwable);
        } else {
            // Приостановить выполнение основного цикла событий.
            ($this->interrupt)(static fn() => throw $throwable);
        }
    }
}
