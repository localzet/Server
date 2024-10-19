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
    private ?Fiber $suspendedFiber = null;

    /** @var WeakReference<Fiber>|null */
    private readonly ?WeakReference $weakReference;

    private ?Error $error = null;

    private bool $pending = false;

    private bool $deadMain = false;

    public function __construct(
        private readonly Closure $run,
        private readonly Closure $queue,
        private readonly Closure $interrupt,
        private readonly WeakMap $weakMap,
    )
    {
        $fiber = Fiber::getCurrent();

        $this->weakReference = $fiber ? WeakReference::create($fiber) : null;
    }

    /**
     * @param mixed|null $value
     */
    public function resume(mixed $value = null): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        if (!$this->pending) {
            throw $this->error ?? new Error('Необходимо вызвать suspend() перед вызовом resume()');
        }

        $this->pending = false;

        /** @var Fiber|null $fiber */
        $fiber = $this->weakReference?->get();

        if ($fiber) {
            ($this->queue)(static function () use ($fiber, $value): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->resume($value);
                }
            });
        } else {
            // Приостановить выполнение основного цикла событий.
            ($this->interrupt)(static fn(): mixed => $value);
        }
    }

    /**
     * @throws Throwable
     */
    public function suspend(): mixed
    {
        // Throw exception when trying to use old dead {main} suspension
        if ($this->deadMain) {
            throw new Error(
                'Suspension cannot be suspended after an uncaught exception is thrown from the event loop',
            );
        }

        if ($this->pending) {
            throw new Error('Необходимо вызвать resume() или throw() перед повторным вызовом suspend()');
        }

        $fiber = $this->weakReference?->get();

        if ($fiber !== Fiber::getCurrent()) {
            throw new Error('Нельзя вызывать suspend() из другого Fiber\'а');
        }

        $this->pending = true;
        $this->error = null;

        // Ожидание внутри Fiber'а.
        if ($fiber instanceof Fiber) {
            $this->suspendedFiber = $fiber;

            try {
                $value = Fiber::suspend();
                $this->suspendedFiber = null;
            } catch (FiberError $error) {
                $this->pending = false;
                $this->suspendedFiber = null;
                $this->error = $error;

                throw $error;
            }

            // Setting $this->suspendedFiber = null in finally will set the fiber to null if a fiber is destroyed
            // as part of a cycle collection, causing an error if the suspension is subsequently resumed.

            return $value;
        }

        // Ожидание в {main}.
        $result = ($this->run)();

        /** @psalm-suppress RedundantCondition Поле pending должно измениться при возобновлении. */
        if ($this->pending) {
            // This is now a dead {main} suspension.
            $this->deadMain = true;

            $result && $result(); // Распаковка любых необработанных исключений из цикла событий
            gc_collect_cycles(); // Сбор циклических ссылок перед выводом ожидающих приостановок.
            $info = '';

            foreach ($this->weakMap as $suspensionRef) {
                if ($suspension = $suspensionRef->get()) {
                    assert($suspension instanceof self);
                    $fiber = $suspension->weakReference?->get();
                    if ($fiber === null) {
                        continue;
                    }

                    $reflectionFiber = new ReflectionFiber($fiber);
                    $info .= "\n\n" . $this->formatStacktrace($reflectionFiber->getTrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                }
            }

            throw new Error('Цикл событий завершился без возобновления текущей приостановки (причиной может быть либо тупик Fiber\'а, либо неправильно отмененный/нессылочный наблюдатель)');
        }

        return $result();
    }

    private function formatStacktrace(array $trace): string
    {
        // Форматирование стека вызовов.
        return implode("\n", array_map(static function ($e, $i): string {
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

    public function throw(Throwable $throwable): void
    {
        // Ignore spurious resumes to old dead {main} suspension
        if ($this->deadMain) {
            return;
        }

        // Бросить исключение.
        if (!$this->pending) {
            throw $this->error ?? new Error('Необходимо вызвать suspend() перед вызовом throw()');
        }

        $this->pending = false;

        /** @var Fiber|null $fiber */
        $fiber = $this->weakReference?->get();

        if ($fiber) {
            // Передать исключение в очередь.
            ($this->queue)(static function () use ($fiber, $throwable): void {
                // The fiber may be destroyed with suspension as part of the GC cycle collector.
                if (!$fiber->isTerminated()) {
                    $fiber->throw($throwable);
                }
            });
        } else {
            // Приостановить выполнение основного цикла событий.
            ($this->interrupt)(static fn() => throw $throwable);
        }
    }
}
