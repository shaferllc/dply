<?php

namespace App\Jobs;

use App\Enums\DeploymentMethod;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\User;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\DeployResumePlan;
use App\Services\Deploy\EphemeralDeployCredentialManager;
use App\Services\Notifications\DeployDigestBuffer;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Notifications\ServerDeployPolicyNotificationDispatcher;
use App\Services\Servers\ServerDeployPolicyGuard;
use App\Services\Sites\Backends\CanarySiteDeployer;
use App\Services\Sites\Backends\RollingSiteDeployer;
use App\Services\Sites\RequiredEnvEvaluator;
use App\Support\DeployLogRedactor;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
        // When set, resume the given prior failed deployment from the phase it
        // failed at, re-using its staged release, instead of a fresh full
        // deploy. Honoured only when that deployment is genuinely resumable.
        public ?string $resumeFromDeploymentId = null,
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
                $this->notifyDeployWindowBlocked($policyDecision);

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
            // Resume: when this run continues a prior failed deploy, build the
            // plan and carry that deploy's already-run phases onto the new row
            // so the timeline reads as the full pipeline (not just the tail).
            // Invalid/stale ids silently fall back to a normal full deploy.
            [$resumePlan, $resumeSeed] = $this->resolveResumePlan();

            $deployment = SiteDeployment::query()->create(array_merge([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_RUNNING,
                'started_at' => now(),
                'idempotency_key' => $this->apiIdempotencyHash,
            ], $resumeSeed));
            Cache::put($activeKey, [
                'started_at' => now()->toIso8601String(),
                'deployment_id' => $deployment->id,
            ], $this->timeout + 120);

            $this->notifyDeploymentStarted($deployment, $notificationPublisher);

            $ephemeralCredential = null;
            $ephemeralLog = [];
            if ($ephemeralCredentials->shouldUseForSite($this->site)) {
                $ephemeralCredential = $ephemeralCredentials->provision($this->site, $deployment);
                $ephemeralCredentials->activateForDeploy($ephemeralCredential);

                // Surface the per-deploy key issuance in the deploy output (not
                // just the audit log) so operators can see the feature working.
                $fingerprint = 'SHA256:'.(string) $ephemeralCredential->public_key_fingerprint;
                $ephemeralLog[] = '── Ephemeral deploy credentials ──';
                $ephemeralLog[] = 'Issued a one-time ed25519 deploy key ('.$fingerprint.').';
                $ephemeralLog[] = 'Installed on '.($this->site->server?->name ?? 'the server')
                    .' via the operational SSH key; this deploy authenticates with it.';
            }

            try {
                // Fail fast (with a clear, actionable message) when the live
                // .env is missing variables the code can't run without, rather
                // than letting the build/activate succeed and the app 500 at
                // runtime. The Deploy panel turns this failure into a fill-in
                // prompt.
                $this->assertRequiredEnvPresent($this->site);

                // Rolling/canary cutover on a multi-backend site fans the deploy
                // out across backends (rolling = drain→deploy→re-add per box;
                // canary = ramp a weighted slice then promote) instead of the
                // single-server engine run. Every other method/site is unaffected.
                $cutover = DeploymentMethod::forSite($this->site)->cutover();
                if ($this->site->isMultiBackend() && in_array($cutover, ['rolling', 'canary'], true)) {
                    $result = $cutover === 'canary'
                        ? app(CanarySiteDeployer::class)->deploy($this->site, $deployment)
                        : app(RollingSiteDeployer::class)->deploy($this->site, $deployment);
                } else {
                    $engine = $deployEngineResolver->forProject($this->site->project);
                    $result = $engine->run(new DeployContext(
                        project: $this->site->project,
                        trigger: $this->trigger,
                        apiIdempotencyHash: $this->apiIdempotencyHash,
                        auditUserId: $this->auditUserId,
                        deployment: $deployment,
                        resume: $resumePlan,
                    ));
                }
                $redacted = $this->withEphemeralLog($ephemeralLog, DeployLogRedactor::redact($result['output']));
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
                // Post-deploy verification — ALWAYS runs after a VM deploy: a
                // deploy can report success while the live app 500s (missing
                // build, migrations, …). Run the health check + smart-fix
                // detection so a degraded deploy surfaces its cause and
                // one-click fixes automatically.
                // Best-effort: the deploy has already succeeded and the status
                // is persisted, so kicking off verification must never be able
                // to flip it back to FAILED. Swallow + log any error here.
                if ($this->site->server?->isVmHost()) {
                    try {
                        $verifyRun = ConsoleAction::query()->create([
                            'subject_type' => $this->site->getMorphClass(),
                            'subject_id' => $this->site->id,
                            'kind' => 'site_test',
                            'status' => ConsoleAction::STATUS_QUEUED,
                            'label' => 'Verifying deploy',
                            // System-run verification has no authenticated user.
                            // user_id is a nullable ULID FK — integer 0 is never
                            // a valid id and trips a foreign-key violation.
                            'user_id' => null,
                            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
                        ]);
                        TestSiteHealthJob::dispatch((string) $verifyRun->id, (string) $this->site->id);
                    } catch (\Throwable $e) {
                        Log::warning('Post-deploy verification could not be started', [
                            'site_id' => $this->site->id,
                            'deployment_id' => $deployment->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $msg = $this->withEphemeralLog($ephemeralLog, DeployLogRedactor::redact($e->getMessage()));
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

                    // Close out the key lifecycle in the deploy output (the log
                    // was already saved above; append so issue → use → revoke is
                    // all visible). The fingerprint is public — safe to show.
                    $deployment->update([
                        'log_output' => trim((string) $deployment->log_output
                            ."\nRevoked the ephemeral deploy key (SHA256:"
                            .(string) $ephemeralCredential->public_key_fingerprint
                            .'); removed from authorized_keys.'),
                    ]);
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
     * Prepend the ephemeral-credential issuance lines to a deploy log so the
     * per-deploy key is visible in the deployment output. No-op when ephemeral
     * credentials weren't used for this deploy.
     *
     * @param  list<string>  $lines
     */
    private function withEphemeralLog(array $lines, string $log): string
    {
        if ($lines === []) {
            return $log;
        }

        return implode("\n", $lines)."\n\n".$log;
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
        // Evaluate (and record on meta.deploy_blocked_env) via the shared gate
        // so the Deploy panel banner and the on-demand re-check stay identical.
        // null = gate doesn't apply; [] = satisfied; non-empty = block.
        $missing = app(RequiredEnvEvaluator::class)->evaluateAndRecord($site);

        if (empty($missing)) {
            return;
        }

        $names = array_map(static fn (array $entry): string => $entry['key'], $missing);
        $shown = implode(', ', array_slice($names, 0, 12));
        $more = count($names) > 12 ? ' (+'.(count($names) - 12).' more)' : '';

        throw new \RuntimeException(
            'Deployment blocked: the app requires environment variables that are not set: '
            .$shown.$more.'. Add them on the Deploy panel (or Settings → Environment) and redeploy.'
        );
    }

    /**
     * Resolve the resume plan + the seed attributes for the new deployment row.
     * Returns [null, []] for a normal deploy or when the referenced deployment
     * isn't (or is no longer) resumable — so a stale "Retry from phase" click
     * safely degrades to a full deploy rather than erroring.
     *
     * @return array{0: ?DeployResumePlan, 1: array<string, mixed>}
     */
    protected function resolveResumePlan(): array
    {
        if ($this->resumeFromDeploymentId === null) {
            return [null, []];
        }

        $origin = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->find($this->resumeFromDeploymentId);

        $startPhase = $origin?->resumeStartPhase();
        if ($origin === null || $startPhase === null) {
            return [null, []];
        }

        // Carry forward every phase that ran before the resume point so the new
        // deployment's timeline shows the whole pipeline. The atomic deployer
        // skips those phases on disk; these records keep them visible.
        $carried = [];
        $originPhases = is_array($origin->phase_results) ? $origin->phase_results : [];
        foreach (DeployResumePlan::PHASE_ORDER as $phase) {
            if ($phase === $startPhase) {
                break;
            }
            if (isset($originPhases[$phase])) {
                $carried[$phase] = $originPhases[$phase];
            }
        }

        return [
            new DeployResumePlan((string) $origin->release_folder, $startPhase),
            [
                'resume_of_deployment_id' => $origin->id,
                'release_folder' => $origin->release_folder,
                'git_sha' => $origin->git_sha,
                'phase_results' => $carried !== [] ? $carried : null,
            ],
        ];
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

    /**
     * Route a server-scoped "deploy blocked by deny window" event to any channels
     * subscribed on the deploy-window workspace. Best-effort: never let a
     * notification failure derail the (already-skipped) deploy.
     *
     * @param  array{allowed: bool, reason: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}  $policyDecision
     */
    protected function notifyDeployWindowBlocked(array $policyDecision): void
    {
        $server = $this->site->server;
        if ($server === null) {
            return;
        }

        try {
            $details = [__('Site: :name', ['name' => $this->site->name])];
            if (! empty($policyDecision['reason'])) {
                $details[] = (string) $policyDecision['reason'];
            }
            $nextAllowedAt = $policyDecision['next_allowed_at'] ?? null;
            if ($nextAllowedAt !== null) {
                $tz = (string) ($policyDecision['policy']['timezone'] ?? config('app.timezone'));
                $details[] = __('Allowed again: :time', ['time' => $nextAllowedAt->timezone($tz)->format('D H:i T')]);
            }

            app(ServerDeployPolicyNotificationDispatcher::class)->notify(
                $server,
                'deploy_blocked',
                $details,
                $this->auditUserId ? User::query()->find($this->auditUserId) : null,
                [
                    'site_id' => (string) $this->site->id,
                    'site_name' => $this->site->name,
                    'trigger' => $this->trigger,
                    'next_allowed_at' => $nextAllowedAt?->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('RunSiteDeploymentJob: deploy-window block notification failed', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->clearIdempotencyInflight();

        // Hard failures (queue timeout, OOM, fatal) bypass handle()'s catch,
        // leaving the deployment stuck "running" and the deploy button spinning
        // until the optimistic lock's 600s TTL. Release the UI lock and mark the
        // in-flight deployment failed so the failure surfaces on the next poll.
        Cache::forget('site-deploy-active:'.$this->site->id);

        $deployment = $this->site->deployments()
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->latest()
            ->first();

        if ($deployment !== null) {
            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'exit_code' => $deployment->exit_code ?? 1,
                'log_output' => trim(($deployment->log_output ? $deployment->log_output."\n\n" : '')
                    .'Deployment failed: '.($exception?->getMessage() ?? 'job terminated')),
                'finished_at' => now(),
            ]);
        }
    }
}
