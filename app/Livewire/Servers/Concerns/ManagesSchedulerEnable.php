<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\PreflightSchedulerOnSite;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSchedulerEnable
{


    public function openEnableSchedulerModal(): void
    {
        $this->resetValidation();
        $this->dispatch('open-modal', self::ENABLE_MODAL);
    }

    public function updatedEnableSiteId(): void
    {
        $this->syncEnableFormToSiteFramework();
    }

    protected function syncEnableFormToSiteFramework(): void
    {
        $site = $this->resolveEnableTargetSite();
        if ($site === null) {
            return;
        }

        if ($site->isLaravelFrameworkDetected()) {
            $this->enable_framework = 'laravel';
            $this->enable_custom_command = '';

            return;
        }

        if ($site->isRailsFrameworkDetected()) {
            $this->enable_framework = 'rails';
            $this->enable_custom_command = '';

            return;
        }

        $this->enable_framework = '';
        if (trim($this->enable_custom_command) === '') {
            $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
            $this->enable_custom_command = 'cd '.$directory.' && ';
        }
    }

    protected function resolveEnableTargetSite(): ?Site
    {
        $siteId = $this->context_site_id ?: ($this->enable_site_id !== '' ? $this->enable_site_id : null);
        if ($siteId === null) {
            return null;
        }

        return Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($siteId)
            ->first();
    }

    protected function resolveEnableSchedulerKind(Site $site): string
    {
        if ($site->isLaravelFrameworkDetected()) {
            return ServerSchedulerHeartbeat::KIND_LARAVEL;
        }

        if ($site->isRailsFrameworkDetected()) {
            return ServerSchedulerHeartbeat::KIND_RAILS;
        }

        return ServerSchedulerHeartbeat::KIND_GENERIC;
    }

    protected function resolveBareSchedulerCommand(Site $site, string $kind): ?string
    {
        $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';

        return match ($kind) {
            ServerSchedulerHeartbeat::KIND_LARAVEL => 'cd '.$directory.' && php artisan schedule:run',
            ServerSchedulerHeartbeat::KIND_RAILS => 'cd '.$directory.' && bundle exec whenever --update-crontab',
            ServerSchedulerHeartbeat::KIND_GENERIC => ($command = trim($this->enable_custom_command)) !== '' ? $command : null,
            default => null,
        };
    }

    protected function schedulerKindLabel(string $kind): string
    {
        return match ($kind) {
            ServerSchedulerHeartbeat::KIND_LARAVEL => 'Laravel',
            ServerSchedulerHeartbeat::KIND_RAILS => 'Rails',
            default => 'Custom',
        };
    }

    public function enableSchedulerForSite(PreflightSchedulerOnSite $preflight, CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);
        $this->preflight_results = [];

        // In site context the target is always the focused site — the modal shows
        // it as a fixed label, so pin it here too rather than trusting form input.
        if ($this->context_site_id !== null) {
            $this->enable_site_id = $this->context_site_id;
        }

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->enable_site_id)
            ->first();
        if ($site === null) {
            $this->toastError(__('Pick a site.'));

            return;
        }

        $cron = trim($this->enable_cron_expression);
        if (! $cronValidator->isValid($cron) || strlen($cron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        // Q18: preflight in one SSH round-trip. Block on structural failures;
        // warn-and-allow on advisory ones. Checks vary by scheduler kind.
        $kind = $this->resolveEnableSchedulerKind($site);
        $results = $preflight->run($this->server, $site, $kind);
        $this->preflight_results = $results;

        $failures = $preflight->structuralFailures($results, $kind);
        if ($results === [] || $failures !== []) {
            // Keep the modal open; $preflight_results renders inside it.
            $this->toastError($results === []
                ? __('Preflight could not run over SSH — the structured results below tell you why.')
                : __('Preflight blocked Enable — fix the structural issues below before retrying.'));

            return;
        }

        $bareCommand = $this->resolveBareSchedulerCommand($site, $kind);
        if ($bareCommand === null) {
            $this->toastError($kind === ServerSchedulerHeartbeat::KIND_GENERIC
                ? __('Enter a scheduler command.')
                : __('Could not build scheduler command for this site.'));

            return;
        }

        $wrappedCommand = sprintf(
            '/usr/local/bin/dply-scheduler-tick %s %s -- %s',
            escapeshellarg($site->id),
            escapeshellarg($kind),
            $bareCommand,
        );

        $cronJob = ServerCronJob::create([
            'server_id' => $this->server->id,
            'site_id' => $site->id,
            'cron_expression' => $cron,
            'command' => $wrappedCommand,
            'user' => $site->effectiveSystemUser($this->server),
            'enabled' => true,
            'description' => $this->schedulerKindLabel($kind).' scheduler — '.$site->name.' (wrapper-managed)',
        ]);

        // Pre-create the heartbeat row in waiting-for-first-tick state (Q4 (e))
        // so the page flips immediately to the "Waiting…" chip rather than
        // showing nothing until the first agent push lands. The agent's push
        // will upsert this row on the very next minute.
        ServerSchedulerHeartbeat::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $site->id,
            'scheduler_kind' => $kind,
            'cron_expression' => $cron,
            'last_tick_at' => null,
            'consecutive_misses' => 0,
            'first_seen_at' => now(),
            'circuit_open' => false,
            'output_capture_enabled' => true,
        ]);

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.enabled',
            $this->server,
            null,
            [
                'cron_job_id' => (string) $cronJob->id,
                'site_id' => $site->id,
                'scheduler_kind' => $kind,
                'cron_expression' => $cron,
                'advisory_warnings' => count($preflight->advisoryWarnings($results)),
            ],
        );

        $this->reset(['enable_site_id', 'enable_framework', 'enable_custom_command']);
        $this->enable_cron_expression = '* * * * *';
        $this->schedule_workspace_tab = 'schedulers';
        $this->dispatch('close-modal', self::ENABLE_MODAL);
        $this->emitPanelEvent(
            __('Scheduler enabled for :site.', ['site' => $site->name]),
            [__('Waiting for the first tick — the chip will turn green within ~60-90 seconds.')],
            'completed',
        );
    }
}
