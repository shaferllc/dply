<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Serverless\InvokeFunctionTick;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

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
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        // Read the dedicated `scheduler_enabled` flag; fall back to the legacy
        // bundled `background_enabled` so sites configured before the split
        // continue to honor the operator's previous choice.
        $this->scheduler_enabled = (bool) ($serverless['scheduler_enabled'] ?? $serverless['background_enabled'] ?? false);
    }

    /**
     * Persist the scheduler toggle. Fires automatically whenever the bound
     * switch changes — the new state is `$this->scheduler_enabled`, so this
     * sets that value rather than blind-flipping the stored one.
     */
    public function updatedSchedulerEnabled(bool $value): void
    {
        Gate::authorize('update', $this->site);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];

        // Write the new dedicated flag. Keep the legacy `background_enabled`
        // in sync (true iff either side is on) so any caller that still reads
        // the old bundled flag sees the correct "at least one task ticking"
        // state. Drop the legacy fallback once nothing reads it.
        $serverless['scheduler_enabled'] = $value;
        $queueOn = (bool) ($serverless['queue_worker_enabled'] ?? $serverless['background_enabled'] ?? false);
        $serverless['background_enabled'] = $value || $queueOn;

        $meta['serverless'] = $serverless;
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();

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
            $this->toastError(__('Cannot tick — the function has no invocation URL or webhook secret set yet. Deploy the function first.'));

            return;
        }

        $ok = ($entry['status'] ?? '') === 'ok';
        $http = $entry['http_status'] ?? '—';
        $this->toastSuccess($ok
            ? __('Scheduler tick fired — HTTP :status, :ms ms.', ['status' => $http, 'ms' => (int) ($entry['duration_ms'] ?? 0)])
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
        $meta = $this->site->fresh()?->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $tickHistory = is_array($serverless['tick_history'] ?? null) ? $serverless['tick_history'] : [];

        $this->selectedTick = collect($tickHistory)
            ->first(fn ($entry): bool => is_array($entry)
                && ($entry['task'] ?? null) === 'schedule'
                && (string) ($entry['at'] ?? '') === $at);
    }

    public function closeTick(): void
    {
        $this->selectedTick = null;
    }

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();

        // Fresh read on every render so wire:poll picks up the tick command's
        // history writes without us re-mounting.
        $this->site->refresh();
        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        $tickHistory = is_array($serverless['tick_history'] ?? null) ? $serverless['tick_history'] : [];
        // The Schedule page only cares about scheduler-task ticks. Queue ticks
        // appear on Workers; keep the two views' histories independent.
        $scheduleHistory = collect($tickHistory)
            ->filter(fn ($entry): bool => is_array($entry) && ($entry['task'] ?? null) === 'schedule')
            ->reverse()
            ->values();

        return view('livewire.sites.schedule', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'schedule',
            'scheduleHistory' => $scheduleHistory,
            'lastTickAt' => $serverless['last_tick_at'] ?? null,
            // Auto-detect the "stale secret" symptom in the most recent tick so
            // the page can surface a specific remedy (redeploy) rather than
            // making the operator parse the function's error body themselves.
            'secretMismatchDetected' => $this->detectSecretMismatch($scheduleHistory->first()),
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
