<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\SchedulerHealthEvaluator;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsScheduleStats
{


    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array{total: int, healthy: int, attention: int, paused: int}
     */
    private function scheduleStatsFromCards(array $cards): array
    {
        $healthy = 0;
        $attention = 0;
        $paused = 0;
        $total = 0;

        foreach ($cards as $card) {
            $state = (string) ($card['state'] ?? '');
            if ($state === 'no_scheduler') {
                $attention++;

                continue;
            }

            $total++;

            if ($state === 'paused') {
                $paused++;

                continue;
            }

            $health = $card['health'] ?? null;
            if ($health === SchedulerHealthEvaluator::STATE_HEALTHY) {
                $healthy++;
            } elseif (in_array($health, [
                SchedulerHealthEvaluator::STATE_WAITING,
                SchedulerHealthEvaluator::STATE_AMBER,
                SchedulerHealthEvaluator::STATE_RED,
            ], true) || $state === 'detected_unmonitored') {
                $attention++;
            }
        }

        return compact('total', 'healthy', 'attention', 'paused');
    }

    /**
     * @param  array{healthy: int, waiting: int, amber: int, red: int, paused: int, unmonitored: int, tracked_total: int, no_scheduler_sites: int}  $stats
     * @return array{total: int, healthy: int, attention: int, paused: int}
     */
    private function scheduleStatsFromSummary(array $stats): array
    {
        return [
            'total' => $stats['tracked_total'] + $stats['paused'] + $stats['unmonitored'],
            'healthy' => $stats['healthy'],
            'attention' => $stats['waiting'] + $stats['amber'] + $stats['red'] + $stats['unmonitored'] + $stats['no_scheduler_sites'],
            'paused' => $stats['paused'],
        ];
    }
}
