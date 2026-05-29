<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use Illuminate\Console\Command;

/**
 * Scale a Cloud worker — change its instance count and/or compute tier.
 *
 *   dply:cloud:worker:scale <worker> [--count=3] [--size=medium]
 *
 * Persists the change and queues SyncCloudWorkersJob, which re-pushes
 * the worker-inclusive app spec to the backend and rolls a deploy.
 *
 * A scheduler is always pinned to a single instance — --count is
 * ignored for scheduler-type workers.
 */
class CloudWorkerScaleCommand extends Command
{
    private const TIERS = ['small', 'medium', 'large', 'xlarge'];

    protected $signature = 'dply:cloud:worker:scale
        {worker : CloudWorker ID}
        {--count= : New instance count}
        {--size= : New compute tier — small, medium, large, or xlarge}';

    protected $description = 'Scale a Cloud worker (instance count / compute tier).';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('worker'));
        $worker = CloudWorker::query()->find($needle);
        if ($worker === null) {
            $this->error("Worker not found: {$needle}");

            return self::FAILURE;
        }

        $countOption = $this->option('count');
        $sizeOption = $this->option('size');
        $countOption = $countOption === null ? '' : (string) $countOption;
        $sizeOption = $sizeOption === null ? '' : (string) $sizeOption;
        if ($countOption === '' && $sizeOption === '') {
            $this->error('Pass --count and/or --size to scale the worker.');

            return self::FAILURE;
        }

        $changes = [];

        if ($sizeOption !== '') {
            $size = strtolower($sizeOption);
            if (! in_array($size, self::TIERS, true)) {
                $this->error('Unknown --size. Valid: '.implode(', ', self::TIERS));

                return self::FAILURE;
            }
            $changes['size'] = $size;
        }

        if ($countOption !== '') {
            $count = max(1, (int) $countOption);
            if ($worker->isScheduler() && $count !== 1) {
                $this->warn('The scheduler always runs a single instance — --count ignored.');
            } else {
                $targetSize = (string) ($changes['size'] ?? $worker->size);
                $max = CloudWorker::maxInstanceCountForSize($targetSize);
                if ($count > $max) {
                    $this->error(sprintf(
                        'The %s worker tier allows at most %d instance(s) on DigitalOcean App Platform.',
                        $targetSize,
                        $max,
                    ));

                    return self::FAILURE;
                }
                $changes['instance_count'] = $count;
            }
        }

        if ($changes === []) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        if (! $worker->isScheduler()) {
            $targetSize = (string) ($changes['size'] ?? $worker->size);
            $targetCount = (int) ($changes['instance_count'] ?? $worker->instance_count);
            $normalized = CloudWorker::normalizeInstanceCount($targetSize, $targetCount);
            if ($normalized !== $targetCount) {
                $changes['instance_count'] = $normalized;
                $this->warn(sprintf(
                    'Instance count adjusted to %d for the %s tier.',
                    $normalized,
                    $targetSize,
                ));
            }
        }

        $worker->update($changes);
        SyncCloudWorkersJob::dispatch($worker->site_id);

        $this->info(sprintf(
            'Worker "%s" scaled — ×%d (%s). Sync queued.',
            $worker->name,
            $worker->effectiveInstanceCount(),
            $worker->size,
        ));

        return self::SUCCESS;
    }
}
