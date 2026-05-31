<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;

final class RecommendServerCreateSizes
{
    use AsObject;

    /**
     * @param  list<array<string, mixed>>  $sizes
     * @return array<string, array{state:'too_small'|'good_starting_point'|'overkill'|'unknown',label:string,detail:string}>
     */
    public function handle(string $serverRole, array $sizes): array
    {
        $thresholds = $this->thresholdsForRole($serverRole);
        $roleLabel = $this->roleLabel($serverRole);
        $recommendations = [];

        foreach ($sizes as $size) {
            $value = (string) ($size['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $memoryMb = (int) ($size['memory_mb'] ?? 0);
            $vcpus = (int) ($size['vcpus'] ?? 0);
            $diskGb = (int) ($size['disk_gb'] ?? 0);

            if ($memoryMb === 0 && $vcpus === 0) {
                $recommendations[$value] = [
                    'state' => 'unknown',
                    'label' => __('Needs review'),
                    'detail' => __('Dply does not have enough spec data to size this plan automatically.'),
                ];

                continue;
            }

            if ($memoryMb < $thresholds['min_memory_mb'] || $vcpus < $thresholds['min_vcpus']) {
                $recommendations[$value] = [
                    'state' => 'too_small',
                    'label' => __('Too small for :role', ['role' => $roleLabel]),
                    'detail' => $this->detailForState('too_small', $serverRole, $roleLabel),
                ];

                continue;
            }

            if ($memoryMb > $thresholds['max_memory_mb'] || $vcpus > $thresholds['max_vcpus'] || $diskGb > $thresholds['max_disk_gb']) {
                $recommendations[$value] = [
                    'state' => 'overkill',
                    'label' => __('Overkill for :role', ['role' => $roleLabel]),
                    'detail' => $this->detailForState('overkill', $serverRole, $roleLabel),
                ];

                continue;
            }

            $recommendations[$value] = [
                'state' => 'good_starting_point',
                'label' => __('Good for :role', ['role' => $roleLabel]),
                'detail' => $this->detailForState('good_starting_point', $serverRole, $roleLabel),
            ];
        }

        return $recommendations;
    }

    private function roleLabel(string $serverRole): string
    {
        $role = collect(config('server_provision_options.server_roles', []))
            ->firstWhere('id', $serverRole);

        if (is_array($role) && filled($role['label'] ?? null)) {
            return (string) $role['label'];
        }

        return str($serverRole)->replace('_', ' ')->title()->toString();
    }

    private function detailForState(string $state, string $serverRole, string $roleLabel): string
    {
        if ($state === 'good_starting_point') {
            return match ($serverRole) {
                'redis', 'valkey' => __('Balanced starting size for :role — enough RAM for cache, queues, and session storage.', ['role' => $roleLabel]),
                'database' => __('Balanced starting size for :role — room for the database engine and working set.', ['role' => $roleLabel]),
                'application' => __('Balanced starting size for :role — web, PHP, cache, and database on one host.', ['role' => $roleLabel]),
                'worker' => __('Balanced starting size for :role — background jobs and queue workers.', ['role' => $roleLabel]),
                'load_balancer' => __('Balanced starting size for :role — traffic routing without app runtime overhead.', ['role' => $roleLabel]),
                'plain' => __('Balanced starting size for :role — minimal baseline you can extend yourself.', ['role' => $roleLabel]),
                'docker' => __('Balanced starting size for :role — container workloads with headroom.', ['role' => $roleLabel]),
                default => __('Balanced starting size for :role.', ['role' => $roleLabel]),
            };
        }

        if ($state === 'too_small') {
            return match ($serverRole) {
                'redis', 'valkey' => __('Likely too small for :role — cache and queue workloads need more RAM headroom.', ['role' => $roleLabel]),
                'database' => __('Likely too small for :role — databases need generous RAM and disk for working sets.', ['role' => $roleLabel]),
                'application' => __('Likely too small for :role — plan for web, PHP, cache, and database together.', ['role' => $roleLabel]),
                'worker' => __('Likely too small for :role — queue workers need steady CPU and memory.', ['role' => $roleLabel]),
                default => __('Likely too small for :role.', ['role' => $roleLabel]),
            };
        }

        return match ($serverRole) {
            'redis', 'valkey' => __('More capacity than most dedicated :role hosts need.', ['role' => $roleLabel]),
            'database' => __('More capacity than most single-database :role hosts need to start.', ['role' => $roleLabel]),
            'application' => __('More capacity than most single :role setups need to start.', ['role' => $roleLabel]),
            default => __('More capacity than most teams need for :role.', ['role' => $roleLabel]),
        };
    }

    /**
     * @return array{min_memory_mb:int,min_vcpus:int,max_memory_mb:int,max_vcpus:int,max_disk_gb:int}
     */
    private function thresholdsForRole(string $serverRole): array
    {
        return match ($serverRole) {
            'database' => ['min_memory_mb' => 4096, 'min_vcpus' => 2, 'max_memory_mb' => 16384, 'max_vcpus' => 8, 'max_disk_gb' => 400],
            'worker' => ['min_memory_mb' => 2048, 'min_vcpus' => 2, 'max_memory_mb' => 8192, 'max_vcpus' => 8, 'max_disk_gb' => 200],
            'plain' => ['min_memory_mb' => 1024, 'min_vcpus' => 1, 'max_memory_mb' => 4096, 'max_vcpus' => 4, 'max_disk_gb' => 160],
            'load_balancer' => ['min_memory_mb' => 1024, 'min_vcpus' => 1, 'max_memory_mb' => 4096, 'max_vcpus' => 4, 'max_disk_gb' => 120],
            'redis', 'valkey' => ['min_memory_mb' => 2048, 'min_vcpus' => 1, 'max_memory_mb' => 8192, 'max_vcpus' => 4, 'max_disk_gb' => 160],
            'docker' => ['min_memory_mb' => 2048, 'min_vcpus' => 2, 'max_memory_mb' => 8192, 'max_vcpus' => 8, 'max_disk_gb' => 200],
            default => ['min_memory_mb' => 2048, 'min_vcpus' => 2, 'max_memory_mb' => 8192, 'max_vcpus' => 4, 'max_disk_gb' => 160],
        };
    }
}
