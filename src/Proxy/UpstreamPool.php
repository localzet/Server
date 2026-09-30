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

namespace localzet\Server\Proxy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Небольшой runtime-aware пул upstream-ов.
 *
 * Здесь намеренно нет HTTP-специфики. Один и тот же pool используется L4
 * TCP proxy и L7 HTTP gateway. Состояние health/failures поэтому общее.
 */
final class UpstreamPool
{
    public const ROUND_ROBIN = 'round_robin';
    public const LEAST_CONNECTIONS = 'least_connections';
    public const RANDOM = 'random';

    /** @var list<Upstream> */
    private array $upstreams = [];

    private int $weightedCursor = 0;
    private string $strategy;
    private int $failureThreshold;
    private float $quarantineSeconds;

    /** @param iterable<Upstream|string> $upstreams */
    public function __construct(
        iterable $upstreams = [],
        string   $strategy = self::ROUND_ROBIN,
        int      $failureThreshold = 2,
        float    $quarantineSeconds = 5.0,
    )
    {
        $this->setStrategy($strategy);
        $this->failureThreshold = max(1, $failureThreshold);
        $this->quarantineSeconds = max(0.0, $quarantineSeconds);

        foreach ($upstreams as $upstream) {
            $this->add($upstream);
        }
    }

    public function add(Upstream|string $upstream, int $weight = 1, string $name = ''): static
    {
        $this->upstreams[] = $upstream instanceof Upstream
            ? $upstream
            : new Upstream($upstream, $weight, $name);
        return $this;
    }

    public function setStrategy(string $strategy): static
    {
        if (!in_array($strategy, [self::ROUND_ROBIN, self::LEAST_CONNECTIONS, self::RANDOM], true)) {
            throw new InvalidArgumentException("Unknown upstream strategy: {$strategy}");
        }
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * Выбирает backend, исключая уже попробованные узлы текущей операции.
     *
     * Если все узлы находятся в quarantine, разрешается один probe на backend
     * с самым ранним deadline — иначе pool мог бы полностью зависнуть до timer.
     *
     * @param array<int, true> $excluded Object IDs уже попробованных upstream-ов.
     */
    public function select(array $excluded = []): Upstream
    {
        $available = array_values(array_filter(
            $this->upstreams,
            static fn(Upstream $upstream): bool => !isset($excluded[spl_object_id($upstream)]) && $upstream->isAvailable()
        ));

        if ($available === []) {
            $candidates = array_values(array_filter(
                $this->upstreams,
                static fn(Upstream $upstream): bool => !isset($excluded[spl_object_id($upstream)])
            ));
            if ($candidates === []) {
                throw new RuntimeException('No upstream is available.');
            }
            usort($candidates, static fn(Upstream $a, Upstream $b): int => $a->quarantineUntil <=> $b->quarantineUntil);
            return $candidates[0];
        }

        return match ($this->strategy) {
            self::LEAST_CONNECTIONS => $this->selectLeastConnections($available),
            self::RANDOM => $available[array_rand($available)],
            default => $this->selectWeightedRoundRobin($available),
        };
    }

    public function markSuccess(Upstream $upstream): void
    {
        $upstream->markSuccess();
    }

    public function markFailure(Upstream $upstream): void
    {
        $upstream->markFailure($this->failureThreshold, $this->quarantineSeconds);
    }

    /** @return list<Upstream> */
    public function all(): array
    {
        return $this->upstreams;
    }

    /** @return list<array<string, int|float|string|bool>> */
    public function status(): array
    {
        return array_map(static fn(Upstream $upstream): array => $upstream->toArray(), $this->upstreams);
    }

    /** @param list<Upstream> $available */
    private function selectLeastConnections(array $available): Upstream
    {
        usort($available, static function (Upstream $a, Upstream $b): int {
            // Вес используется как capacity hint: 10 active при weight=10
            // лучше, чем 2 active при weight=1.
            $left = $a->activeConnections / $a->weight;
            $right = $b->activeConnections / $b->weight;
            return $left <=> $right;
        });
        return $available[0];
    }

    /** @param list<Upstream> $available */
    private function selectWeightedRoundRobin(array $available): Upstream
    {
        $wheel = [];
        foreach ($available as $upstream) {
            for ($i = 0; $i < $upstream->weight; $i++) {
                $wheel[] = $upstream;
            }
        }

        if ($wheel === []) {
            throw new RuntimeException('Upstream pool is empty.');
        }
        $selected = $wheel[$this->weightedCursor % count($wheel)];
        $this->weightedCursor = ($this->weightedCursor + 1) % max(1, count($wheel));
        return $selected;
    }
}
