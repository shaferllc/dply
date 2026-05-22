<?php

namespace App\Livewire\Sites;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Serverless\FunctionStatsRangeQuery;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Services\Sites\UptimeProbeRegionResolver;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Monitor extends Component
{
    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use DispatchesToastNotifications;

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    public Server $server;

    public Site $site;

    public string $newLabel = '';

    public string $newPath = '';

    public string $newProbeRegion = 'eu-amsterdam';

    /** Function-activity chart window: 1h | 24h | 7d (serverless only). */
    #[Url]
    public string $statsRange = '24h';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site->load('uptimeMonitors');

        // Default the probe region to the one nearest the host, not EU.
        $this->newProbeRegion = app(UptimeProbeRegionResolver::class)->forSite($this->site);

        if (! FunctionStatsRangeQuery::isValidRange($this->statsRange)) {
            $this->statsRange = FunctionStatsRangeQuery::defaultRange();
        }

        $this->ensureFunctionUptimeMonitor();
    }

    /**
     * Backfill the default "Homepage check" monitor for a function that has
     * none — functions created before AppServiceProvider's Site::created
     * hook never got one. New sites still get theirs at creation; this just
     * catches the older ones so their Monitor page isn't empty. Idempotent.
     */
    private function ensureFunctionUptimeMonitor(): void
    {
        if (! $this->site->usesFunctionsRuntime() || $this->site->uptimeMonitors->isNotEmpty()) {
            return;
        }

        if (array_keys(config('site_uptime.probe_regions', [])) === []) {
            return;
        }

        $monitor = SiteUptimeMonitor::query()->firstOrCreate(
            ['site_id' => $this->site->id, 'sort_order' => 0],
            [
                'label' => __('Homepage check'),
                'path' => null,
                'probe_region' => app(UptimeProbeRegionResolver::class)->forSite($this->site),
            ],
        );

        $this->site->load('uptimeMonitors');

        if ($monitor->wasRecentlyCreated) {
            RunSiteUptimeMonitorCheckJob::dispatchWithConsoleAction($this->site, $monitor, auth()->id());
        }
    }

    public function setStatsRange(string $range): void
    {
        $this->statsRange = FunctionStatsRangeQuery::isValidRange($range)
            ? $range
            : FunctionStatsRangeQuery::defaultRange();
    }

    /** Re-renders, which re-queries the function-activity series. */
    public function refreshStats(): void {}

    public function addMonitor(SiteUptimeCheckUrlResolver $resolver): void
    {
        Gate::authorize('update', $this->site);

        $regions = array_keys(config('site_uptime.probe_regions', []));

        $this->validate([
            'newLabel' => 'required|string|max:120',
            'newPath' => 'nullable|string|max:2048',
            'newProbeRegion' => ['required', 'string', Rule::in($regions)],
        ]);

        $path = $this->normalizePathInput($this->newPath);
        if ($resolver->resolveBaseUrl($this->site) === null) {
            $this->addError('newLabel', __('Add a primary domain, preview hostname, or publication URL before creating monitors.'));

            return;
        }

        $maxOrder = (int) $this->site->uptimeMonitors()->max('sort_order');

        $created = SiteUptimeMonitor::query()->create([
            'site_id' => $this->site->id,
            'label' => $this->newLabel,
            'path' => $path,
            'probe_region' => $this->newProbeRegion,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->reset('newLabel', 'newPath');
        $this->site->load('uptimeMonitors');
        $this->dispatch('close-modal', 'add-uptime-monitor-modal');
        $this->toastSuccess(__('Monitor added.'));

        RunSiteUptimeMonitorCheckJob::dispatchWithConsoleAction($this->site, $created, auth()->id());
    }

    public function runCheckNow(string $monitorId): void
    {
        Gate::authorize('update', $this->site);

        $monitor = SiteUptimeMonitor::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($monitorId);

        RunSiteUptimeMonitorCheckJob::dispatchWithConsoleAction($this->site, $monitor, auth()->id());

        $this->site->load('uptimeMonitors');
    }

    public function confirmRemoveMonitor(string $monitorId): void
    {
        Gate::authorize('update', $this->site);

        $monitor = SiteUptimeMonitor::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($monitorId);

        $this->openConfirmActionModal(
            'removeMonitor',
            [$monitor->id],
            __('Remove monitor'),
            __('Remove :label from this site? Status pages that reference it will drop this component.', ['label' => $monitor->label]),
            __('Remove'),
            true,
        );
    }

    public function removeMonitor(string $monitorId): void
    {
        Gate::authorize('update', $this->site);

        SiteUptimeMonitor::query()
            ->where('site_id', $this->site->id)
            ->whereKey($monitorId)
            ->delete();

        $this->site->load('uptimeMonitors');
        $this->toastSuccess(__('Monitor removed.'));
    }

    public function render(SiteUptimeCheckUrlResolver $resolver): View
    {
        $probeRegions = config('site_uptime.probe_regions', []);
        $baseUrl = $resolver->resolveBaseUrl($this->site);
        $hostnameDisplay = $baseUrl !== null ? (parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl) : null;

        $settingsSidebarItems = SiteSettingsSidebar::items($this->site, $this->server);
        $runtimeMode = $this->site->runtimeTargetMode();
        $resourceNoun = $runtimeMode === 'vm' ? __('Site') : __('App');
        $resourcePlural = $runtimeMode === 'vm' ? __('sites') : __('apps');
        $routingTab = 'domains';
        $laravel_tab = 'commands';
        $section = 'monitor';
        $runtimeTarget = $this->site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];

        // Function-activity dashboard — serverless functions only.
        $functionStats = $this->site->usesFunctionsRuntime()
            ? app(FunctionStatsRangeQuery::class)->forSite($this->site, $this->statsRange)
            : null;

        return view('livewire.sites.monitor', [
            'probeRegions' => is_array($probeRegions) ? $probeRegions : [],
            'resolvedBaseUrl' => $baseUrl,
            'hostnameDisplay' => $hostnameDisplay,
            'settingsSidebarItems' => $settingsSidebarItems,
            'resourceNoun' => $resourceNoun,
            'resourcePlural' => $resourcePlural,
            'routingTab' => $routingTab,
            'laravel_tab' => $laravel_tab,
            'section' => $section,
            'runtimePublication' => $runtimePublication,
            'runtimeMode' => $runtimeMode,
            'functionStats' => $functionStats,
        ]);
    }

    private function normalizePathInput(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return null;
        }

        $normalized = '/'.ltrim($trimmed, '/');

        return strlen($normalized) > 2048 ? substr($normalized, 0, 2048) : $normalized;
    }
}
