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

use function assert;
use function count;

/**
 * Использует бинарное дерево, сохраненное в массиве, для реализации кучи.
 *
 * @internal
 */
final class TimerQueue
{
    /** @var array<int, TimerCallback> */
    private array $callbacks = [];

    /** @var array<string, int> */
    private array $pointers = [];

    /**
     * Вставляет обратный вызов в очередь.
     *
     * Сложность по времени: O(log(n)).
     */
    public function insert(TimerCallback $callback): void
    {
        assert(!isset($this->pointers[$callback->id]));

        $node = count($this->callbacks);
        $this->callbacks[$node] = $callback;
        $this->pointers[$callback->id] = $node;

        $this->heapifyUp($node);
    }

    /**
     * @param int $node Перестраивает массив данных от заданного узла вверх.
     */
    private function heapifyUp(int $node): void
    {
        $entry = $this->callbacks[$node];
        while ($node !== 0 && $entry->expiration < $this->callbacks[$parent = ($node - 1) >> 1]->expiration) {
            $this->swap($node, $parent);
            $node = $parent;
        }
    }

    /**
     * @param int $left
     * @param int $right
     * @return void
     */
    private function swap(int $left, int $right): void
    {
        // Временное хранение обратного вызова слева
        $temp = $this->callbacks[$left];

        // Меняем местами обратные вызовы и указатели
        $this->callbacks[$left] = $this->callbacks[$right];
        $this->pointers[$this->callbacks[$right]->id] = $left;

        $this->callbacks[$right] = $temp;
        $this->pointers[$temp->id] = $right;
    }

    /**
     * Удаляет данный обратный вызов из очереди.
     *
     * Сложность по времени: O(log(n)).
     */
    public function remove(TimerCallback $callback): void
    {
        // Получаем id обратного вызова
        $id = $callback->id;

        // Если обратного вызова нет в очереди, то выходим
        if (!isset($this->pointers[$id])) {
            return;
        }

        // Удаляем и перестраиваем очередь
        $this->removeAndRebuild($this->pointers[$id]);
    }

    /**
     * @param int $node Удаляет заданный узел, а затем перестраивает массив данных.
     */
    private function removeAndRebuild(int $node): void
    {
        $length = count($this->callbacks) - 1;
        $id = $this->callbacks[$node]->id;
        $left = $this->callbacks[$node] = $this->callbacks[$length];
        $this->pointers[$left->id] = $node;
        unset($this->callbacks[$length], $this->pointers[$id]);

        if ($node < $length) { // не нужно делать ничего, если мы удалили последний элемент
            $parent = ($node - 1) >> 1;
            if ($parent >= 0 && $this->callbacks[$node]->expiration < $this->callbacks[$parent]->expiration) {
                // Перестраиваем массив данных от заданного узла вверх.
                $this->heapifyUp($node);
            } else {
                // Перестраиваем массив данных от заданного узла вниз.
                $this->heapifyDown($node);
            }
        }
    }

    /**
     * @param int $node Перестраивает массив данных от заданного узла вниз.
     */
    private function heapifyDown(int $node): void
    {
        $length = count($this->callbacks);
        while (($child = ($node << 1) + 1) < $length) {
            if ($this->callbacks[$child]->expiration < $this->callbacks[$node]->expiration
                && ($child + 1 >= $length || $this->callbacks[$child]->expiration < $this->callbacks[$child + 1]->expiration)
            ) {
                // Левый потомок меньше родителя и правого потомка.
                $swap = $child;
            } elseif ($child + 1 < $length && $this->callbacks[$child + 1]->expiration < $this->callbacks[$node]->expiration) {
                // Правый потомок меньше родителя и левого потомка.
                $swap = $child + 1;
            } else { // Левый и правый потомки больше родителя.
                break;
            }

            // Меняем местами узлы
            $this->swap($node, $swap);
            $node = $swap;
        }
    }


    /**
     * Удаляет и возвращает обратный вызов на вершине кучи, если он истек, иначе возвращает null.
     *
     * Сложность по времени: O(log(n)).
     *
     * @param float $now Текущее время цикла событий.
     *
     * @return TimerCallback|null Истекший обратный вызов на вершине кучи или null, если обратный вызов не истек.
     */
    public function extract(float $now): ?TimerCallback
    {
        if (!$this->callbacks) {
            return null;
        }

        $callback = $this->callbacks[0];
        if ($callback->expiration > $now) {
            return null;
        }

        $this->removeAndRebuild(0);

        return $callback;
    }

    /**
     * Возвращает значение времени истечения на вершине кучи.
     *
     * Сложность по времени: O(1).
     *
     * @return float|null Время истечения обратного вызова на вершине кучи или null, если куча пуста.
     */
    public function peek(): ?float
    {
        return isset($this->callbacks[0]) ? $this->callbacks[0]->expiration : null;
    }
}
