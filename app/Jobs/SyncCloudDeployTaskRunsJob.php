<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudDeployTask;
use App\Models\CloudDeployTaskRun;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Walk a Cloud site's most-recent deployment on DO and persist a
 * CloudDeployTaskRun row for each PRE_DEPLOY / POST_DEPLOY /
 * FAILED_DEPLOY job that ran. Each row carries the deploy ID,
 * trigger snapshot, status, started/finished timestamps, and the
 * exit code DO reported.
 *
 * MANUAL job runs are populated separately when the operator
 * triggers them via Run-now (followup) — DO doesn't include manual
 * runs in the deployment's jobs[] payload.
 *
 * Idempotent: re-running the sync against the same deployment is a
 * no-op because we key task_runs by (cloud_deploy_task_id, deployment_id).
 */
class SyncCloudDeployTaskRunsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || $site->container_backend !== 'digitalocean_app_platform') {
            return;
        }
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $credential = CloudRouter::credentialFor($site);
        if ($credential === null) {
            return;
        }

        try {
            $service = new DigitalOceanAppPlatformService($credential);
            $deployments = $service->listDeployments($site->container_backend_id, 1);
        } catch (\Throwable $e) {
            // Transient network/rate-limit — let the next poll retry.
            return;
        }

        $deployment = $deployments[0] ?? null;
        if (! is_array($deployment) || ! is_string($deployment['id'] ?? null)) {
            return;
        }
        $deploymentId = (string) $deployment['id'];

        try {
            $detail = $service->getDeployment($site->container_backend_id, $deploymentId);
        } catch (\Throwable $e) {
            return;
        }

        $jobs = is_array($detail['jobs'] ?? null) ? $detail['jobs'] : [];
        if ($jobs === []) {
            return;
        }

        // Build a name → CloudDeployTask map. The DO component name is
        // `job-{slugified name}` per jobComponentName(); we strip the
        // prefix to match against the stored task name's slug form.
        $tasks = CloudDeployTask::query()->where('site_id', $site->id)->get();
        $tasksByDoName = [];
        foreach ($tasks as $task) {
            $slug = strtolower((string) preg_replace('/[^a-z0-9-]/i', '-', (string) $task->name));
            $slug = trim($slug, '-');
            $doName = $slug !== '' ? 'job-'.$slug : 'job';
            $tasksByDoName[substr($doName, 0, 28)] = $task;
        }

        foreach ($jobs as $job) {
            $doName = (string) ($job['name'] ?? '');
            $task = $tasksByDoName[$doName] ?? null;
            if ($task === null) {
                continue;
            }

            // Idempotency — skip if we've already recorded this run.
            $exists = CloudDeployTaskRun::query()
                ->where('cloud_deploy_task_id', $task->id)
                ->where('deployment_id', $deploymentId)
                ->exists();
            if ($exists) {
                continue;
            }

            $startedAt = $this->parseTimestamp($job['started_at'] ?? $detail['started_at'] ?? null);
            $finishedAt = $this->parseTimestamp($job['phase_last_updated_at'] ?? $detail['phase_last_updated_at'] ?? null);
            $status = $this->mapJobStatus((string) ($job['status'] ?? ''));
            $exitCode = is_numeric($job['exit_code'] ?? null) ? (int) $job['exit_code'] : null;
            $duration = ($startedAt && $finishedAt) ? max(0, $finishedAt->diffInMilliseconds($startedAt)) : null;

            CloudDeployTaskRun::query()->create([
                'cloud_deploy_task_id' => $task->id,
                'deployment_id' => $deploymentId,
                'trigger' => $task->trigger,
                'status' => $status,
                'exit_code' => $exitCode,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
                'log_tail' => null,
                'error' => null,
                'meta' => [
                    'do_phase' => $job['status'] ?? null,
                    'do_component_name' => $doName,
                ],
            ]);
        }
    }

    /**
     * Translate DO's per-job status string into one of our run states.
     */
    private function mapJobStatus(string $do): string
    {
        return match (strtoupper($do)) {
            'SUCCESS' => CloudDeployTaskRun::STATUS_SUCCEEDED,
            'ERROR', 'FAILED' => CloudDeployTaskRun::STATUS_FAILED,
            'CANCELED' => CloudDeployTaskRun::STATUS_CANCELED,
            default => CloudDeployTaskRun::STATUS_RUNNING,
        };
    }

    private function parseTimestamp(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
