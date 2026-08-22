<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessBackgroundTasks;
use App\Modules\Serverless\Services\ServerlessFunctionDnsProvisioner;
use App\Modules\Serverless\Services\SiteWorkerRegistry;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

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
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use WithPagination;

    /** History rows per page — matches Schedule, the other half of this pair. */
    private const TICKS_PER_PAGE = 15;

    /** Restart policies a worker definition may declare. */
    public const RESTART_POLICIES = SiteWorkerRegistry::RESTART_POLICIES;

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
        $this->queue_worker_enabled = $this->tasks()->enabled($site, 'queue');
        $this->workers = $this->registry()->all($site);
    }

    /** Reads and writes of worker state are shared with the HTTP API. */
    private function registry(): SiteWorkerRegistry
    {
        return app(SiteWorkerRegistry::class);
    }

    /** The queue-engine toggle — shared with Schedule's scheduler toggle. */
    private function tasks(): ServerlessBackgroundTasks
    {
        return app(ServerlessBackgroundTasks::class);
    }

    /**
     * Persist the queue-worker toggle. Fires automatically whenever the bound
     * switch changes — the new state is `$this->queue_worker_enabled`.
     */
    public function updatedQueueWorkerEnabled(bool $value): void
    {
        Gate::authorize('update', $this->site);

        $this->tasks()->setEnabled($this->site, 'queue', $value);

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

        $attributes = [
            'name' => $this->workerName,
            'command' => $this->workerCommand,
            'concurrency' => $this->workerConcurrency,
            'restart_policy' => $this->workerRestartPolicy,
        ];

        if ($this->editingWorkerId !== null) {
            $this->registry()->update($this->site, $this->editingWorkerId, $attributes);
            $message = __('Worker ":name" updated.', ['name' => $this->workerName]);
        } else {
            $this->registry()->add($this->site, $attributes);
            $message = __('Worker ":name" added.', ['name' => $this->workerName]);
        }

        $this->workers = $this->registry()->all($this->site);
        $this->resetWorkerForm();
        $this->showWorkerForm = false;
        $this->toastSuccess($message);
    }

    public function deleteWorker(string $id): void
    {
        Gate::authorize('update', $this->site);

        $this->registry()->remove($this->site, $id);
        $this->workers = $this->registry()->all($this->site);
        $this->toastSuccess(__('Worker removed.'));
    }

    /** Flip a worker's desired-running flag. */
    public function toggleWorker(string $id): void
    {
        Gate::authorize('update', $this->site);

        $worker = collect($this->workers)->firstWhere('id', $id);

        if ($worker !== null) {
            $this->registry()->update($this->site, $id, ['enabled' => ! ($worker['enabled'] ?? false)]);
            $this->workers = $this->registry()->all($this->site);
        }
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

    /**
     * Open the detail modal for one history entry. Resolved fresh by its `at`
     * timestamp (unique per task — one tick per minute) so the 15s polling
     * refresh can't desync a stored index.
     */
    public function showTick(string $at): void
    {
        // Resolved by timestamp against the table, not by scanning the page
        // being shown — the row may live on any page of the history.
        $this->selectedTick = $this->ticksQuery()
            ->where('created_at', Carbon::parse($at))
            ->first()
            ?->toTickEntry();
    }

    /**
     * Every `queue` tick for this site, newest first.
     *
     * @return Builder<FunctionInvocation>
     */
    private function ticksQuery(): Builder
    {
        return FunctionInvocation::query()
            ->where('site_id', $this->site->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->where('task', 'queue')
            ->orderByDesc('created_at');
    }

    /** One page of history, in the tick-entry array shape the view consumes. */
    private function tickHistory(): LengthAwarePaginator
    {
        return $this->ticksQuery()
            ->paginate(self::TICKS_PER_PAGE, ['*'], 'tickPage')
            ->through(fn (FunctionInvocation $invocation): array => $invocation->toTickEntry());
    }

    public function closeTick(): void
    {
        $this->selectedTick = null;
    }

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();

        $this->site->refresh();
        // Re-read on every render (the page polls every 15s) so a change made
        // out of band — `dply serverless workers …`, the HTTP API — shows up
        // without a reload.
        $this->queue_worker_enabled = $this->tasks()->enabled($this->site, 'queue');
        $this->workers = $this->registry()->all($this->site);
        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        // Workers cares only about queue-task ticks; the Schedule page shows
        // the scheduler half. Each ServerlessTickCommand pass records one row
        // per task type.
        $queueHistory = $this->tickHistory();

        // The status strip and the worker rows describe the newest tick, which
        // only lives on page 1 — read it from the query, not the page shown.
        $latestQueue = $queueHistory->currentPage() === 1
            ? $queueHistory->first()
            : $this->ticksQuery()->first()?->toTickEntry();
        $lastQueueStatus = is_array($latestQueue) ? ($latestQueue['status'] ?? null) : null;

        // Decorate each worker with its derived live status for the table.
        $workerRows = array_map(function (array $worker) use ($lastQueueStatus): array {
            [$state, $label] = $this->registry()->status($worker, $this->queue_worker_enabled, $lastQueueStatus);

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
            'latestTick' => $latestQueue,
            'lastTickAt' => $latestQueue['at'] ?? null,
            'secretMismatchDetected' => $this->detectSecretMismatch($latestQueue),
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
