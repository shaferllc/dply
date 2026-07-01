<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ResetSiteOpcacheJob;
use App\Models\SiteRelease;
use App\Services\Sites\SiteOpcacheManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * "Release health" for the resources page: is php-fpm actually serving the
 * release the last deploy activated, or are the workers pinned to an older one?
 *
 * The failure this surfaces is real and was invisible before — with
 * `opcache.revalidate_path` off, an atomic symlink swap doesn't re-resolve in
 * OPcache, so a plain FPM reload leaves workers booting the PRIOR release (stale
 * Vite asset hashes → unstyled site, green health check). The OPcache FastCGI
 * agent now reports which `releases/<folder>` the live workers loaded from
 * ({@see SiteOpcacheManager}); comparing it to the active {@see SiteRelease} row
 * is the drift signal. The one-click flush reuses {@see ResetSiteOpcacheJob}.
 *
 * Reuses the OPcache probe cache key so the Runtime tab and this card share a
 * single FastCGI round-trip. Requires the host component to expose `$site` +
 * `$server` and compose {@see \App\Livewire\Concerns\WatchesConsoleActionOutcomes}
 * and {@see \App\Livewire\Concerns\DispatchesToastNotifications}.
 */
trait ManagesSiteReleaseHealth
{
    /**
     * @var array{expected: ?string, serving: ?string, state: string, opcache: ?array<string, mixed>}|null
     */
    public ?array $releaseHealth = null;

    public bool $releaseHealthLoaded = false;

    private function opcacheProbeCacheKey(): string
    {
        return 'dply.site-opcache:'.$this->site->id;
    }

    /**
     * Deferred (wire:init) loader. Reads the live FPM cache off the render path
     * and compares the release the workers serve to the active release row.
     * No-op (leaves `$releaseHealth` null) for sites without a dedicated pool or
     * atomic releases — there's no symlink-pin to detect there.
     */
    public function loadReleaseHealth(SiteOpcacheManager $opcache): void
    {
        $this->releaseHealthLoaded = true;
        $this->releaseHealth = null;

        $supported = $this->site->usesDedicatedPhpFpmPool()
            && $this->site->isAtomicDeploys()
            && $this->server->hostCapabilities()->supportsMachinePhpManagement();

        if (! $supported) {
            return;
        }

        $expected = SiteRelease::query()
            ->where('site_id', $this->site->id)
            ->where('is_active', true)
            ->value('folder');

        try {
            $status = Cache::remember(
                $this->opcacheProbeCacheKey(),
                15,
                fn () => $opcache->status($this->site),
            );
        } catch (\Throwable) {
            $status = null;
        }

        $serving = is_array($status) ? ($status['serving_release'] ?? null) : null;

        // 'drifted' only when we can positively compare two known folders and
        // they differ; 'unknown' when the workers' cache is empty/just-flushed
        // (no releases/* scripts cached yet) so we can't tell — never alarm on
        // absence of evidence.
        $state = 'unknown';
        if ($expected !== null && $serving !== null) {
            $state = ((string) $expected === (string) $serving) ? 'in_sync' : 'drifted';
        }

        $this->releaseHealth = [
            'expected' => $expected !== null ? (string) $expected : null,
            'serving' => $serving !== null ? (string) $serving : null,
            'state' => $state,
            'opcache' => is_array($status) ? $status : null,
        ];
    }

    /** Force a fresh probe (Refresh button on the card). */
    public function refreshReleaseHealth(SiteOpcacheManager $opcache): void
    {
        Cache::forget($this->opcacheProbeCacheKey());
        $this->releaseHealthLoaded = false;
        $this->loadReleaseHealth($opcache);
    }

    /**
     * Flush OPcache so workers re-resolve `current` onto the active release.
     * Queued + watched via the console banner so the SSH work stays off the
     * request {@see [[feedback_queue_ssh_operations]]}.
     */
    public function flushOpcacheResync(): void
    {
        Gate::authorize('update', $this->site);

        if (! $this->site->usesDedicatedPhpFpmPool() || ! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This site has no dedicated PHP-FPM pool whose OPcache can be flushed.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('opcache_reset', __('Flushing OPcache & re-syncing release'));
        ResetSiteOpcacheJob::dispatch(
            (string) $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        Cache::forget($this->opcacheProbeCacheKey());
        $this->releaseHealthLoaded = false;

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('OPcache flushed — workers now serve the current release.'),
            __('OPcache flush failed.'),
        );
        $this->toastConsoleActionQueued();
    }
}
