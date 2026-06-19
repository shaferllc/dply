<?php

namespace App\Modules\Deploy\Jobs;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\ScanSiteEnvRequirementsJob;
use App\Jobs\TestSiteHealthJob;

use App\Enums\DeploymentMethod;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\User;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Modules\Deploy\Services\DeployContext;
use App\Modules\Deploy\Services\DeployEngineResolver;
use App\Modules\Deploy\Services\DeployResumePlan;
use App\Modules\Deploy\Services\EphemeralDeployCredentialManager;
use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Modules\Insights\Jobs\RunSiteInsightsJob;
use App\Modules\Notifications\Services\DeployDigestBuffer;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Notifications\Services\ServerDeployPolicyNotificationDispatcher;
use App\Modules\Secrets\Services\EphemeralSecretIdentityContext;
use App\Services\Servers\ServerDeployPolicyGuard;
use App\Services\Sites\AtomicDeployHealthChecker;
use App\Services\Sites\Backends\CanarySiteDeployer;
use App\Services\Sites\Backends\RollingSiteDeployer;
use App\Services\Sites\RequiredEnvEvaluator;
use App\Services\SshConnection;
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
        // Cache token (NOT the raw key) under which a customer-held org age
        // identity was stashed for this deploy, so the env push can decrypt
        // customer-held escrowed secrets. Pulled-and-forgotten in handle().
        public ?string $ephemeralIdentityToken = null,
    ) {}

    public function handle(
        DeployEngineResolver $deployEngineResolver,
        NotificationPublisher $notificationPublisher,
        EphemeralDeployCredentialManager $ephemeralCredentials,
    ): void {
        $freshSite = $this->site->fresh();
        if ($freshSite === null) {
            $this->clearIdempotencyInflight();

            return;
        }
        $this->site = $freshSite;

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
                'skip_reason' => SiteDeployment::SKIP_REASON_PLATFORM_DISABLED,
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
            // Interactive clicks are pre-gated in the UI with a billing prompt,
            // so don't persist a phantom "skipped" row that reads as a stuck
            // deploy. Machine triggers (webhook/API/schedule/sync) still leave an
            // audited, clearly-labelled record so there's a trail of "a deploy
            // was requested while paused".
            if ($this->isInteractiveTrigger()) {
                Cache::forget('site-deploy-active:'.$this->site->id);
                $this->clearIdempotencyInflight();

                return;
            }

            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'skip_reason' => SiteDeployment::SKIP_REASON_BILLING_PAUSED,
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

        // Deploy windows are GA — always evaluate. The guard returns allowed=true
        // when the server has no policy or enforcement is disabled, so this is a
        // no-op for servers that never configured deny windows.
        $policyDecision = app(ServerDeployPolicyGuard::class)->evaluate($this->site);
        if (! $policyDecision['allowed']) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'skip_reason' => SiteDeployment::SKIP_REASON_DEPLOY_WINDOW,
                'skip_rule_summary' => $policyDecision['rule_summary'] ?? null,
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

        $lock = Cache::lock('site-deploy:'.$this->site->id, $this->timeout);
        $activeKey = 'site-deploy-active:'.$this->site->id;

        if (! $lock->get()) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $this->site->id,
                'project_id' => $this->site->project_id,
                'trigger' => $this->trigger,
                'status' => SiteDeployment::STATUS_SKIPPED,
                'skip_reason' => SiteDeployment::SKIP_REASON_ALREADY_RUNNING,
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
                $ephemeralLog[] = 'Installed on '.($this->site->server->name ?? 'the server')
                    .' via the operational SSH key; this deploy authenticates with it.';
            }

            // Make any customer-supplied identity available to the env push that
            // runs deep inside the deploy engine, so a site with customer-held
            // escrowed secrets can be deployed. Cleared in the finally below so
            // it never leaks into the next job on this worker.
            app(EphemeralSecretIdentityContext::class)->set($this->pullEphemeralIdentity());

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

                // UNIVERSAL post-cutover HTTP health gate. The atomic deployer
                // already smoke-tests inline (with auto-rollback) and records the
                // 'health' phase; every OTHER VM path (simple/flat) otherwise
                // reaches success WITHOUT one — so a render-time 500 went green.
                // Run the same gate here for those paths before the deploy can be
                // called a success. A flat deploy can't roll back (no previous
                // release symlink), so this DETECTS and fails the deploy rather
                // than silently succeeding. The checker honors
                // meta.deploy_health_enabled and self-skips when there's no
                // hostname, so this is a safe no-op where it shouldn't run.
                if ($this->site->server->isVmHost()
                    && filled($this->site->server->ssh_private_key)
                    && ! $deployment->hasPhase('health')) {
                    $healthStart = microtime(true);
                    try {
                        $healthLog = app(AtomicDeployHealthChecker::class)
                            ->verify($this->site, new SshConnection($this->site->server));
                        if (trim($healthLog) !== '') {
                            $deployment->recordPhaseResults('health', [[
                                'label' => __('HTTP health check'),
                                'ok' => true,
                                'skipped' => str_contains($healthLog, 'skipped:'),
                                'output' => trim($healthLog),
                                'duration_ms' => (int) round((microtime(true) - $healthStart) * 1000),
                            ]]);
                            $redacted .= "\n".DeployLogRedactor::redact($healthLog);
                        }
                    } catch (\Throwable $healthError) {
                        $deployment->recordPhaseResults('health', [[
                            'label' => __('HTTP health check'),
                            'ok' => false,
                            'output' => $healthError->getMessage(),
                            'duration_ms' => (int) round((microtime(true) - $healthStart) * 1000),
                        ]]);
                        // Re-throw → the catch below marks the deployment FAILED
                        // with the diagnostic and does NOT advance last_deploy_at.
                        throw $healthError;
                    }
                }

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
                $caps = $this->site->server?->hostCapabilities();
                if ($caps?->supportsFunctionDeploy() || $caps?->supportsClusterDeploy()) {
                    $siteUpdates['status'] = Site::activeStatusForWebserver($this->site->webserver());
                } elseif ($caps?->supportsEnvPushToHost()) {
                    // Self-heal on a VM web host: a successful deploy proves the
                    // site is live, so a stale or wrong status (e.g. a mis-seeded
                    // edge_failed inherited from a DB seed, or a transient error)
                    // should resolve to the correct webserver-active status.
                    // Only rewrite when the status ISN'T already a healthy active
                    // one — a correctly-provisioned site keeps its status and we
                    // avoid churning the row on every deploy.
                    $target = Site::activeStatusForWebserver($this->site->webserver());
                    if ($this->site->status !== $target
                        && ! in_array($this->site->status, Site::webserverActiveStatuses(), true)) {
                        $siteUpdates['status'] = $target;
                    }
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
                // Drop the customer-held identity so it never leaks into a later
                // job on this (reused) worker container.
                app(EphemeralSecretIdentityContext::class)->forget();

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
     * Pull-and-forget the customer-held org identity stashed for this deploy.
     * Mirrors {@see PushSiteEnvJob} — the payload carries only a cache token, the
     * raw key lives transiently in the cache and is dropped the moment it's read.
     */
    private function pullEphemeralIdentity(): ?string
    {
        // isset() (not === null) so a stale queue payload serialized before this
        // property existed — which leaves the typed property uninitialized on
        // unserialize, not null — degrades gracefully instead of fatally.
        if (! isset($this->ephemeralIdentityToken)) {
            return null;
        }

        $identity = Cache::pull(PushSiteEnvJob::EPHEMERAL_IDENTITY_CACHE_PREFIX.$this->ephemeralIdentityToken);

        return is_string($identity) ? $identity : null;
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
        $originPhases = $origin->phase_results;
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

    /**
     * True when a human kicked off this deploy from the UI (manual click or a
     * resume click) — as opposed to a webhook/API/schedule/sync trigger with no
     * human watching. Interactive deploys that hit the billing pause are dropped
     * silently (the UI already prompts for payment) rather than leaving a
     * phantom "skipped" row; machine triggers leave an audited record.
     */
    protected function isInteractiveTrigger(): bool
    {
        return in_array($this->trigger, [
            SiteDeployment::TRIGGER_MANUAL,
            SiteDeployment::TRIGGER_RESUME,
        ], true);
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
        $user = $this->auditUserId ? User::find($this->auditUserId) : null;
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
     * @param  array{allowed: bool, reason: ?string, rule_summary: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}  $policyDecision
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
                $this->auditUserId ? User::find($this->auditUserId) : null,
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
            // $exception is null when the worker was killed outright (OOM,
            // SIGKILL, a self-deploy restarting this very queue) rather than
            // throwing — guard against a blank reason so the deploy never reads
            // as "failed with no output" in the UI.
            $reason = trim((string) ($exception?->getMessage() ?? ''));
            if ($reason === '') {
                $reason = 'The deploy worker was terminated mid-deploy before it could record an error (e.g. restart, timeout, or out-of-memory). Trigger the deploy again.';
            }

            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'exit_code' => $deployment->exit_code ?? 1,
                'log_output' => trim(($deployment->log_output ? $deployment->log_output."\n\n" : '')
                    .'Deployment failed: '.$reason),
                'finished_at' => now(),
            ]);
        }
    }
}
