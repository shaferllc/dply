<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Services\Deploy\ByoDeployContext;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Integrations\DeployIntegrationDispatcher;
use App\Support\DeployLogRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class RunSiteDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public Site $site,
        public string $trigger = SiteDeployment::TRIGGER_MANUAL,
        public ?string $apiIdempotencyHash = null,
        public ?string $auditUserId = null,
    ) {}

    public function handle(DeployEngineResolver $deployEngineResolver, DeployIntegrationDispatcher $integrationDispatcher): void
    {
        $this->site = $this->site->fresh();
        if (! $this->site) {
            $this->clearIdempotencyInflight();

            return;
        }

        $this->site->loadMissing('project');
        if ($this->site->project === null) {
            Log::error('RunSiteDeploymentJob: site has no project', ['site_id' => $this->site->id]);
            $this->clearIdempotencyInflight();

            return;
        }

        $lock = Cache::lock('site-deploy:'.$this->site->id, $this->timeout);
        $activeKey = 'site-deploy-active:'.$this->site->id;

        if (! $lock->get()) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'exit_code' => null,
                'log_output' => 'Another deployment is already running for this site.',
                'started_at' => now(),
                'finished_at' => now(),
                'idempotency_key' => $this->apiIdempotencyHash,
            ]);
            $this->auditDeploy($deployment);
            $this->clearIdempotencyInflight();
            $this->notifyStakeholders($deployment, $integrationDispatcher);

            return;
        }

        Cache::put($activeKey, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], $this->timeout + 120);

        try {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_RUNNING,
                'started_at' => now(),
                'idempotency_key' => $this->apiIdempotencyHash,
            ]);
            Cache::put($activeKey, [
                'started_at' => now()->toIso8601String(),
                'deployment_id' => $deployment->id,
            ], $this->timeout + 120);

            try {
                $engine = $deployEngineResolver->forProject($this->site->project);
                $result = $engine->run(new ByoDeployContext(
                    project: $this->site->project,
                    trigger: $this->trigger,
                    apiIdempotencyHash: $this->apiIdempotencyHash,
                    auditUserId: $this->auditUserId,
                ));
                $redacted = DeployLogRedactor::redact($result['output']);
                $deployment->update([
                    'status' => SiteDeployment::STATUS_SUCCESS,
                    'git_sha' => $result['sha'],
                    'exit_code' => 0,
                    'log_output' => $redacted,
                    'finished_at' => now(),
                ]);
                $this->site->update(['last_deploy_at' => now()]);
                $this->cacheIdempotencySuccess($deployment);
                if (config('insights.queue_after_deploy', true)) {
                    RunServerInsightsJob::dispatch($this->site->server_id);
                    RunSiteInsightsJob::dispatch($this->site->id);
                }
            } catch (\Throwable $e) {
                $msg = DeployLogRedactor::redact($e->getMessage());
                $deployment->update([
                    'status' => SiteDeployment::STATUS_FAILED,
                    'exit_code' => 1,
                    'log_output' => $msg,
                    'finished_at' => now(),
                ]);
                $this->cacheIdempotencyFailure($deployment, $msg);
                Log::warning('RunSiteDeploymentJob failed', ['site_id' => $this->site->id, 'error' => $msg]);
                $this->auditDeploy($deployment);
                $this->notifyStakeholders($deployment, $integrationDispatcher);
                throw $e;
            }

            $this->auditDeploy($deployment);
            $this->notifyStakeholders($deployment, $integrationDispatcher);
        } finally {
            Cache::forget($activeKey);
            $lock->release();
            $this->clearIdempotencyInflight();
        }
    }

    protected function clearIdempotencyInflight(): void
    {
        if ($this->apiIdempotencyHash) {
            Cache::forget('api-deploy-inflight:'.$this->apiIdempotencyHash);
        }
    }

    protected function cacheIdempotencySuccess(SiteDeployment $deployment): void
    {
        if (! $this->apiIdempotencyHash) {
            return;
        }
        Cache::put('api-deploy-result:'.$this->apiIdempotencyHash, [
            'message' => 'Deployment completed.',
            'data' => [
                'deployment_id' => $deployment->id,
                'status' => $deployment->status,
                'git_sha' => $deployment->git_sha,
                'finished_at' => $deployment->finished_at?->toIso8601String(),
            ],
        ], now()->addDay());
    }

    protected function cacheIdempotencyFailure(SiteDeployment $deployment, string $message): void
    {
        if (! $this->apiIdempotencyHash) {
            return;
        }
        Cache::put('api-deploy-result:'.$this->apiIdempotencyHash, [
            'message' => 'Deployment failed.',
            'error' => $message,
            'data' => [
                'deployment_id' => $deployment->id,
                'status' => $deployment->status,
                'finished_at' => $deployment->finished_at?->toIso8601String(),
            ],
        ], now()->addDay());
    }

    protected function auditDeploy(SiteDeployment $deployment): void
    {
        $this->site->loadMissing('organization');
        $org = $this->site->organization;
        if (! $org) {
            return;
        }
        $user = $this->auditUserId ? User::query()->find($this->auditUserId) : null;
        $action = match ($deployment->status) {
            SiteDeployment::STATUS_SUCCESS => 'site.deploy.success',
            SiteDeployment::STATUS_FAILED => 'site.deploy.failed',
            SiteDeployment::STATUS_SKIPPED => 'site.deploy.skipped',
            default => 'site.deploy.finished',
        };
        audit_log($org, $user, $action, $deployment, null, [
            'site' => $this->site->name,
            'trigger' => $this->trigger,
            'status' => $deployment->status,
        ]);
    }

    protected function notifyStakeholders(SiteDeployment $deployment, DeployIntegrationDispatcher $integrationDispatcher): void
    {
        if (! config('dply.deploy_notifications', true)) {
            return;
        }

        if (! in_array($deployment->status, [
            SiteDeployment::STATUS_SUCCESS,
            SiteDeployment::STATUS_FAILED,
            SiteDeployment::STATUS_SKIPPED,
        ], true)) {
            return;
        }

        $site = $this->site->fresh(['server', 'organization']);
        if (! $site) {
            return;
        }

        $org = $site->organization;
        $userIds = collect([$site->user_id])->filter();
        if ($org) {
            $userIds = $userIds->merge(
                $org->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')
            );
        }

        $users = User::query()->whereIn('id', $userIds->unique()->all())->get();
        $sendDeployEmail = true;
        if ($org && ! $org->wantsDeployEmailNotifications()) {
            $sendDeployEmail = false;
        }
        if ($sendDeployEmail && $users->isNotEmpty()) {
            Notification::send($users, new SiteDeploymentCompletedNotification($deployment->fresh()));
        }

        if ($org) {
            $integrationDispatcher->dispatch($org, $site, $deployment->fresh());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->clearIdempotencyInflight();
    }
}
