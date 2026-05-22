<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Serverless\InvokeFunctionTick;
use App\Services\Serverless\ServerlessFunctionDnsProvisioner;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * BACKGROUND > Workers.
 *
 * Long-running engine processes for a container/serverless app.
 *
 * The page carries two things. The engine toggle ("process queue jobs") is
 * the actual mechanism — a single boolean tied to the same serverless tick
 * that drives Schedule. The named-workers list lets the operator describe
 * the worker processes their app runs (command/function-ref, replicas,
 * restart policy) with a live status per worker. In v1 every enabled worker
 * is driven by that one engine tick; per-worker process isolation is a
 * later release.
 */
#[Layout('layouts.app')]
class Workers extends Component
{
    use DispatchesToastNotifications;

    /** Restart policies a worker definition may declare. */
    public const RESTART_POLICIES = ['always', 'on-failure', 'never'];

    public Server $server;

    public Site $site;

    public bool $queue_worker_enabled = false;

    /**
     * The history entry currently expanded in the detail modal — the full
     * row data (response body, error, timing). Null when the modal is closed.
     *
     * @var array<string, mixed>|null
     */
    public ?array $selectedTick = null;

    /**
     * Named worker definitions for this app. Each entry: id, name, command,
     * concurrency, restart_policy, enabled. Persisted at
     * site.meta.serverless.workers.
     *
     * @var list<array<string, mixed>>
     */
    public array $workers = [];

    /** True while the add/edit worker modal is open. */
    public bool $showWorkerForm = false;

    /** The worker being edited; null means the form is adding a new one. */
    public ?string $editingWorkerId = null;

    public string $workerName = '';

    public string $workerCommand = '';

    public int $workerConcurrency = 1;

