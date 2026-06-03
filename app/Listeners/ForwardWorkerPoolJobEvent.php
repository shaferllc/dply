<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Http;

/**
 * Box-side agent: forwards each Horizon job lifecycle event to dply's ingest
 * endpoint so the worker-pool dashboard updates per-job in real time. Active
 * ONLY when dply has configured the box (DPLY_POOL_EVENT_URL + _TOKEN env,
 * written by {@see \App\Jobs\PushWorkerPoolHorizonConfigJob}); a no-op everywhere
 * else, so registering it globally is safe.
 *
 * Best-effort and non-fatal: a tiny POST with a short timeout, errors swallowed.
 * It must never slow or fail the job it's reporting on. High-volume queues can
 * disable it by clearing the env vars.
 */
class ForwardWorkerPoolJobEvent
{
    public function handleProcessing(JobProcessing $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'processing', $event->job?->uuid());
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'completed', $event->job?->uuid());
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->forward($event->job?->resolveName(), $event->job?->getQueue(), 'failed', $event->job?->uuid());
    }

    private function forward(?string $name, ?string $queue, string $status, ?string $uuid): void
    {
        $url = (string) env('DPLY_POOL_EVENT_URL', '');
        $token = (string) env('DPLY_POOL_EVENT_TOKEN', '');
        if ($url === '' || $token === '' || $name === null) {
            return;
        }

        try {
            Http::withToken($token)
                ->connectTimeout(1)
                ->timeout(2)
                ->post($url, [
                    'name' => $name,
                    'queue' => $queue ?? 'default',
                    'status' => $status,
                    'uuid' => $uuid,
                    'at' => microtime(true),
                ]);
        } catch (\Throwable) {
            // Never let telemetry break job processing.
        }
    }
}
