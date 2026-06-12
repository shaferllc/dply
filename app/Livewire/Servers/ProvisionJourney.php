<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionOvhServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Services\Servers\ProvisionStepEtaService;
use App\Services\Servers\ServerJourneyInfrastructureAlerts;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\InstalledStack;
use App\Support\Servers\ProvisionPipelineLog;
use App\Support\Servers\ProvisionStepDurations;
use App\Support\Servers\ProvisionStepSnapshots;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Livewire;

#[Layout('layouts.app')]
class ProvisionJourney extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public bool $showCancelProvisionModal = false;

    public bool $showResumeInstallModal = false;

    /** @var string SHA-256 of last logged journey snapshot (avoids log spam on wire:poll). */
    public string $journeyViewLogSignature = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        // Server may have been deleted out from under us (e.g. user just confirmed
        // the remove-server modal). Bail to the index instead of letting refresh()
        // throw ModelNotFoundException, which surfaces in the UI as a 404 inside
        // any lingering modal/poll response.
        $fresh = Server::query()->find($this->server->getKey());
        if ($fresh === null) {
            $this->showRemoveServerModal = false;
            $this->showCancelProvisionModal = false;

            if (Livewire::isLivewireRequest()) {
                $this->dispatch('provision-journey-complete', url: route('servers.index'));
            } else {
                $this->redirectRoute('servers.index', navigate: true);
            }

            return view('livewire.servers.provision-journey-removed');
        }
        $this->server = $fresh;

        if ($this->shouldRedirectToServerOverview()) {
            if (Livewire::isLivewireRequest()) {
                $this->dispatch('provision-journey-complete', url: route('servers.overview', $this->server));
            } else {
                $this->redirectRoute('servers.overview', $this->server, navigate: true);
            }
        }

        $task = $this->provisionTask();
        $run = $this->provisionRun();
        $artifacts = $run?->artifacts()->get() ?? collect();
        $steps = $this->steps($task);
        $completedCount = collect($steps)->where('state', 'completed')->count();
        $shouldPoll = $this->shouldPoll();
        $verificationChecks = $this->verificationChecks($artifacts);
        $failureClassification = $this->failureClassification($task, $steps, $run, $verificationChecks);
        $repairGuidance = $this->repairGuidance($task, $steps, $run, $verificationChecks, $failureClassification);
        $stackSummary = $this->stackSummary($artifacts);
        $stallState = $this->stallState($task, $steps);

        $stepStatesSig = collect($steps)
            ->map(fn (array $s): string => ($s['key'] ?? '').':'.$s['state'])
            ->implode('|');
        $activeRow = collect($steps)->firstWhere('state', 'active');
        $failedRow = collect($steps)->firstWhere('state', 'failed');
        $sigPayload = [
            'server_status' => $this->server->status,
            'setup_status' => $this->server->setup_status,
            'task_id' => $task?->id,
            'task_status' => $task?->status?->value,
            'run_id' => $run?->id,
            'run_status' => $run?->status,
            'step_signature' => $stepStatesSig,
            'active_key' => $activeRow['key'] ?? null,
            'failed_key' => $failedRow['key'] ?? null,
            'should_poll' => $shouldPoll,
        ];
        $sig = hash('sha256', json_encode($sigPayload, JSON_THROW_ON_ERROR));
        if ($this->journeyViewLogSignature !== $sig) {
            $this->journeyViewLogSignature = $sig;
            ProvisionPipelineLog::info('server.provision.journey.view_state', $this->server, [
                'phase' => 'ui',
                'active_step' => $activeRow['label'] ?? null,
                'failed_step' => $failedRow['label'] ?? null,
                'completed_steps' => $completedCount,
                'total_steps' => count($steps),
                'task_status' => $task?->status?->value,
                'run_status' => $run?->status,
                'should_poll' => $shouldPoll,
            ]);
        }

        return view('livewire.servers.provision-journey', [
            'task' => $task,
            'run' => $run,
            'infrastructureAlerts' => app(ServerJourneyInfrastructureAlerts::class)->forServer($this->server),
            'localDevShellHints' => $this->localDevShellHints(),
            // True when this server row was created during a prior
            // fake-cloud session but DPLY_FAKE_CLOUD_PROVISION is now
            // off. The view surfaces a clear "orphan — delete and
            // recreate" banner instead of misleading the operator with
            // "100% complete" stats from cached fake-cloud step state.
            'isOrphanedFakeServer' => FakeCloudProvision::isOrphanedFakeServer($this->server),
            'artifacts' => $artifacts,
            'steps' => $steps,
            'completedCount' => $completedCount,
            'totalCount' => count($steps),
            'activeStep' => collect($steps)->firstWhere('state', 'active'),
            'pendingSteps' => collect($steps)->where('state', 'pending')->values(),
            'completedSteps' => collect($steps)->where('state', 'completed')->values(),
            'failedStep' => collect($steps)->firstWhere('state', 'failed'),
            'verificationChecks' => $verificationChecks,
            'failureClassification' => $failureClassification,
            'repairGuidance' => $repairGuidance,
            // Reconciled snapshot of what physically landed (vs the
            // wizard's request). View uses this to render the
            // "Requested vs Installed" divergence banner when applicable.
            'installedStack' => InstalledStack::fromMeta($this->server),
            'installedStackDiverges' => InstalledStack::fromMeta($this->server)->divergesFromRequest($this->server),
            'requestedDatabase' => $this->server->meta['database'] ?? null,
            'stackSummary' => $stackSummary,
            'stackTiles' => $stackSummary ? [
                ['label' => __('Role'),        'value' => $stackSummary['role'],         'icon' => 'heroicon-o-rectangle-stack'],
                ['label' => __('Web server'),  'value' => $stackSummary['webserver'],    'icon' => 'heroicon-o-globe-alt'],
                ['label' => __('PHP'),         'value' => $stackSummary['php_version'],  'icon' => 'heroicon-o-code-bracket'],
                ['label' => __('Database'),    'value' => $stackSummary['database'],     'icon' => 'heroicon-o-circle-stack'],
                ['label' => __('Cache'),       'value' => $stackSummary['cache_service'], 'icon' => 'heroicon-o-bolt'],
                ['label' => __('Deploy user'), 'value' => $stackSummary['deploy_user'],  'icon' => 'heroicon-o-user-circle'],
            ] : [],
            'stallState' => $stallState,
            'shouldPoll' => $shouldPoll,
            'canCancelProvision' => $this->canCancelProvision($task),
            'canRetryCloudProvision' => $this->canRetryCloudProvision($this->server),
            'cloudProvisionError' => is_array($this->server->meta['provision_error'] ?? null)
                ? $this->server->meta['provision_error']
                : null,
            'liveTaskOutput' => $this->liveTaskOutput($task),
            'liveTaskOutputLineCount' => $this->liveTaskOutputLineCount($task),
            'taskUpdatedAt' => $task?->updated_at,
            'rollbackSummary' => $this->rollbackSummary($task),
            'failureReason' => $this->failureReason($task, collect($steps)->firstWhere('state', 'failed')),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server->fresh())
                : null,
        ]);
    }

    public function openCancelProvisionModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showCancelProvisionModal = true;
    }

    public function closeCancelProvisionModal(): void
    {
        $this->showCancelProvisionModal = false;
    }

    public function cancelProvision(TaskRunnerService $taskRunner): void
    {
        $this->authorize('update', $this->server);

        $task = $this->activeProvisionTask();
        if (! $task) {
            $this->toastError(__('There is no active build task to cancel right now.'));
            $this->showCancelProvisionModal = false;

            return;
        }

        $result = $taskRunner->cancelTask((string) $task->id);

        if (! ($result['success'] ?? false)) {
            $this->toastError((string) ($result['error'] ?? __('Could not cancel the build task.')));

            return;
        }

        $this->server->refresh();
        $this->showCancelProvisionModal = false;
        $this->toastSuccess(__('Build cancelled. You can keep this server or remove it.'));
    }

    public function cancelProvisionAndOpenDelete(TaskRunnerService $taskRunner): void
    {
        $this->authorize('delete', $this->server);

        $task = $this->activeProvisionTask();
        if ($task) {
            $result = $taskRunner->cancelTask((string) $task->id);

            if (! ($result['success'] ?? false)) {
                $this->toastError((string) ($result['error'] ?? __('Could not cancel the build task.')));

                return;
            }
        }

        $this->server->refresh();
        $this->showCancelProvisionModal = false;
        $this->openRemoveServerModal();
    }

    protected function shouldPoll(): bool
    {
        return ! $this->shouldRedirectToServerOverview();
    }

    /**
     * Copy/paste hints for local SSH dev (docker-compose.ssh-dev, fake cloud). Not used in production UI.
     *
     * @return array{
     *     ssh:string,
     *     docker_exec:string,
     *     web_terminal_url:?string,
     *     fake_cloud_enabled:bool,
     *     is_fake_server:bool
     * }|null
     */
    protected function localDevShellHints(): ?array
    {
        // Two gates per the local-dev pattern: only render when the
        // operator is actually running fake-cloud locally. Disabling
        // DPLY_FAKE_CLOUD_PROVISION (or moving to a non-local env)
        // hides the local-docker shell hints entirely so production
        // operators don't see "exec into the dply-ssh-dev container"
        // hints that would mislead them.
        if (! app()->environment('local') || ! FakeCloudProvision::enabled()) {
            return null;
        }

        $ip = trim((string) ($this->server->ip_address ?? ''));
        $port = (int) ($this->server->ssh_port ?: 22);
        $user = trim((string) ($this->server->ssh_user ?? ''));
        if ($user === '') {
            $user = 'root';
        }

        $ssh = $ip !== ''
            ? ($port === 22 ? "ssh {$user}@{$ip}" : "ssh -p {$port} {$user}@{$ip}")
            : '';

        $container = (string) config('server_provision.local_dev_ssh_compose_container', 'dply-ssh-dev');
        $dockerExec = "docker exec -it {$container} /bin/bash";

        $webUrl = config('server_provision.local_dev_web_terminal_url');
        $webTerminalUrl = is_string($webUrl) && $webUrl !== '' ? $webUrl : null;

        return [
            'ssh' => $ssh,
            'docker_exec' => $dockerExec,
            'web_terminal_url' => $webTerminalUrl,
            'fake_cloud_enabled' => FakeCloudProvision::enabled(),
            'is_fake_server' => FakeCloudProvision::isFakeServer($this->server),
        ];
    }

    protected function shouldRedirectToServerOverview(): bool
    {
        return $this->server->status === Server::STATUS_READY
            && $this->server->setup_status === Server::SETUP_STATUS_DONE;
    }

    public function openResumeInstallModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showResumeInstallModal = true;
    }

    public function closeResumeInstallModal(): void
    {
        $this->showResumeInstallModal = false;
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);
        $this->showResumeInstallModal = false;

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->toastError('This server is not ready for a setup re-run yet.');

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);
        unset($meta['provision_step_snapshots']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        $fresh = $server->fresh();
        if ($fresh) {
            ProvisionPipelineLog::info('server.provision.journey.rerun_setup_dispatched', $fresh, [
                'phase' => 'ui',
            ]);
        }
        WaitForServerSshReadyJob::dispatch($fresh ?? $server);

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    /**
     * Re-dispatch the provider-specific provision job when the cloud-side
     * call failed (e.g. region/size mismatch) before any provider resource
     * was created. Only safe to call while the server has no provider_id,
     * otherwise we'd create a duplicate cloud resource.
     */
    public function retryCloudProvision(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! $this->canRetryCloudProvision($server)) {
            $this->toastError(__('This server cannot be retried — it already has a provider resource or is not in a failed cloud-provision state.'));

            return;
        }

        $job = $this->provisionJobClassFor($server);
        if ($job === null) {
            $this->toastError(__('Retry is not supported for this provider yet.'));

            return;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        unset($meta['provision_error']);
        unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);

        $server->forceFill([
            'status' => Server::STATUS_PENDING,
            'meta' => $meta,
        ])->save();

        $fresh = $server->fresh() ?? $server;

        ProvisionPipelineLog::info('server.provision.journey.retry_cloud_dispatched', $fresh, [
            'phase' => 'ui',
            'provider' => $fresh->provider?->value,
        ]);

        $job::dispatch($fresh);

        $this->redirectRoute('servers.journey', $fresh, navigate: true);
    }

    protected function canRetryCloudProvision(Server $server): bool
    {
        if ($server->status !== Server::STATUS_ERROR) {
            return false;
        }

        // Setup-side failures are handled by Resume install — only retry
        // pre-SSH cloud-side failures here.
        if ($server->setup_status === Server::SETUP_STATUS_FAILED) {
            return false;
        }

        // Server already exists at the provider — re-dispatching would
        // create a duplicate. Operator should remove and recreate instead.
        if (filled($server->provider_id)) {
            return false;
        }

        return $this->provisionJobClassFor($server) !== null;
    }

    /**
     * Drop the failed server row and bounce the operator back to the
     * create wizard with a fresh draft pre-filled from this server's
     * saved fields. Only safe when no provider resource was created.
     * The user lands on step 2 (Where) so they can change the bad
     * region/size before re-submitting.
     */
    public function editAndRetry(): void
    {
        $this->authorize('update', $this->server);
        $this->authorize('delete', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! $this->canRetryCloudProvision($server)) {
            $this->toastError(__('This server can no longer be edited — its provider resource exists or it is mid-flight.'));

            return;
        }

        $user = auth()->user();
        $org = $server->organization;

        if ($user === null || $org === null) {
            $this->toastError(__('You are not in an organization context — cannot restore the wizard draft.'));

            return;
        }

        $payload = $this->buildDraftPayloadFromServer($server);

        /** @var ServerCreateDraft $draft */
        $draft = ServerCreateDraft::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'organization_id' => $org->getKey(),
        ]);

        $draft->payload = $payload;
        // Land on step 2 (Where) so region/size — the most common fix —
        // is right in front of the operator.
        $draft->step = 2;
        $draft->bumpExpiry();
        $draft->save();

        ProvisionPipelineLog::info('server.provision.journey.edit_and_retry_dispatched', $server, [
            'phase' => 'ui',
            'provider' => $server->provider?->value,
        ]);

        // Drop the failed server — no provider resource exists so this
        // is safe. The wizard will create a fresh row when the operator
        // re-submits.
        $server->delete();

        $this->redirectRoute('servers.create.where', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDraftPayloadFromServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $cacheServer = is_array($meta['cache_server'] ?? null) ? $meta['cache_server'] : [];

        // Re-encrypt the cache password from meta if present (it was stored
        // encrypted with the same app key). For the draft restore path,
        // leaving it blank and asking the operator to re-enter is the
        // safe default — the form field is encrypted-on-save anyway and
        // we don't want to expose the plaintext just to round-trip it.
        return [
            'mode' => 'provider',
            'type' => $server->provider?->value ?? '',
            'name' => (string) ($server->name ?? ''),
            'provider_credential_id' => (string) ($server->provider_credential_id ?? ''),
            'region' => (string) ($server->region ?? ''),
            'size' => (string) ($server->size ?? ''),
            'setup_script_key' => (string) ($server->setup_script_key ?? ''),
            'server_role' => (string) ($meta['server_role'] ?? 'application'),
            'install_profile' => (string) ($meta['install_profile'] ?? 'laravel_app'),
            'webserver' => (string) ($meta['webserver'] ?? 'nginx'),
            'php_version' => (string) ($meta['php_version'] ?? '8.3'),
            'database' => (string) ($meta['database'] ?? 'mysql84'),
            'cache_service' => (string) ($meta['cache_service'] ?? 'redis'),
            'cache_remote_access' => (bool) ($cacheServer['remote_access'] ?? false),
            'cache_allowed_from' => (string) ($cacheServer['allowed_from'] ?? ''),
            'cache_require_password' => (bool) ($cacheServer['require_password'] ?? false),
            'cache_password' => '',
        ];
    }

    /**
     * @return class-string|null
     */
    protected function provisionJobClassFor(Server $server): ?string
    {
        return match ($server->provider) {
            ServerProvider::DigitalOcean => ProvisionDigitalOceanDropletJob::class,
            ServerProvider::Hetzner => ProvisionHetznerServerJob::class,
            ServerProvider::Linode => ProvisionLinodeServerJob::class,
            ServerProvider::Vultr => ProvisionVultrServerJob::class,
            ServerProvider::Ovh => ProvisionOvhServerJob::class,
            ServerProvider::UpCloud => ProvisionUpCloudServerJob::class,
            ServerProvider::Aws => ProvisionAwsEc2ServerJob::class,
            ServerProvider::Azure => ProvisionAzureServerJob::class,
            ServerProvider::Oracle => ProvisionOracleServerJob::class,
            default => null,
        };
    }

    protected function provisionTask(): ?Task
    {
        $taskId = (string) ($this->server->meta['provision_task_id'] ?? '');
        if ($taskId === '') {
            return null;
        }

        return Task::query()->find($taskId);
    }

    protected function provisionRun(): ?ServerProvisionRun
    {
        $runId = (string) ($this->server->meta['provision_run_id'] ?? '');
        if ($runId !== '') {
            return ServerProvisionRun::query()->with('artifacts')->find($runId);
        }

        $task = $this->provisionTask();

        return ServerProvisionRun::query()
            ->with('artifacts')
            ->when($task, fn ($query) => $query->where('task_id', $task->id))
            ->where('server_id', $this->server->id)
            ->latest('created_at')
            ->first();
    }

    protected function activeProvisionTask(): ?Task
    {
        $task = $this->provisionTask();

        if (! $task || ! $task->status->isActive()) {
            return null;
        }

        return $task;
    }

    protected function canCancelProvision(?Task $task): bool
    {
        return $task?->status->isActive() === true
            || in_array($this->server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING], true)
            || $this->server->setup_status === Server::SETUP_STATUS_RUNNING;
    }

    /**
     * @return list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>
     */
    protected function steps(?Task $task): array
    {
        $server = $this->server;
        $scriptSteps = $this->scriptSteps($task);

        $steps = [
            ['key' => 'queued', 'label' => __('Request queued with provider')],
            ['key' => 'provisioning', 'label' => __('Provisioning server')],
            ['key' => 'ip', 'label' => __('Waiting for server IP')],
            ['key' => 'ssh', 'label' => __('Waiting for SSH')],
            ['key' => 'ready', 'label' => __('Server ready')],
        ];

        if ($scriptSteps !== []) {
            array_splice($steps, 4, 0, $scriptSteps);
        } else {
            array_splice($steps, 4, 0, [[
                'key' => 'setup',
                'label' => __('Running server setup'),
            ]]);
        }

        $activeKey = 'queued';
        $failedKey = null;
        $scriptStepKeys = array_column($scriptSteps, 'key');
        $lastSeenScriptKey = $this->lastSeenScriptStepKey($task, $scriptSteps);

        // A provision/setup task can reach a terminal *failed* state
        // (failed / timeout / connection_failed / upload_failed) without
        // anything having flipped the server's own status flags — e.g. the
        // setup job died mid-run, or the result callback from the box never
        // landed. When that happens the branches below compute an active step
        // from the now-stale server status and the view renders a spinner
        // forever; stallState() also bails because the task is no longer
        // active, so the operator never sees the actual error. Treat a
        // terminally-failed task as a journey failure too, so the failed-step
        // banner (with the captured output / failure reason) surfaces. We
        // skip this while an auto-retry is still pending — that state owns its
        // own "Retrying" UI — and once the server is genuinely done.
        $autoRetryAt = isset($server->meta['auto_retry_at'])
            ? Carbon::parse((string) $server->meta['auto_retry_at'])
            : null;
        $autoRetryPending = $autoRetryAt !== null && $autoRetryAt->isFuture();
        $serverReady = $server->status === Server::STATUS_READY
            && $server->setup_status === Server::SETUP_STATUS_DONE;
        $taskFailed = $task !== null
            && $task->status->isFailed()
            && ! $serverReady
            && ! $autoRetryPending;

        // The task drives the *setup* phase (it's only dispatched once SSH is
        // up), so a failed task is a setup-side failure and maps to a script
        // step — same as setup_status === FAILED. A pure cloud-side error
        // (server ERROR, setup never started, no task) still maps to
        // 'provisioning'.
        $failedDuringSetup = $server->setup_status === Server::SETUP_STATUS_FAILED || $taskFailed;

        if ($server->status === Server::STATUS_ERROR || $failedDuringSetup) {
            $activeKey = $failedDuringSetup
                ? ($lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup'))
                : 'provisioning';
            $failedKey = $activeKey;
        } elseif ($server->status === Server::STATUS_PENDING) {
            $activeKey = 'queued';
        } elseif ($server->status === Server::STATUS_PROVISIONING) {
            $activeKey = filled($server->ip_address) ? 'ssh' : 'provisioning';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_PENDING) {
            $activeKey = 'ssh';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_RUNNING) {
            $activeKey = $lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup');
        } elseif ($server->status === Server::STATUS_READY) {
            // Only flip to the terminal 'ready' step once setup is *actually*
            // done. Previously this branch matched on status alone, which
            // caught the brief window after SSH comes up but before the
            // setup job has stamped setup_status (= null) — and marked
            // every cloud + setup step "completed", showing both progress
            // bars at 100% on a server that hadn't started running its
            // bash provision yet. Treat null/unknown like PENDING so the
            // journey holds on 'ssh' until the setup job takes over.
            $activeKey = $server->setup_status === Server::SETUP_STATUS_DONE ? 'ready' : 'ssh';
        }

        $stepIndex = array_flip(array_column($steps, 'key'));
        $activeIndex = $stepIndex[$activeKey] ?? 0;

        // Bulk-resolve ETAs for every script step in one query. The
        // step key for script steps IS the label hash (both come from
        // ProvisionStepSnapshots::keyForLabel), so we can hand the keys
        // directly to the service. Cloud-side steps (queued, provisioning,
        // ip, ssh, ready, setup placeholder) have no historical row and
        // the lookup just returns nothing for them.
        $etaByKey = app(ProvisionStepEtaService::class)
            ->averagesForLabels(
                array_values(array_filter(
                    array_column($steps, 'key'),
                    static fn (string $k): bool => str_starts_with($k, 'script_'),
                )),
                $server->organization,
            );

        return array_map(function (array $step, int $index) use ($activeIndex, $failedKey, $task, $server, $etaByKey): array {
            $state = 'pending';

            if ($failedKey === $step['key']) {
                $state = 'failed';
            } elseif ($index < $activeIndex || ($step['key'] === 'ready' && $activeIndex === $index)) {
                $state = 'completed';
            } elseif ($index === $activeIndex) {
                $state = 'active';
            }

            if ($state === 'pending' && $this->stepHasPersistedSnapshot($server, $step['key'])) {
                $state = 'completed';
            }

            return [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'detail' => $this->stepDetail($step['key'], $task, $server, $state),
                'output' => $this->stepOutput($step['key'], $task, $server, $state),
                'duration' => $this->stepDuration($step['key'], $task, $server, $state),
                // null when no historical average is available (cold start
                // org, or fewer than step_eta_min_samples runs for this
                // step). View should fall back to the static "Usually X"
                // copy when this is missing.
                'eta' => $etaByKey[$step['key']] ?? null,
            ];
        }, $steps, array_keys($steps));
    }

    protected function stepDetail(string $key, ?Task $task, Server $server, string $state): ?string
    {
        $scriptLabel = $this->scriptStepLabelForKey($task, $key);
        if ($scriptLabel !== null) {
            $stepOutput = $this->persistedStepOutput($server, $key) ?? $this->scriptStepOutput($task, $scriptLabel);

            return match ($state) {
                'active' => $this->scriptStepOutputTail($task, $scriptLabel) ?: __('This setup step is currently running.'),
                'failed' => __('This setup step failed before finishing.'),
                'completed' => $this->stepWasSkipped($stepOutput)
                    ? __('Skipped because the required software was already installed.')
                    : __('Completed during server setup.'),
                default => null,
            };
        }

        return match ($key) {
            'queued' => $state === 'active' ? __('Your request has been accepted and is waiting to start provisioning.') : null,
            'provisioning' => $state === 'failed'
                ? __('Provisioning hit an error before the server became reachable.')
                : __('Dply is waiting for the provider to finish building the server.'),
            'ip' => filled($server->ip_address)
                ? __('IP assigned: :ip', ['ip' => $server->ip_address])
                : __('The server will move forward once a public IP is available.'),
            'ssh' => $state === 'active'
                ? __('The server is reachable enough to continue, but SSH setup has not started yet.')
                : __('Dply will continue once SSH is ready.'),
            'setup' => $state === 'failed'
                ? __('The server setup task failed before finishing.')
                : ($task?->tailOutput(3) ?: __('Applying the selected stack and packages.')),
            'ready' => __('The server is ready for normal workspace operations.'),
            default => null,
        };
    }

    protected function stepDuration(string $key, ?Task $task, Server $server, string $state): ?string
    {
        if ($state !== 'active' && $state !== 'completed') {
            return null;
        }

        $isScriptStep = $key === 'setup' || $this->scriptStepLabelForKey($task, $key) !== null;

        if (! $isScriptStep) {
            // Cloud-side steps (queued / provisioning / ip / ssh / ready) —
            // use the elapsed-since-server-created proxy. We don't track
            // these steps in the duration table because their timing is
            // owned by the cloud provider, not the bash script.
            return $server->created_at?->diffForHumans(now(), true);
        }

        if (! $task) {
            return null;
        }

        // Per-step durations come from the `[dply-step-end]` markers
        // emitted by ServerProvisionCommandBuilder::withStep(). For a
        // step that's already completed we have the recorded value
        // directly; for the *active* step there's no end marker yet,
        // so we approximate "running for" as
        //   (task elapsed) - (sum of all completed step durations).
        // That folds out the time spent on prior steps and leaves only
        // the time accumulated since this step started — which used to
        // be wrong, the active script step was showing the entire task
        // wall-clock instead of its own slice.
        $endDurations = $this->stepEndDurations($task);

        if ($state === 'completed' && $key !== 'setup') {
            $hash = $key; // script_<md5> already matches label_hash
            if (isset($endDurations[$hash])) {
                return $this->formatRunDuration($endDurations[$hash]);
            }
        }

        if ($state === 'active') {
            $started = $task->started_at ?? $task->created_at;
            if ($started === null) {
                return null;
            }

            $taskElapsed = (int) abs(now()->diffInSeconds($started, true));
            $completedTotal = array_sum($endDurations);
            $sliceSeconds = max(0, $taskElapsed - $completedTotal);

            return $this->formatRunDuration($sliceSeconds);
        }

        return $task->getDurationForHumans();
    }

    /**
     * Map of label_hash → recorded duration_seconds for every step that
     * has emitted an end marker so far in this task's output. Cached
     * per-render via a property to avoid re-parsing on every step row.
     *
     * @return array<string, int>
     */
    private array $stepEndDurationsCache = [];

    private function stepEndDurations(Task $task): array
    {
        $cacheKey = (string) $task->id.'@'.(string) ($task->updated_at?->timestamp ?? 0);
        if (array_key_exists($cacheKey, $this->stepEndDurationsCache)) {
            return $this->stepEndDurationsCache[$cacheKey];
        }

        $output = is_string($task->output) ? $task->output : '';
        $rows = ProvisionStepDurations::parse($output);

        $map = [];
        foreach ($rows as $row) {
            // Resumed-skip rows have duration_seconds = 0; ignoring them
            // would still be correct here because the active-step math
            // relies on summing real elapsed seconds, not a count.
            $map[$row['label_hash']] = ($map[$row['label_hash']] ?? 0) + (int) $row['duration_seconds'];
        }

        return $this->stepEndDurationsCache[$cacheKey] = $map;
    }

    protected function stepOutput(string $key, ?Task $task, Server $server, string $state): ?string
    {
        $scriptLabel = $this->scriptStepLabelForKey($task, $key);
        if ($scriptLabel !== null) {
            $stepSpecific = $this->persistedStepOutput($server, $key) ?? $this->scriptStepOutput($task, $scriptLabel);
            if ($stepSpecific !== null) {
                return $stepSpecific;
            }

            // Step marker hasn't appeared yet — fall back to the latest raw output so the user still sees activity.
            if ($state === 'active' && $task) {
                $output = trim((string) $task->tailOutput(40));

                return $output !== '' ? $output : null;
            }

            return null;
        }

        if ($key === 'setup' && $task && in_array($state, ['active', 'failed', 'completed'], true)) {
            $output = trim((string) $task->tailOutput(40));

            return $output !== '' ? $output : null;
        }

        return null;
    }

    /**
     * Raw tail of the task output regardless of step framing — gives the user a "tail -f" view of progress.
     */
    protected function liveTaskOutput(?Task $task): ?string
    {
        if (! $task) {
            return null;
        }

        $output = trim((string) $task->tailOutput(150));

        return $output !== '' ? $output : null;
    }

    protected function liveTaskOutputLineCount(?Task $task): int
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return 0;
        }

        return count(preg_split('/\r\n|\r|\n/', $task->output) ?: []);
    }

    /**
     * Extract a one-line "why did this fail" headline + a few supporting lines from the
     * captured step output (or full task output as a fallback). Surfaces the actual error
     * message to the user instead of a generic "step failed before finishing" framing.
     *
     * @param  array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}|null  $failedStep
     * @return array{headline:string, context:list<string>, exit_code:?int}|null
     */
    protected function failureReason(?Task $task, ?array $failedStep): ?array
    {
        if ($failedStep === null) {
            return null;
        }

        $source = trim((string) ($failedStep['output'] ?? ''));
        if ($source === '' && $task !== null && is_string($task->output)) {
            $source = trim($task->output);
        }
        if ($source === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];

        // Drop noise: rollback markers, step markers, empty lines, locale warnings.
        $meaningful = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }
            if (str_contains($trimmed, '[dply-rollback]') || str_contains($trimmed, '[dply-step]')) {
                continue;
            }
            $meaningful[] = $trimmed;
        }
        if ($meaningful === []) {
            return null;
        }

        // High-priority root-cause patterns: when these appear, they're
        // almost always the actual cause of the failure even if a
        // downstream symptom appears later (e.g. a PPA fetch timeout
        // followed by "couldn't find package php8.4-mysql"). Scan the
        // FULL meaningful set (not just the tail) so we catch causes
        // that scrolled past the symptom.
        $rootCausePatterns = [
            '/Could not connect to/i',
            '/Connection (?:timed out|refused)/i',
            '/Failed to fetch/i',
            '/Some index files failed to download/i',
            '/Network is unreachable/i',
            '/Temporary failure resolving/i',
        ];

        // Lower-priority symptom patterns — match these only when no
        // root cause was found. Scanned from the tail backwards.
        $errorPatterns = [
            '/^E:\s/i',                                  // apt
            '/^Err:/i',                                  // apt
            '/^Error:\s/i',                              // generic
            '/^FATAL:/i',
            '/^fatal:/i',
            '/Cannot\s/i',
            '/Permission denied/i',
            '/No such file or directory/i',
            '/command not found/i',
            '/exited with (?:status|code)\s+\d+/i',
            '/Timeout was reached/i',
            '/Failed to (?:start|connect|enable)/i',
            '/dpkg:\s+error/i',
            '/Sub-process\s+\S+\s+returned/i',
        ];

        $headline = null;
        $contextStart = max(0, count($meaningful) - 8);
        $tail = array_slice($meaningful, $contextStart);

        foreach ($meaningful as $line) {
            foreach ($rootCausePatterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $headline = $line;
                    break 2;
                }
            }
        }

        if ($headline === null) {
            foreach (array_reverse($tail, true) as $line) {
                foreach ($errorPatterns as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $headline = $line;
                        break 2;
                    }
                }
            }
        }

        if ($headline === null) {
            // Fall back to the last meaningful line.
            $headline = end($tail) ?: end($meaningful);
        }

        $exitCode = null;
        if (preg_match('/exited with (?:status|code)\s+(\d+)/i', $source, $m) === 1) {
            $exitCode = (int) $m[1];
        } elseif ($task && $task->exit_code !== null) {
            $exitCode = (int) $task->exit_code;
        }

        // Trim very long lines for the headline so we don't overflow the banner.
        if (mb_strlen($headline) > 280) {
            $headline = mb_substr($headline, 0, 277).'…';
        }

        return [
            'headline' => $headline,
            'context' => array_slice($meaningful, max(0, count($meaningful) - 5)),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Parse `[dply-rollback] <relpath> :: <action> :: <detail>` markers emitted by the
     * bootstrap script's ERR trap + dply_restore_backups helper. Lets us tell the user
     * whether automatic rollback ran and what files it touched.
     *
     * @return array{
     *     triggered: bool,
     *     restored: list<string>,
     *     removed: list<string>,
     *     other: list<array{path:string, action:string, detail:string}>,
     *     total: int
     * }|null
     */
    protected function rollbackSummary(?Task $task): ?array
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $triggered = false;
        $restored = [];
        $removed = [];
        $other = [];

        $lines = preg_split('/\r\n|\r|\n/', $task->output) ?: [];
        foreach ($lines as $line) {
            if (! str_contains($line, '[dply-rollback]')) {
                continue;
            }

            // Strip prefix and split "<path> :: <action> :: <detail>".
            $body = trim((string) preg_replace('/^.*\[dply-rollback\]\s*/', '', $line));
            $segments = array_map('trim', explode('::', $body, 3));
            $path = $segments[0] ?? '';
            $action = strtolower($segments[1] ?? '');
            $detail = $segments[2] ?? '';

            if ($path === 'automatic' && $action === 'started') {
                $triggered = true;

                continue;
            }

            if ($action === 'restored') {
                $restored[] = $path;
            } elseif ($action === 'removed') {
                $removed[] = $path;
            } elseif ($action !== 'checkpoint' && $action !== '') {
                $other[] = ['path' => $path, 'action' => $action, 'detail' => $detail];
            }
        }

        if (! $triggered && $restored === [] && $removed === [] && $other === []) {
            return null;
        }

        return [
            'triggered' => $triggered,
            'restored' => $restored,
            'removed' => $removed,
            'other' => $other,
            'total' => count($restored) + count($removed),
        ];
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function scriptSteps(?Task $task): array
    {
        if (! $task) {
            return [];
        }

        $source = is_string($task->script_content) && trim($task->script_content) !== ''
            ? $task->script_content
            : (is_string($task->output) ? $task->output : '');

        if (trim($source) === '') {
            return [];
        }

        $labels = $this->extractScriptStepLabels($source);

        return array_map(
            fn (string $label): array => [
                'key' => 'script_'.md5($label),
                'label' => $label,
            ],
            $labels,
        );
    }

    /**
     * @return list<string>
     */
    protected function extractScriptStepLabels(string $content): array
    {
        return ProvisionStepSnapshots::extractLabels($content);
    }

    protected function lastSeenScriptStepKey(?Task $task, array $scriptSteps): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '' || $scriptSteps === []) {
            return null;
        }

        $seenLabels = $this->extractScriptStepLabels($task->output);
        if ($seenLabels === []) {
            return null;
        }

        $lastSeenLabel = $seenLabels[array_key_last($seenLabels)];

        foreach ($scriptSteps as $step) {
            if ($step['label'] === $lastSeenLabel) {
                return $step['key'];
            }
        }

        return null;
    }

    protected function scriptStepLabelForKey(?Task $task, string $key): ?string
    {
        foreach ($this->scriptSteps($task) as $step) {
            if ($step['key'] === $key) {
                return $step['label'];
            }
        }

        return null;
    }

    protected function scriptStepOutputTail(?Task $task, string $label): ?string
    {
        $output = $this->scriptStepOutput($task, $label);

        if ($output === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];

        return implode("\n", array_slice($lines, -3));
    }

    protected function scriptStepOutput(?Task $task, string $label): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $task->output) ?: [];
        $filtered = [];
        $capture = false;

        foreach ($lines as $line) {
            if (str_contains($line, ProvisionStepSnapshots::SCRIPT_STEP_PREFIX.$label)) {
                $capture = true;

                continue;
            }

            if ($capture && str_contains($line, ProvisionStepSnapshots::SCRIPT_STEP_PREFIX)) {
                break;
            }

            if ($capture && trim($line) !== '') {
                $filtered[] = $line;
            }
        }

        return $filtered === [] ? null : implode("\n", $filtered);
    }

    protected function persistedStepOutput(Server $server, string $key): ?string
    {
        $snapshot = $server->meta['provision_step_snapshots'][$key] ?? null;
        $output = is_array($snapshot) ? trim((string) ($snapshot['output'] ?? '')) : '';

        return $output !== '' ? $output : null;
    }

    protected function stepHasPersistedSnapshot(Server $server, string $key): bool
    {
        return $this->persistedStepOutput($server, $key) !== null;
    }

    protected function stepWasSkipped(?string $output): bool
    {
        if (! is_string($output) || trim($output) === '') {
            return false;
        }

        return str_contains($output, 'already installed; skipping package install.')
            || str_contains($output, 'already installed; skipping installer.')
            || str_contains($output, 'already installed; skipping package setup.');
    }

    /**
     * @param  Collection<int, mixed>  $artifacts
     * @return list<array{key:string,label:string,status:string,detail:?string}>
     */
    protected function verificationChecks(Collection $artifacts): array
    {
        /** @var ServerProvisionArtifact|null $artifact */
        $artifact = $artifacts->firstWhere('type', 'verification_report');

        return ProvisionVerificationSummary::fromArtifact($artifact);
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>  $steps
     * @param  list<array{key:string,label:string,status:string,detail:?string}>  $verificationChecks
     * @return array{code:string,label:string,detail:string}|null
     */
    protected function failureClassification(?Task $task, array $steps, ?ServerProvisionRun $run, array $verificationChecks): ?array
    {
        if (! $run || $run->status !== 'failed') {
            return null;
        }

        $failedStep = collect($steps)->firstWhere('state', 'failed');

        return ClassifyProvisionFailure::classify(
            $failedStep['label'] ?? null,
            $task?->tailOutput(12),
            $verificationChecks,
            $run->rollback_status,
        );
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>  $steps
     * @param  list<array{key:string,label:string,status:string,detail:?string}>  $verificationChecks
     * @param  array{code:string,label:string,detail:string}|null  $failureClassification
     * @return array{summary:string,actions:list<string>,commands:list<string>}|null
     */
    protected function repairGuidance(?Task $task, array $steps, ?ServerProvisionRun $run, array $verificationChecks, ?array $failureClassification): ?array
    {
        if (! $run || ! in_array($run->status, ['failed', 'cancelled'], true)) {
            return null;
        }

        $failedStep = collect($steps)->firstWhere('state', 'failed');
        $failingChecks = collect($verificationChecks)
            ->where('status', '!=', 'ok')
            ->pluck('label')
            ->values()
            ->all();

        $actions = [];
        $commands = [];

        if ($run->rollback_status === 'repair_required') {
            $actions[] = 'Inspect the generated config and service state before reusing this server.';
            $actions[] = 'Remove the server if you want a fully clean rebuild.';
        } else {
            $actions[] = 'Resume install after reviewing the failed step output.';
            $actions[] = 'Inspect the generated configs and recent task output if the rerun fails again.';
        }

        if ($failingChecks !== []) {
            $actions[] = 'Review the failed verification checks: '.implode(', ', $failingChecks).'.';
        }

        if (($failureClassification['code'] ?? null) === 'package_repo_unreachable') {
            $actions = [
                'This usually means a package mirror or PPA was briefly unreachable. Click Re-run setup to try again.',
                'If it keeps failing, check whether the server can reach archive.ubuntu.com and ppa.launchpadcontent.net (HTTPS, port 443).',
                'In rare cases the PPA itself is offline — in that case waiting a few minutes is the only fix.',
            ];
            $commands[] = 'curl -I https://ppa.launchpadcontent.net';
            $commands[] = 'sudo apt-get update';
        } elseif (($failureClassification['code'] ?? null) === 'config_validation') {
            $commands[] = 'sudo nginx -t';
            $commands[] = 'sudo haproxy -c -f /etc/haproxy/haproxy.cfg';
        } elseif (($failureClassification['code'] ?? null) === 'service_startup') {
            $commands[] = 'sudo systemctl status nginx --no-pager';
            $commands[] = 'sudo systemctl status php8.3-fpm --no-pager';
        } else {
            $commands[] = 'sudo journalctl -xe --no-pager | tail -n 80';
            $commands[] = 'sudo systemctl --failed';
        }

        return [
            'summary' => $failedStep
                ? 'The run stopped during "'.$failedStep['label'].'". Review the output and the suggested actions before retrying.'
                : 'Provisioning did not complete. Review the latest output and suggested actions before retrying.',
            'actions' => array_values(array_unique($actions)),
            'commands' => array_values(array_unique($commands)),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $artifacts
     * @return array{role:?string,webserver:?string,php_version:?string,database:?string,cache_service:?string,deploy_user:?string,expected_services:list<string>,paths:array<string,string>,config_files:list<string>}|null
     */
    protected function stackSummary(Collection $artifacts): ?array
    {
        /** @var ServerProvisionArtifact|null $artifact */
        $artifact = $artifacts->firstWhere('type', 'stack_summary');
        if (! $artifact) {
            return null;
        }

        $decoded = $artifact->metadata;
        if (! is_array($decoded) || $decoded === []) {
            $decoded = json_decode((string) $artifact->content, true);
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return [
            'role' => isset($decoded['role']) ? (string) $decoded['role'] : null,
            'webserver' => isset($decoded['webserver']) ? (string) $decoded['webserver'] : null,
            'php_version' => isset($decoded['php_version']) ? (string) $decoded['php_version'] : null,
            'database' => isset($decoded['database']) ? (string) $decoded['database'] : null,
            'cache_service' => isset($decoded['cache_service']) ? (string) $decoded['cache_service'] : null,
            'deploy_user' => isset($decoded['deploy_user']) ? (string) $decoded['deploy_user'] : null,
            'expected_services' => array_values(array_filter(array_map('strval', is_array($decoded['expected_services'] ?? null) ? $decoded['expected_services'] : []))),
            'paths' => is_array($decoded['paths'] ?? null) ? $decoded['paths'] : [],
            'config_files' => array_values(array_filter(array_map('strval', is_array($decoded['config_files'] ?? null) ? $decoded['config_files'] : []))),
        ];
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string,eta:?array{seconds:int,samples:int}}>  $steps
     * @return array{eta:string,eta_samples:?int,running_for:string,last_output:?string,stalled:bool,warning:?string}|null
     */
    protected function stallState(?Task $task, array $steps): ?array
    {
        if (! $task || ! $task->status->isActive()) {
            return null;
        }

        $activeStep = collect($steps)->firstWhere('state', 'active');
        $now = now();
        // Carbon 3's diffInSeconds() returns a SIGNED float — when the
        // argument is in the past it comes back negative, and max(0, …)
        // then clamps the timer to zero. Operators saw "Running for 0s"
        // sit there forever even after several minutes for that reason.
        // Pass `true` for absolute, then int-cast — keeps the timer
        // monotonic regardless of which Carbon version is active.
        $secondsSinceUpdate = (int) abs($now->diffInSeconds($task->updated_at ?? $task->started_at ?? $now, true));
        $secondsRunning = (int) abs($now->diffInSeconds($task->started_at ?? $task->created_at ?? $now, true));

        // Prefer the data-driven ETA from past runs over the static
        // "Usually X minutes" copy. The eta payload only lands on
        // script_* steps (cloud-side keys never have one) and only
        // when sample size cleared the configured threshold.
        $etaSamples = null;
        $stepEta = $activeStep['eta'] ?? null;
        if (is_array($stepEta) && ($stepEta['seconds'] ?? 0) > 0) {
            $eta = sprintf('Avg %s', $this->formatRunDuration((int) $stepEta['seconds']));
            $etaSamples = (int) ($stepEta['samples'] ?? 0);
        } else {
            $eta = match ($activeStep['key'] ?? null) {
                'provisioning', 'ip', 'ssh' => 'Usually 2-5 minutes',
                'setup' => 'Usually 5-10 minutes',
                default => 'Usually a few minutes',
            };
        }

        // Stall heuristics in minutes (integer thresholds are fine here
        // because we round up to favour the operator: a 2m59s gap should
        // still tip into "looks stalled" sooner rather than later).
        $minutesSinceUpdate = (int) ceil($secondsSinceUpdate / 60);
        $minutesRunning = (int) ceil($secondsRunning / 60);
        $stalled = $minutesSinceUpdate >= 3 || $minutesRunning >= 8;

        return [
            'eta' => $eta,
            'eta_samples' => $etaSamples,
            'running_for' => 'Running for '.$this->formatRunDuration($secondsRunning),
            // Only surface this when the gap is meaningful; under 30s
            // is just normal poll cadence and would flicker on/off.
            'last_output' => $secondsSinceUpdate >= 30
                ? 'No new output for '.$this->formatRunDuration($secondsSinceUpdate)
                : null,
            'stalled' => $stalled,
            'warning' => $stalled ? 'This run may be stalled. Review the latest output or cancel and retry if it does not recover soon.' : null,
        ];
    }

    private function formatRunDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        if ($minutes < 10 && $remainder > 0) {
            return "{$minutes}m {$remainder}s";
        }

        return $minutes.'m';
    }
}