    public string $workerRestartPolicy = 'on-failure';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        // Read the dedicated `queue_worker_enabled` flag; fall back to the
        // legacy bundled `background_enabled` so sites configured before the
        // split (when one toggle drove both tasks) keep their previous state.
        $this->queue_worker_enabled = (bool) ($serverless['queue_worker_enabled'] ?? $serverless['background_enabled'] ?? false);
        $this->workers = $this->normalizeWorkers($serverless['workers'] ?? null);
    }

    /**
     * Coerce the stored worker list into a clean, fully-shaped list — drops
     * malformed entries and back-fills missing keys with safe defaults.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeWorkers(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $workers = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || trim((string) ($entry['id'] ?? '')) === '') {
                continue;
            }

            $policy = (string) ($entry['restart_policy'] ?? 'on-failure');

            $workers[] = [
                'id' => (string) $entry['id'],
                'name' => (string) ($entry['name'] ?? 'worker'),
                'command' => (string) ($entry['command'] ?? ''),
                'concurrency' => max(1, (int) ($entry['concurrency'] ?? 1)),
                'restart_policy' => in_array($policy, self::RESTART_POLICIES, true) ? $policy : 'on-failure',
                'enabled' => (bool) ($entry['enabled'] ?? false),
            ];
        }

        return $workers;
    }

    /**
     * Persist the queue-worker toggle. Fires automatically whenever the bound
     * switch changes — the new state is `$this->queue_worker_enabled`.
     */
    public function updatedQueueWorkerEnabled(bool $value): void
    {
        Gate::authorize('update', $this->site);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];

        // Write the dedicated flag; keep the legacy bundled flag in sync (true
        // iff either side is on) so callers that still read the old key see
        // the right "at least one task ticking" state.
        $serverless['queue_worker_enabled'] = $value;
        $schedulerOn = (bool) ($serverless['scheduler_enabled'] ?? $serverless['background_enabled'] ?? false);
        $serverless['background_enabled'] = $value || $schedulerOn;

        $meta['serverless'] = $serverless;
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();

        $this->toastSuccess($value
            ? __('Queue worker enabled — dply processes jobs in background ticks.')
            : __('Queue worker disabled.'));
    }

    /**
     * Fire a single queue ping immediately. Useful when the Laravel
     * scheduler isn't running locally — confirms the function is
     * reachable without depending on `php artisan schedule:work`.
     */
    public function tickNow(InvokeFunctionTick $tick): void
    {
        Gate::authorize('update', $this->site);

        $entry = $tick->tickSite($this->site->fresh(), 'queue');

        if ($entry === null) {
            $this->toastError(__('Cannot tick — the function has no webhook secret set yet. Deploy the function first.'));

            return;
        }

        $http = $entry->status_code ?? '—';
        $this->toastSuccess($entry->success
            ? __('Queue tick fired — HTTP :status, :ms ms.', ['status' => $http, 'ms' => $entry->duration_ms])
            : __('Queue tick fired but reported a failure — HTTP :status. Check the history below.', ['status' => $http]));
    }

    /**
     * Trigger a manual deploy from the "secret mismatch" banner — after the
     * redeploy completes, the function holds the current webhook_secret as
     * DPLY_COMMAND_SECRET so subsequent queue ticks succeed.
     */
    public function redeployToRefreshSecret(): void
    {
        Gate::authorize('update', $this->site);

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess(__('Redeploy queued. Once it completes, the function holds the current secret and Tick now will succeed.'));
    }

    /** Open the worker modal in "add" mode. */
    public function newWorker(): void
    {
        Gate::authorize('update', $this->site);

        $this->resetWorkerForm();
        $this->showWorkerForm = true;
    }

    /** Open the worker modal pre-filled with an existing worker's values. */
    public function editWorker(string $id): void
    {
        Gate::authorize('update', $this->site);

        $worker = collect($this->workers)->firstWhere('id', $id);
        if ($worker === null) {
            return;
        }

        $this->editingWorkerId = $id;
        $this->workerName = (string) $worker['name'];
        $this->workerCommand = (string) $worker['command'];
        $this->workerConcurrency = (int) $worker['concurrency'];
        $this->workerRestartPolicy = (string) $worker['restart_policy'];
        $this->resetValidation();
        $this->showWorkerForm = true;
    }

    public function cancelWorkerForm(): void
    {
        $this->resetWorkerForm();
        $this->showWorkerForm = false;
    }

    /**
     * Validate and persist the worker form — updating the edited worker, or
     * appending a new one (enabled by default). A new worker turning the
     * engine on is the operator's job: the engine toggle stays independent.
     */
    public function saveWorker(): void
    {
        Gate::authorize('update', $this->site);

        $this->validate([
            'workerName' => ['required', 'string', 'max:60'],
            'workerCommand' => ['required', 'string', 'max:255'],
            'workerConcurrency' => ['required', 'integer', 'min:1', 'max:50'],
            'workerRestartPolicy' => ['required', Rule::in(self::RESTART_POLICIES)],
        ]);

        if ($this->editingWorkerId !== null) {
            $this->workers = array_map(function (array $worker): array {
                if ($worker['id'] !== $this->editingWorkerId) {
                    return $worker;
                }

                return [
                    ...$worker,
                    'name' => $this->workerName,
                    'command' => $this->workerCommand,
                    'concurrency' => $this->workerConcurrency,
                    'restart_policy' => $this->workerRestartPolicy,
                ];
            }, $this->workers);
            $message = __('Worker ":name" updated.', ['name' => $this->workerName]);
        } else {
            $this->workers[] = [
                'id' => (string) Str::ulid(),
                'name' => $this->workerName,
                'command' => $this->workerCommand,
                'concurrency' => $this->workerConcurrency,
                'restart_policy' => $this->workerRestartPolicy,
                'enabled' => true,
            ];
            $message = __('Worker ":name" added.', ['name' => $this->workerName]);
        }

        $this->persistWorkers();
        $this->resetWorkerForm();
        $this->showWorkerForm = false;
        $this->toastSuccess($message);
    }

    public function deleteWorker(string $id): void
    {
        Gate::authorize('update', $this->site);

        $this->workers = array_values(array_filter(
            $this->workers,
            fn (array $worker): bool => $worker['id'] !== $id,
        ));
        $this->persistWorkers();
        $this->toastSuccess(__('Worker removed.'));
    }

    /** Flip a worker's desired-running flag. */
    public function toggleWorker(string $id): void
    {
        Gate::authorize('update', $this->site);

        $this->workers = array_map(
            fn (array $worker): array => $worker['id'] === $id
                ? [...$worker, 'enabled' => ! ($worker['enabled'] ?? false)]
                : $worker,
            $this->workers,
        );
        $this->persistWorkers();
    }

    /**
     * Re-run the DNS provisioner from the failure banner. The provisioner is
     * idempotent — it clears conflicting records and re-creates the function
     * hostname — so a retry after fixing the token/zone in DigitalOcean lands
     * the record without a redeploy.
     */
    public function provisionDnsNow(ServerlessFunctionDnsProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $result = $provisioner->provision($this->site->fresh());
        if ($result === null) {
            $this->toastError(__('Cannot provision DNS — the function has no friendly hostname yet. Deploy the function first.'));

            return;
        }

        $this->site->refresh();
        $status = (string) data_get($this->site->meta, 'serverless.dns.status', 'unknown');

        match ($status) {
            'ready' => $this->toastSuccess(__('DNS provisioned — the hostname is live.')),
            'failed' => $this->toastError(__('DNS provisioning failed again. See the banner for the latest error.')),
            default => $this->toastSuccess(__('DNS provisioner ran — status: :status.', ['status' => $status])),
        };
    }

    private function resetWorkerForm(): void
    {
        $this->editingWorkerId = null;
        $this->workerName = '';
        $this->workerCommand = '';
        $this->workerConcurrency = 1;
        $this->workerRestartPolicy = 'on-failure';
        $this->resetValidation();
    }

    /** Write the worker list back to the site's serverless meta. */
    private function persistWorkers(): void
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['workers'] = array_values($this->workers);
        $meta['serverless'] = $serverless;

        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
    }

    /**
     * Resolve a worker's live status. v1 has no per-worker process, so the
     * status is derived: a disabled worker is Stopped; an enabled worker with
     * the engine off is idle; otherwise it mirrors the most recent queue tick.
     *
     * @param  array<string, mixed>  $worker
     * @return array{0: string, 1: string}
     */
    private function workerStatus(array $worker, bool $engineOn, ?string $lastTickStatus): array
    {
        if (! ($worker['enabled'] ?? false)) {
            return ['stopped', __('Stopped')];
        }

        if (! $engineOn) {
            return ['idle', __('Engine off')];
        }

        return match ($lastTickStatus) {
            'ok' => ['running', __('Running')],
            'failed' => ['erroring', __('Erroring')],
            default => ['pending', __('Pending')],
        };
    }

    /**
     * Open the detail modal for one history entry. Resolved fresh by its `at`
     * timestamp (unique per task — one tick per minute) so the 15s polling
     * refresh can't desync a stored index.
     */
    public function showTick(string $at): void
    {
        $this->selectedTick = $this->tickHistory()
            ->first(fn (array $entry): bool => (string) ($entry['at'] ?? '') === $at);
    }

    /**
     * The site's recent `queue` ticks, newest-first, in the legacy
     * tick-history array shape the view consumes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function tickHistory(): Collection
    {
        return FunctionInvocation::query()
            ->where('site_id', $this->site->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->where('task', 'queue')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (FunctionInvocation $invocation): array => $invocation->toTickEntry());
    }

    public function closeTick(): void
    {
        $this->selectedTick = null;
    }

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();

        $this->site->refresh();
        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        // Workers cares only about queue-task ticks; the Schedule page shows
        // the scheduler half. Each ServerlessTickCommand pass records one row
        // per task type.
        $queueHistory = $this->tickHistory();

        $latestQueue = $queueHistory->first();
        $lastQueueStatus = is_array($latestQueue) ? ($latestQueue['status'] ?? null) : null;

        // Decorate each worker with its derived live status for the table.
        $workerRows = array_map(function (array $worker) use ($lastQueueStatus): array {
            [$state, $label] = $this->workerStatus($worker, $this->queue_worker_enabled, $lastQueueStatus);

            return [...$worker, 'status' => $state, 'status_label' => $label];
        }, $this->workers);

        return view('livewire.sites.workers', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'workers',
            'queueHistory' => $queueHistory,
            'lastTickAt' => $queueHistory->first()['at'] ?? null,
            'secretMismatchDetected' => $this->detectSecretMismatch($queueHistory->first()),
            'dns' => is_array($serverless['dns'] ?? null) ? $serverless['dns'] : [],
            'workerRows' => $workerRows,
            'restartPolicies' => self::RESTART_POLICIES,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private function detectSecretMismatch(?array $latest): bool
    {
        if ($latest === null) {
            return false;
        }
        $body = (string) ($latest['body_preview'] ?? '');

        return stripos($body, 'invalid command secret') !== false
            || stripos($body, 'DPLY_COMMAND_SECRET') !== false;
    }
}
