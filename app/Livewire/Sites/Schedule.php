<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessBackgroundTasks;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * BACKGROUND > Schedule.
 *
 * Engine-level scheduled invocations for a container/serverless app. v1
 * surfaces a single boolean ("run the Laravel scheduler tick every minute")
 * that ServerlessTickCommand reads when invoking DigitalOcean Functions in
 * command mode. Future iterations expand this into a list of cron rules
 * (cron expression + target + timezone + retry) per the design grilling.
 */
#[Layout('layouts.app')]
class Schedule extends Component
{
    use DispatchesToastNotifications;
    use WithPagination;

    /** History rows per page. A dense table in a workspace card, not a feed. */
    private const TICKS_PER_PAGE = 15;

    public Server $server;

    public Site $site;

    public bool $scheduler_enabled = false;

    /**
     * The history entry currently expanded in the detail modal — the full
     * row data (response body, error, timing). Null when the modal is closed.
     *
     * @var array<string, mixed>|null
     */
    public ?array $selectedTick = null;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->scheduler_enabled = $this->tasks()->enabled($site, 'schedule');
    }

    /** The scheduler toggle — shared with the Workers page's queue engine. */
    private function tasks(): ServerlessBackgroundTasks
    {
        return app(ServerlessBackgroundTasks::class);
    }

    /**
     * Persist the scheduler toggle. Fires automatically whenever the bound
     * switch changes — the new state is `$this->scheduler_enabled`, so this
     * sets that value rather than blind-flipping the stored one.
     */
    public function updatedSchedulerEnabled(bool $value): void
    {
        Gate::authorize('update', $this->site);

        $this->tasks()->setEnabled($this->site, 'schedule', $value);

        $this->toastSuccess($value
            ? __('Scheduler enabled — dply ticks the function every minute.')
            : __('Scheduler disabled.'));
    }

    /**
     * Fire a single scheduler ping immediately. Useful when the Laravel
     * scheduler isn't running locally — operators can verify the function
     * is reachable without setting up `php artisan schedule:work`.
     */
    public function tickNow(InvokeFunctionTick $tick): void
    {
        Gate::authorize('update', $this->site);

        $entry = $tick->tickSite($this->site->fresh(), 'schedule');

        if ($entry === null) {
            $this->toastError(__('Cannot tick — the function has no webhook secret set yet. Deploy the function first.'));

            return;
        }

        $http = $entry->status_code ?? '—';
        $this->toastSuccess($entry->success
            ? __('Scheduler tick fired — HTTP :status, :ms ms.', ['status' => $http, 'ms' => $entry->duration_ms])
            : __('Scheduler tick fired but reported a failure — HTTP :status. Check the history below.', ['status' => $http]));
    }

    /**
     * Trigger a manual deploy. Surfaced from the "secret mismatch" banner —
     * after redeploying, the deployed function carries the current
     * webhook_secret as `DPLY_COMMAND_SECRET` so subsequent ticks succeed.
     */
    public function redeployToRefreshSecret(): void
    {
        Gate::authorize('update', $this->site);

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess(__('Redeploy queued. Once it completes, the function holds the current secret and Tick now will succeed.'));
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
     * Every `schedule` tick for this site, newest first.
     *
     * @return Builder<FunctionInvocation>
     */
    private function ticksQuery(): Builder
    {
        return FunctionInvocation::query()
            ->where('site_id', $this->site->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->where('task', 'schedule')
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

        // The Schedule page only cares about scheduler-task ticks. Queue ticks
        // appear on Workers; keep the two views' histories independent.
        $scheduleHistory = $this->tickHistory();
        // The banner + "latest output" panel describe the newest tick, which
        // is only on page 1 — read it from the query, not from the page shown.
        $latest = $scheduleHistory->currentPage() === 1
            ? $scheduleHistory->first()
            : $this->ticksQuery()->first()?->toTickEntry();

        return view('livewire.sites.schedule', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'schedule',
            'scheduleHistory' => $scheduleHistory,
            'latestTick' => $latest,
            'lastTickAt' => $latest['at'] ?? null,
            // Auto-detect the "stale secret" symptom in the most recent tick so
            // the page can surface a specific remedy (redeploy) rather than
            // making the operator parse the function's error body themselves.
            'secretMismatchDetected' => $this->detectSecretMismatch($latest),
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
