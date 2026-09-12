<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\EnableSchedulerJob;
use App\Models\Site;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Support\Servers\SchedulerRecipe;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSchedulerEnable
{
    /**
     * One-click enable from a site's row. The detected stack picks the command
     * and cadence ({@see SchedulerRecipe}); a stack with no recipe opens the
     * row's "Set command…" field instead. The SSH work runs queued in
     * {@see EnableSchedulerJob}, and the page polls it like Run now.
     */
    public function enableScheduler(string $siteId): void
    {
        $this->authorize('update', $this->server);

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($siteId)
            ->first();
        if ($site === null) {
            $this->toastError(__('Site not found.'));

            return;
        }

        if ($this->scheduler_run_busy) {
            $this->toastError(__('Another scheduler change is still running — try again in a moment.'));

            return;
        }

        $custom = $this->custom_command_site_id === $site->id ? trim($this->custom_command) : '';
        if (mb_strlen($custom) > 2000) {
            $this->toastError(__('That command is too long.'));

            return;
        }

        $recipe = SchedulerRecipe::for($site);
        if ($custom === '' && $recipe === null && SchedulerCardsBuilder::schedulerEntryFor($site) === null) {
            $this->custom_command_site_id = $site->id;
            $this->custom_command = '';

            return;
        }

        unset($this->enable_failures[$site->id]);
        $runId = (string) Str::ulid();
        $this->enabling_site_id = $site->id;
        $this->scheduler_run_id = $runId;
        $this->scheduler_run_busy = true;
        $this->scheduler_run_cache_key = EnableSchedulerJob::cacheKey($runId);

        // The job has no auth context, so intent is audited here; the job
        // reports the outcome through the polled cache entry.
        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.enabled',
            $this->server,
            null,
            [
                'site_id' => $site->id,
                'recipe' => $custom !== '' ? SchedulerRecipe::KEY_CUSTOM : ($recipe?->key ?? 'existing'),
            ],
        );

        $this->custom_command_site_id = null;
        $this->custom_command = '';

        EnableSchedulerJob::dispatch(
            $this->server->id,
            $site->id,
            $runId,
            $custom !== '' ? $custom : null,
            (string) auth()->id(),
        );

        $this->emitPanelEvent(
            __('Enabling the scheduler for :site…', ['site' => $site->name]),
            [__('Checking the app, installing the tick wrapper, writing the crontab.')],
            'running',
        );
    }

    public function cancelCustomCommand(): void
    {
        $this->custom_command_site_id = null;
        $this->custom_command = '';
    }
}
