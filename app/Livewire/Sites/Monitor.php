<?php

namespace App\Livewire\Sites;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Monitor extends Component
{
    use ConfirmsActionWithModal;

    public Server $server;

    public Site $site;

    public string $newLabel = '';

    public string $newPath = '';

    public string $newProbeRegion = 'eu-amsterdam';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site->load('uptimeMonitors');

        $regions = array_keys(config('site_uptime.probe_regions', []));
        if ($regions !== [] && ! in_array($this->newProbeRegion, $regions, true)) {
            $this->newProbeRegion = $regions[0];
        }
    }

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
        session()->flash('success', __('Monitor added.'));

        if (config('site_uptime.enabled', true)) {
            RunSiteUptimeMonitorCheckJob::dispatch($created->id);
        }
    }

    public function runCheckNow(string $monitorId): void
    {
        Gate::authorize('update', $this->site);

        $monitor = SiteUptimeMonitor::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($monitorId);

        if (config('site_uptime.enabled', true)) {
            RunSiteUptimeMonitorCheckJob::dispatch($monitor->id);
        }

        $this->site->load('uptimeMonitors');
        session()->flash('success', __('Check queued.'));
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
        session()->flash('success', __('Monitor removed.'));
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
