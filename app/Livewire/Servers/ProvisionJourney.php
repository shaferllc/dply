<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Services\Servers\ServerJourneyInfrastructureAlerts;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ProvisionPipelineLog;
use App\Support\Servers\ProvisionStepSnapshots;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Contracts\View\View;
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
            'stackSummary' => $stackSummary,
            'stallState' => $stallState,
            'shouldPoll' => $shouldPoll,
            'canCancelProvision' => $this->canCancelProvision($task),
            'liveTaskOutput' => $this->liveTaskOutput($task),
            'liveTaskOutputLineCount' => $this->liveTaskOutputLineCount($task),
            'taskUpdatedAt' => $task?->updated_at,
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
        if (! app()->environment('local')) {
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
        if (app()->environment('local')) {
            return false;
        }

        return $this->server->status === Server::STATUS_READY
            && $this->server->setup_status === Server::SETUP_STATUS_DONE;
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);

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

        if ($server->status === Server::STATUS_ERROR || $server->setup_status === Server::SETUP_STATUS_FAILED) {
            $activeKey = $server->setup_status === Server::SETUP_STATUS_FAILED
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
            $activeKey = 'ready';
        }

        $stepIndex = array_flip(array_column($steps, 'key'));
        $activeIndex = $stepIndex[$activeKey] ?? 0;

        return array_map(function (array $step, int $index) use ($activeIndex, $failedKey, $task, $server): array {
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

        if (($key === 'setup' || $this->scriptStepLabelForKey($task, $key) !== null) && $task) {
            return $task->getDurationForHumans();
        }

        return $server->created_at?->diffForHumans(now(), true);
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

        if (($failureClassification['code'] ?? null) === 'config_validation') {
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
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>  $steps
     * @return array{eta:string,last_output:string,stalled:bool,warning:?string}|null
     */
    protected function stallState(?Task $task, array $steps): ?array
    {
        if (! $task || ! $task->status->isActive()) {
            return null;
        }

        $activeStep = collect($steps)->firstWhere('state', 'active');
        $minutesSinceUpdate = (int) max(0, now()->diffInMinutes($task->updated_at ?? $task->started_at ?? now()));
        $minutesRunning = (int) max(0, now()->diffInMinutes($task->started_at ?? $task->created_at ?? now()));
        $eta = match ($activeStep['key'] ?? null) {
            'provisioning', 'ip', 'ssh' => 'Usually 2-5 minutes',
            'setup' => 'Usually 5-10 minutes',
            default => 'Usually a few minutes',
        };

        $stalled = $minutesSinceUpdate >= 3 || $minutesRunning >= 8;

        return [
            'eta' => $eta,
            'last_output' => $minutesSinceUpdate === 0
                ? "Running for {$minutesRunning} minute".($minutesRunning === 1 ? '' : 's')
                : "No new output for {$minutesSinceUpdate} minute".($minutesSinceUpdate === 1 ? '' : 's'),
            'stalled' => $stalled,
            'warning' => $stalled ? 'This run may be stalled. Review the latest output or cancel and retry if it does not recover soon.' : null,
        ];
    }
}
