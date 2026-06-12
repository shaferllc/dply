<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Enums\SiteType;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\MeasureSiteDiskUsageJob;
use App\Jobs\ResetSiteOpcacheJob;
use App\Models\Site;
use App\Services\Sites\SiteAppServerProbe;
use App\Services\Sites\SiteOpcacheManager;
use App\Services\Sites\SitePhpFpmProbe;
use App\Support\SiteSettingsSidebar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteRuntimeHealth
{
    /**
     * Live runtime health for the Runtime → Overview card, keyed by 'kind':
     *   - 'fpm':  dedicated PHP-FPM pool {running, socket_present, conf_present, workers, max_children, php_version, pool}
     *   - 'port': long-running app server {listening, port}
     * Loaded deferred via {@see loadRuntimeHealth()} (wire:init) so the SSH probe
     * stays off the render path; null until the probe runs / when there's nothing
     * to probe / when the probe couldn't reach the server.
     *
     * @var array<string, mixed>|null
     */
    public ?array $runtimeHealth = null;

    /** Whether {@see loadRuntimeHealth()} has run yet (drives the "checking…" state). */
    public bool $runtimeHealthLoaded = false;

    /**
     * Live OPcache status for the Runtime → Overview card, read from the FPM
     * worker (not CLI) via {@see SiteOpcacheManager}. Loaded deferred by
     * {@see loadOpcacheStatus()} (wire:init); null until probed / when the site
     * has no dedicated pool / when FPM couldn't be reached.
     *
     * @var array<string, mixed>|null
     */
    public ?array $opcacheStatus = null;

    /** Whether {@see loadOpcacheStatus()} has run yet (drives the "checking…" state). */
    public bool $opcacheStatusLoaded = false;

    /** Worker mode: serve a locked-down "runs workers" page instead of the app. */
    public bool $worker_mode = false;

    /**
     * Whether the worker-mode toggle is offered for this site. It's a VM
     * webserver concern only — container/serverless/edge sites don't serve from
     * a Caddy vhost, and headless (webserver=none) sites have no web front at
     * all. Worker hosts default the toggle ON; any VM site can opt in.
     */
    #[Computed]
    public function canConfigureWorkerMode(): bool
    {
        return $this->server->isVmHost()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime()
            && ! $this->site->usesDockerRuntime()
            && ! $this->site->usesKubernetesRuntime()
            && $this->site->webserver() !== 'none';
    }

    /**
     * Persist the worker-mode override on the site and re-apply the webserver
     * config so Caddy switches between the normal vhost and the locked-down
     * worker page. Setting it to the host-role default clears the override so
     * the site tracks its host again.
     */
    public function saveWorkerMode(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->canConfigureWorkerMode()) {
            $this->toastError(__('Worker mode applies to VM sites served by a web server.'));

            return;
        }

        $this->validate([
            'worker_page_html' => 'nullable|string|max:100000',
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        // Clear the override when the choice matches the host-role default so the
        // site keeps following its host; otherwise pin the explicit value.
        $hostDefault = $this->server->isWorkerHost();
        if ($this->worker_mode === $hostDefault) {
            unset($meta['worker_mode']);
        } else {
            $meta['worker_mode'] = $this->worker_mode;
        }

        // Custom worker page: store when non-empty, clear to fall back to the
        // built-in dply page.
        $customHtml = trim($this->worker_page_html);
        if ($customHtml === '') {
            unset($meta['worker_page_html']);
        } else {
            $meta['worker_page_html'] = $customHtml;
        }

        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();

        ApplySiteWebserverConfigJob::dispatch(
            (string) $this->site->id,
            (string) auth()->id(),
        );

        $org = $this->site->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.worker_mode.updated', $this->site, null, [
                'worker_mode' => $this->worker_mode,
            ]);
        }

        $this->toastSuccess($this->worker_mode
            ? __('Worker mode on — re-applying the web server to lock the site down.')
            : __('Worker mode off — re-applying the web server to restore the site.'));
    }

    private function runtimeHealthCacheKey(): string
    {
        return 'dply.site-runtime-health:'.$this->site->id;
    }

    /**
     * Deferred (wire:init) loader for the Runtime → Overview live-health card.
     * Kept OFF render() so renders + console wire:polls never block on SSH; the
     * probe is one inline-bash roundtrip, cached briefly so rapid re-entries
     * (tab nav, double fire) coalesce. The probe kind (FPM pool vs. app-server
     * port) is decided by {@see Site::runtimeHealthProbeKind()} so the loader and
     * the card always agree; no-ops to a "loaded but empty" state when there's
     * nothing to probe.
     */
    public function loadRuntimeHealth(SitePhpFpmProbe $fpmProbe, SiteAppServerProbe $portProbe): void
    {
        $this->runtimeHealthLoaded = true;
        $this->runtimeHealth = null;

        $kind = $this->site->runtimeHealthProbeKind();
        if ($kind === null || ! $this->server->hostCapabilities()->supportsSsh()) {
            return;
        }
        if ($kind === 'fpm' && ! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            return;
        }

        try {
            $this->runtimeHealth = Cache::remember(
                $this->runtimeHealthCacheKey(),
                15,
                function () use ($kind, $fpmProbe, $portProbe): ?array {
                    $result = $kind === 'fpm'
                        ? $fpmProbe->probe($this->site)
                        : $portProbe->probe($this->site);

                    return $result === null ? null : ['kind' => $kind] + $result;
                },
            );
        } catch (\Throwable) {
            $this->runtimeHealth = null;
        }
    }

    /** Force-refresh the Overview live-health card (button on the card). */
    public function refreshRuntimeHealth(SitePhpFpmProbe $fpmProbe, SiteAppServerProbe $portProbe): void
    {
        Cache::forget($this->runtimeHealthCacheKey());
        $this->runtimeHealthLoaded = false;
        $this->loadRuntimeHealth($fpmProbe, $portProbe);
    }

    /**
     * Re-apply this site's webserver config, which rewrites the dedicated pool
     * conf and reloads php-fpm (idempotent — only changed files reload). Surfaced
     * as a "Reload pool" button on the Overview FPM card. Queued + watched via
     * the console banner, never inline {@see [[feedback_queue_ssh_operations]]}.
     */
    public function reloadFpmPool(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->site->usesDedicatedPhpFpmPool() || ! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This site has no dedicated PHP-FPM pool to reload.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('webserver_config', __('Reloading PHP-FPM pool'));
        ApplySiteWebserverConfigJob::dispatch(
            (string) $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        // The reload churns live worker state, so drop the cached probe — the
        // next card render (or Refresh) re-reads it.
        Cache::forget($this->runtimeHealthCacheKey());
        $this->runtimeHealthLoaded = false;

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('PHP-FPM pool reloaded.'),
            __('PHP-FPM pool reload failed.'),
        );
        $this->toastConsoleActionQueued();
    }

    private function opcacheStatusCacheKey(): string
    {
        return 'dply.site-opcache:'.$this->site->id;
    }

    /**
     * Deferred (wire:init) loader for the Overview OPcache card. Reads the live
     * FPM cache via {@see SiteOpcacheManager} (FastCGI to the pool socket) off
     * the render path; cached briefly so re-entries coalesce.
     */
    public function loadOpcacheStatus(SiteOpcacheManager $opcache): void
    {
        $this->opcacheStatusLoaded = true;
        $this->opcacheStatus = null;

        if (! $this->site->usesDedicatedPhpFpmPool() || ! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            return;
        }

        try {
            $this->opcacheStatus = Cache::remember(
                $this->opcacheStatusCacheKey(),
                15,
                fn () => $opcache->status($this->site),
            );
        } catch (\Throwable) {
            $this->opcacheStatus = null;
        }
    }

    /** Force-refresh the OPcache card (button on the card). */
    public function refreshOpcacheStatus(SiteOpcacheManager $opcache): void
    {
        Cache::forget($this->opcacheStatusCacheKey());
        $this->opcacheStatusLoaded = false;
        $this->loadOpcacheStatus($opcache);
    }

    /**
     * Flush this site's FPM OPcache. Queued + watched via the console banner so
     * the SSH work stays off the request {@see [[feedback_queue_ssh_operations]]}.
     */
    public function resetOpcache(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->site->usesDedicatedPhpFpmPool() || ! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This site has no dedicated PHP-FPM pool whose OPcache can be flushed.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('opcache_reset', __('Flushing OPcache'));
        ResetSiteOpcacheJob::dispatch(
            (string) $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        // Stats change the moment it flushes — drop the cache so the card re-reads.
        Cache::forget($this->opcacheStatusCacheKey());
        $this->opcacheStatusLoaded = false;

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('OPcache flushed.'),
            __('OPcache flush failed.'),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * Measure this site's on-disk footprint over SSH and stash it on
     * meta.disk_usage so the Site details card shows a real size. VM sites only —
     * container/edge/serverless apps have no SSH box to `du`.
     */
    public function measureDiskUsage(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->canMeasureDiskUsage()) {
            $this->toastError(__('Disk usage can only be measured for sites hosted on a VM.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('disk_usage_measure', __('Measuring disk usage'));
        MeasureSiteDiskUsageJob::dispatch(
            (string) $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        // Re-read meta on the next render so the new value lands the moment the job finishes.
        unset($this->diskUsage);

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Disk usage updated.'),
            __('Disk usage measurement failed.'),
        );
        $this->toastConsoleActionQueued();
    }

    /**
     * VM-hosted sites have a real filesystem to measure; container/edge/serverless
     * apps run off-box and have no deploy path to `du`.
     */
    #[Computed]
    public function canMeasureDiskUsage(): bool
    {
        return $this->server->isVmHost()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime()
            && ! $this->site->usesDockerRuntime()
            && ! $this->site->usesKubernetesRuntime();
    }

    /**
     * Fresh disk-usage snapshot, re-queried (not read off the hydrated model) so
     * the value appears the moment {@see MeasureSiteDiskUsageJob} writes it,
     * without a full model refresh. Memoized per render.
     *
     * @return array{bytes:int, files?:int, volume_total_bytes?:int, volume_used_bytes?:int, volume_available_bytes?:int, path?:string, measured_at?:string}|null
     */
    #[Computed]
    public function diskUsage(): ?array
    {
        $usage = data_get(
            Site::query()->select(['id', 'meta'])->find($this->site->id)?->meta,
            'disk_usage'
        );

        return is_array($usage) && isset($usage['bytes']) ? $usage : null;
    }

    public function saveRuntimePreferences(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Runtime preferences apply to VM and container sites. Use Deploy for function and serverless targets.'));

            return;
        }

        $rules = [];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $rules['laravel_scheduler'] = 'boolean';
            if (! $this->shouldShowSystemUserPanel()) {
                $rules['php_fpm_user'] = 'nullable|string|max:64';
            }
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $rules['octane_port'] = 'nullable|integer|min:1|max:65535';
            $rules['octane_server'] = ['required', Rule::in(Site::OCTANE_SERVERS)];
        }

        if ($this->shouldShowRuntimeAppPortField()) {
            $rules['runtime_app_port'] = 'nullable|integer|min:1|max:65535';
        }

        if ($this->site->type === SiteType::Static) {
            $rules['settings_document_root'] = ['required', 'string', 'max:500'];
        }

        if ($this->shouldShowRailsRuntimeFields()) {
            $rules['rails_env'] = 'nullable|string|max:32';
        }

        $this->validate($rules);

        $update = [];

        if ($this->shouldShowRuntimePhpRolloutFields()) {
            $update['laravel_scheduler'] = $this->laravel_scheduler;
            if (! $this->shouldShowSystemUserPanel()) {
                $update['php_fpm_user'] = $this->php_fpm_user !== '' ? $this->php_fpm_user : null;
            }
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $update['octane_port'] = $this->octane_port !== '' ? (int) $this->octane_port : null;
        }

        if ($this->shouldShowRuntimeAppPortField()) {
            $update['app_port'] = $this->runtime_app_port !== '' ? (int) $this->runtime_app_port : null;
        }

        if ($this->site->type === SiteType::Static) {
            $update['document_root'] = trim($this->settings_document_root);
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $metaTouched = false;

        if ($this->shouldShowRailsRuntimeFields()) {
            $railsRuntime = is_array($meta['rails_runtime'] ?? null) ? $meta['rails_runtime'] : [];
            $env = trim($this->rails_env);
            $railsRuntime['env'] = $env !== '' ? $env : 'production';
            $meta['rails_runtime'] = $railsRuntime;
            $metaTouched = true;
        }

        if ($this->shouldShowRuntimePhpRolloutFields() && $this->site->shouldShowOctaneRuntimeUi()) {
            $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
            $lo['server'] = $this->octane_server;
            $meta['laravel_octane'] = $lo;
            $metaTouched = true;
        }

        if ($metaTouched) {
            $update['meta'] = $meta;
        }

        $this->site->update($update);
        $this->syncFormFromSite();
        $this->syncGeneralSettingsForm();

        $this->finalizeRoutingMutation(__('Runtime preferences saved.'));
    }

    private function shouldShowRuntimePhpRolloutFields(): bool
    {
        return $this->site->shouldShowPhpOctaneRolloutSettings();
    }

    private function shouldShowRuntimeAppPortField(): bool
    {
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->site->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $this->site->type === SiteType::Node
            || $this->site->usesDockerRuntime()
            || $this->site->usesKubernetesRuntime()
            || in_array($fw, [
                'rails',
                'nextjs',
                'nuxt',
                'node_generic',
                'vite_static',
                'django',
                'flask',
                'fastapi',
                'python_generic',
            ], true);
    }

    private function shouldShowRailsRuntimeFields(): bool
    {
        return $this->site->shouldShowRailsRuntimeSettings();
    }

    private function resolveRuntimeTabForSite(Site $site, mixed $tab): string
    {
        $allowed = array_keys(SiteSettingsSidebar::runtimeTabsFor($site));

        return is_string($tab) && in_array($tab, $allowed, true)
            ? $tab
            : 'overview';
    }
}
