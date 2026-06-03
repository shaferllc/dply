<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\User;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\EphemeralDeployCredentialManager;
use App\Services\Notifications\DeployDigestBuffer;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Servers\ServerDeployPolicyGuard;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvReader;
use App\Support\DeployLogRedactor;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

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

    public function handle(
        DeployEngineResolver $deployEngineResolver,
        NotificationPublisher $notificationPublisher,
        EphemeralDeployCredentialManager $ephemeralCredentials,
    ): void {
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

        $this->site->loadMissing('server.organization');
        $organization = $this->site->server?->organization;

        if (ProductLineKillSwitches::blocksVmSiteDeploy($this->site)) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'exit_code' => null,
                'log_output' => 'VM deploys are temporarily disabled by platform administrators.',
                'started_at' => now(),
                'finished_at' => now(),
                'idempotency_key' => $this->apiIdempotencyHash,
            ]);
            $this->auditDeploy($deployment);
            $this->clearIdempotencyInflight();

            return;
        }

        if ($organization !== null && ! $organization->canDeploy()) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'exit_code' => null,
                'log_output' => 'Deploys are paused while this organization\'s trial is expired. Add a payment method on the billing page to resume.',
                'started_at' => now(),
                'finished_at' => now(),
                'idempotency_key' => $this->apiIdempotencyHash,
            ]);
            $this->auditDeploy($deployment);
            $this->clearIdempotencyInflight();
            $this->notifyStakeholders($deployment, $notificationPublisher);

            return;
        }

        if (Feature::active('workspace.deploy_windows')) {
            $policyDecision = app(ServerDeployPolicyGuard::class)->evaluate($this->site);
            if (! $policyDecision['allowed']) {
                $deployment = SiteDeployment::query()->create([
                    'site_id' => $this->site->id,
                    'project_id' => $this->site->project_id,
                    'trigger' => $this->trigger,
                    'status' => SiteDeployment::STATUS_SKIPPED,
                    'exit_code' => null,
                    'log_output' => (string) ($policyDecision['reason'] ?? 'Deploy blocked by server deploy window policy.'),
                    'started_at' => now(),
                    'finished_at' => now(),
                    'idempotency_key' => $this->apiIdempotencyHash,
                ]);
                $this->auditDeploy($deployment);
                $this->clearIdempotencyInflight();
                $this->notifyStakeholders($deployment, $notificationPublisher);

                return;
            }
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
            $this->notifyStakeholders($deployment, $notificationPublisher);

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

            $this->notifyDeploymentStarted($deployment, $notificationPublisher);

            $ephemeralCredential = null;
            if ($ephemeralCredentials->shouldUseForSite($this->site)) {
                $ephemeralCredential = $ephemeralCredentials->provision($this->site, $deployment);
                $ephemeralCredentials->activateForDeploy($ephemeralCredential);
            }

            try {
                // Fail fast (with a clear, actionable message) when the live
                // .env is missing variables the code can't run without, rather
                // than letting the build/activate succeed and the app 500 at
                // runtime. The Deploy panel turns this failure into a fill-in
                // prompt.
                $this->assertRequiredEnvPresent($this->site);

                $engine = $deployEngineResolver->forProject($this->site->project);
                $result = $engine->run(new DeployContext(
                    project: $this->site->project,
                    trigger: $this->trigger,
                    apiIdempotencyHash: $this->apiIdempotencyHash,
                    auditUserId: $this->auditUserId,
                    deployment: $deployment,
                ));
                $redacted = DeployLogRedactor::redact($result['output']);
                $deployment->update([
                    'status' => SiteDeployment::STATUS_SUCCESS,
                    'git_sha' => $result['sha'],
                    'exit_code' => 0,
                    'log_output' => $redacted,
                    'finished_at' => now(),
                ]);
                $siteUpdates = [
                    'last_deploy_at' => now(),
                ];
                if ($this->site->server?->hostCapabilities()->supportsFunctionDeploy()) {
                    $siteUpdates['status'] = Site::activeStatusForWebserver($this->site->webserver());
                }
                if ($this->site->server?->hostCapabilities()->supportsClusterDeploy()) {
                    $siteUpdates['status'] = Site::activeStatusForWebserver($this->site->webserver());
                }
                $this->site->update($siteUpdates);
                $this->cacheIdempotencySuccess($deployment);
                if (config('insights.queue_after_deploy', true) && $this->site->server?->isVmHost()) {
                    RunServerInsightsJob::dispatch($this->site->server_id);
                    RunSiteInsightsJob::dispatch($this->site->id);
                }
                // Refresh the detected env-var requirements from the freshly
                // deployed code so the Environment tab can flag missing keys.
                if ($this->site->server?->hostCapabilities()->supportsEnvPushToHost()) {
                    ScanSiteEnvRequirementsJob::dispatch($this->site->id);
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
                $this->auditDeploy($deployment, $ephemeralCredential);
                $this->notifyStakeholders($deployment, $notificationPublisher);
                throw $e;
            } finally {
                if ($ephemeralCredential instanceof SiteDeploymentEphemeralCredential) {
                    $ephemeralCredentials->revoke($ephemeralCredential);
                }
            }

            $this->auditDeploy($deployment, $ephemeralCredential);
            $this->notifyStakeholders($deployment, $notificationPublisher);
        } finally {
            Cache::forget($activeKey);
            $lock->release();
            $this->clearIdempotencyInflight();
        }
    }

    /**
     * Block the deploy when the live .env is missing variables the code can't
     * run without (env('KEY') with no default — source 'code'). Reads the real
     * server .env (truth, not the UI cache) and diffs it against the last
     * scan's detected requirements. Records the offenders on the site so the
     * Deploy panel can prompt the operator to fill them, then throws a clear
     * message that surfaces as the deployment's failure reason.
     *
     * Conservative on purpose: hosts with no server .env, sites never scanned
     * (e.g. their first deploy), and unreadable .env files all pass through —
     * we only block when we're confident a required value is genuinely absent.
     */
    private function assertRequiredEnvPresent(Site $site): void
    {
        $server = $site->server;
        if ($server === null || ! $server->hostCapabilities()->supportsEnvPushToHost()) {
            return;
        }

        if (($site->envRequirements()['keys'] ?? []) === []) {
            return;
        }

        try {
            $envRaw = app(SiteEnvReader::class)->read($site);
        } catch (\Throwable) {
            return;
        }

        $parsed = app(DotEnvFileParser::class)->parse($envRaw);
        $present = [];
        foreach ($parsed['variables'] as $key => $value) {
            if (trim((string) $value) !== '') {
                $present[] = (string) $key;
            }
        }
        $inherited = $site->workspace?->variables->pluck('env_key')->map(fn ($k) => (string) $k)->all() ?? [];

        // Strict gate: only no-default env() references (source 'code'). Keys
        // that only appear in .env.example or carry a config default are
        // advisory and never block a deploy.
        $missing = array_values(array_filter(
            $site->missingRequiredEnvKeys($present, $inherited),
            static fn (array $entry): bool => in_array('code', $entry['sources'], true),
        ));

        $meta = is_array($site->meta) ? $site->meta : [];

        if ($missing === []) {
            if (array_key_exists('deploy_blocked_env', $meta)) {
                unset($meta['deploy_blocked_env']);
                $site->forceFill(['meta' => $meta])->save();
            }

            return;
        }

        $meta['deploy_blocked_env'] = [
            'at' => now()->toIso8601String(),
            'keys' => array_map(
                static fn (array $entry): array => ['key' => $entry['key'], 'example' => $entry['example']],
                $missing,
            ),
        ];
        $site->forceFill(['meta' => $meta])->save();

        $names = array_map(static fn (array $entry): string => $entry['key'], $missing);
        $shown = implode(', ', array_slice($names, 0, 12));
        $more = count($names) > 12 ? ' (+'.(count($names) - 12).' more)' : '';

        throw new \RuntimeException(
            'Deployment blocked: the app requires environment variables that are not set: '
            .$shown.$more.'. Add them on the Deploy panel (or Settings → Environment) and redeploy.'
        );
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

    protected function auditDeploy(SiteDeployment $deployment, ?SiteDeploymentEphemeralCredential $ephemeralCredential = null): void
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

        $startedAt = $deployment->started_at;
        $finishedAt = $deployment->finished_at;
        $durationMs = $startedAt && $finishedAt
            ? (int) round(($finishedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000)
            : null;

        $errorExcerpt = null;
        if ($deployment->status === SiteDeployment::STATUS_FAILED && $deployment->log_output) {
            $errorExcerpt = mb_strimwidth((string) $deployment->log_output, 0, 1000, '…');
        }

        audit_log($org, $user, $action, $deployment, null, [
            'site' => $this->site->name,
            'site_id' => (string) $this->site->id,
            'deployment_id' => (string) $deployment->id,
            'trigger' => $this->trigger,
            'status' => $deployment->status,
            'exit_code' => $deployment->exit_code,
            'git_sha' => $deployment->git_sha,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'error_excerpt' => $errorExcerpt,
            'ephemeral_credential_fingerprint' => $ephemeralCredential?->public_key_fingerprint,
        ]);
    }

    protected function notifyDeploymentStarted(SiteDeployment $deployment, NotificationPublisher $notificationPublisher): void
    {
        if (! config('dply.deploy_notifications', true)) {
            return;
        }

        $site = $this->site->fresh(['server', 'organization']);
        if (! $site || ! $site->server) {
            return;
        }

        $notificationPublisher->publish(
            eventKey: 'site.deployment_started',
            subject: $deployment->fresh(),
            title: '['.config('app.name').'] '.$site->name.' deploy started',
            body: 'Trigger: '.$deployment->trigger,
            url: route('sites.show', [$site->server, $site], absolute: true),
            metadata: [
                'deployment_id' => $deployment->id,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'trigger' => $deployment->trigger,
                'status' => SiteDeployment::STATUS_RUNNING,
            ],
        );
    }

    protected function notifyStakeholders(SiteDeployment $deployment, NotificationPublisher $notificationPublisher): void
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
        $event = $notificationPublisher->publish(
            eventKey: 'site.deployments',
            subject: $deployment->fresh(),
            title: '['.config('app.name').'] '.$site->name.' deploy '.strtoupper($deployment->status),
            body: 'Trigger: '.$deployment->trigger.($deployment->git_sha ? "\nGit SHA: ".$deployment->git_sha : ''),
            url: route('sites.show', [$site->server, $site], absolute: true),
            metadata: [
                'deployment_id' => $deployment->id,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'status' => $deployment->status,
                'trigger' => $deployment->trigger,
                'git_sha' => $deployment->git_sha,
                'log_excerpt' => $deployment->log_output
                    ? Str::limit(DeployLogRedactor::redact($deployment->log_output), 1200)
                    : null,
            ],
        );

        $sendDeployEmail = ! $org || $org->wantsDeployEmailNotifications();
        if (! $sendDeployEmail || $users->isEmpty()) {
            return;
        }

        if ($org && (int) config('dply.deploy_digest_hours', 0) > 0) {
            DeployDigestBuffer::record((string) $org->id, sprintf(
                '%s — %s — %s',
                $site->name,
                strtoupper($deployment->status),
                $deployment->trigger
            ));

            return;
        }

        Notification::send($users, new SiteDeploymentCompletedNotification($event));
    }

    public function failed(?\Throwable $exception): void
    {
        $this->clearIdempotencyInflight();
    }
}
