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
                    'label' => __('Too small'),
                    'detail' => __('Likely undersized for the selected workload.'),
                ];

                continue;
            }

            if ($memoryMb > $thresholds['max_memory_mb'] || $vcpus > $thresholds['max_vcpus'] || $diskGb > $thresholds['max_disk_gb']) {
                $recommendations[$value] = [
                    'state' => 'overkill',
                    'label' => __('Overkill'),
                    'detail' => __('More capacity than most teams need for this starting profile.'),
                ];

                continue;
            }

            $recommendations[$value] = [
                'state' => 'good_starting_point',
                'label' => __('Good starting point'),
                'detail' => __('Balanced starting size for the selected install profile.'),
            ];
        }

        return $recommendations;
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
