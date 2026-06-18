<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

use App\Modules\Cloud\Jobs\SyncCloudWorkersJob;
use App\Models\CloudWorker;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates a CloudWorker row — a queue worker or the scheduler — for a
 * Cloud container site, then dispatches SyncCloudWorkersJob to push
 * the new background component into the backend's app spec.
 *
 * Guards:
 *  - the site must be a Cloud container site;
 *  - the site's backend must support workers (DigitalOcean App
 *    Platform does; AWS App Runner does not);
 *  - a site may have at most one scheduler — the Laravel scheduler
 *    loop must run on exactly one process or scheduled tasks fire
 *    multiple times.
 */
class CreateCloudWorker
{
    private const TYPES = [
        CloudWorker::TYPE_WORKER,
        CloudWorker::TYPE_SCHEDULER,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Site $site, array $payload): CloudWorker
    {
        if ($site->container_backend === '') {
            throw new InvalidArgumentException('Workers can only be added to Cloud container sites.');
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null) {
            throw new RuntimeException('No cloud backend is resolvable for this site.');
        }
        if (! $backend->supportsWorkers()) {
            throw new InvalidArgumentException(
                'AWS App Runner does not support background workers — it is HTTP-request-driven only. '
                .'Use a DigitalOcean App Platform site for queue workers and the scheduler.',
            );
        }

        $type = strtolower(trim((string) ($payload['type'] ?? CloudWorker::TYPE_WORKER)));
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                'Unknown worker type. Use one of: '.implode(', ', self::TYPES),
            );
        }

        if ($type === CloudWorker::TYPE_SCHEDULER) {
            $existing = CloudWorker::query()
                ->where('site_id', $site->id)
                ->where('type', CloudWorker::TYPE_SCHEDULER)
                ->exists();
            if ($existing) {
                throw new InvalidArgumentException('This site already has a scheduler.');
            }
        }

        $size = strtolower(trim((string) ($payload['size'] ?? 'small')));
        if (! array_key_exists($size, CloudWorker::SIZE_TIERS)) {
            $size = 'small';
        }

        $isScheduler = $type === CloudWorker::TYPE_SCHEDULER;

        $command = trim((string) ($payload['command'] ?? ''));
        if ($isScheduler) {
            // The scheduler always runs the Laravel scheduler loop.
            $command = CloudWorker::SCHEDULER_COMMAND;
        } elseif ($command === '') {
            $command = CloudWorker::DEFAULT_WORKER_COMMAND;
        }

        $count = (int) ($payload['instance_count'] ?? 1);
        if ($isScheduler || $count < 1) {
            $count = 1;
        } elseif ($count > CloudWorker::maxInstanceCountForSize($size)) {
            throw new InvalidArgumentException(sprintf(
                'The %s worker tier allows at most %d instance(s) on DigitalOcean App Platform. Choose medium or larger for more instances.',
                $size,
                CloudWorker::maxInstanceCountForSize($size),
            ));
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = $isScheduler ? 'scheduler' : 'worker';
        }

        $worker = CloudWorker::query()->create([
            'site_id' => $site->id,
            'type' => $type,
            'name' => $name,
            'command' => $command,
            'size' => $size,
            'instance_count' => $count,
            'status' => CloudWorker::STATUS_PROVISIONING,
        ]);

        SyncCloudWorkersJob::dispatch($site->id);

        return $worker;
    }
}
