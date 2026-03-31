<?php

namespace App\Livewire\Sites;

use App\Jobs\ApplyInsightFixJob;
use App\Jobs\RunSiteInsightsJob;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\InsightSettingsRepository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceInsights extends Component
{
    public Server $server;

    public Site $site;

    public string $tab = 'overview';

    /** @var array<string, bool> */
    public array $enabled_map = [];

    /** @var array<string, mixed> */
    public array $parameters = [];

    public bool $running = false;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;
        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $org = $this->site->organization ?? $this->server->organization;
        if (! $org instanceof Organization) {
            $this->enabled_map = [];
            $this->parameters = [];

            return;
        }

        $repo = app(InsightSettingsRepository::class);
        $setting = $repo->forSite($this->site, $org);
        $defaults = $repo->defaultEnabledMap($org);
        $this->enabled_map = array_merge($defaults, $setting->enabled_map ?? []);
        $paramDefaults = $repo->defaultParameters();
        $this->parameters = array_replace_recursive($paramDefaults, $setting->parameters ?? []);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['overview', 'notifications', 'settings'], true) ? $tab : 'overview';
    }

    public function saveSettings(): void
    {
        $this->authorize('update', $this->site);
        $org = $this->site->organization ?? $this->server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $repo = app(InsightSettingsRepository::class);
        $setting = $repo->forSite($this->site, $org);
        $setting->forceFill([
            'enabled_map' => $this->filterEnabledForPlan($this->enabled_map, $org),
            'parameters' => $this->parameters,
        ])->save();

        session()->flash('success', __('Settings saved.'));
    }

    /**
     * @param  array<string, bool>  $map
     * @return array<string, bool>
     */
    protected function filterEnabledForPlan(array $map, Organization $org): array
    {
        $out = [];
        foreach (config('insights.insights', []) as $key => $def) {
            if (($def['requires_pro'] ?? false) && ! $org->onProSubscription()) {
                $out[$key] = false;
            } else {
                $out[$key] = (bool) ($map[$key] ?? false);
            }
        }

        return $out;
    }

    public function enableAll(): void
    {
        $this->authorize('update', $this->site);
        $org = $this->site->organization ?? $this->server->organization;
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['site', 'both'], true)) {
                continue;
            }
            if (($def['requires_pro'] ?? false) && $org && ! $org->onProSubscription()) {
                $this->enabled_map[$key] = false;
            } else {
                $this->enabled_map[$key] = true;
            }
        }
    }

    public function disableAll(): void
    {
        $this->authorize('update', $this->site);
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['site', 'both'], true)) {
                continue;
            }
            $this->enabled_map[$key] = false;
        }
    }

    public function runChecksNow(): void
    {
        $this->authorize('view', $this->site);
        $this->running = true;
        RunSiteInsightsJob::dispatch($this->site->id);
        $this->running = false;
        session()->flash('success', __('Insights check queued. Refresh in a moment for results.'));
    }

    public function applyFix(int $findingId): void
    {
        $this->authorize('update', $this->site);
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->whereKey($findingId)
            ->first();
        if ($finding === null || ! $finding->isOpen()) {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        ApplyInsightFixJob::dispatch($finding->id, $user->id);
        session()->flash('success', __('Fix has been queued. This may take up to a minute.'));
    }

    public function render(): View
    {
        $org = $this->site->organization ?? $this->server->organization;
        $orgHasPro = $org?->onProSubscription() ?? false;

        $findings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        $catalog = [];
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['site', 'both'], true)) {
                continue;
            }
            $catalog[$key] = $def;
        }

        return view('livewire.sites.workspace-insights', [
            'orgHasPro' => $orgHasPro,
            'findings' => $findings,
            'insightsCatalog' => $catalog,
        ]);
    }
}
