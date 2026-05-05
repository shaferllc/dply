<?php

namespace App\Livewire\Servers;

use App\Jobs\ApplyInsightFixJob;
use App\Jobs\RunServerInsightsJob;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Insights\InsightSettingsRepository;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceInsights extends Component
{
    use InteractsWithServerWorkspace;

    public string $tab = 'overview';

    /** @var array<string, bool> */
    public array $enabled_map = [];

    /** @var array<string, mixed> */
    public array $parameters = [];

    public bool $running = false;

    public bool $showApplyFixModal = false;

    public ?int $applyFixFindingId = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $org = $this->server->organization;
        if (! $org instanceof Organization) {
            $this->enabled_map = [];
            $this->parameters = [];

            return;
        }

        $repo = app(InsightSettingsRepository::class);
        $setting = $repo->forServer($this->server, $org);
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
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $repo = app(InsightSettingsRepository::class);
        $setting = $repo->forServer($this->server, $org);
        $setting->forceFill([
            'enabled_map' => $this->filterEnabledForPlan($this->enabled_map, $org),
            'parameters' => $this->parameters,
        ])->save();

        $this->toastSuccess(__('Settings saved.'));
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
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
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
        $this->authorize('update', $this->server);
        foreach (array_keys(config('insights.insights', [])) as $key) {
            $def = config('insights.insights.'.$key);
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
                continue;
            }
            $this->enabled_map[$key] = false;
        }
    }

    public function runChecksNow(): void
    {
        $this->authorize('view', $this->server);
        $this->running = true;
        RunServerInsightsJob::dispatch($this->server->id);
        $this->running = false;
        $this->toastSuccess(__('Insights check queued. Refresh in a moment for results.'));
    }

    public function openApplyFixModal(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $fix = config('insights.insights.'.$finding->insight_key.'.fix');
        $canFix = is_array($fix) && ($fix['handler'] ?? null);
        if (! $canFix) {
            return;
        }

        $this->applyFixFindingId = $finding->id;
        $this->showApplyFixModal = true;
    }

    public function closeApplyFixModal(): void
    {
        $this->showApplyFixModal = false;
        $this->applyFixFindingId = null;
    }

    public function confirmApplyFix(): void
    {
        if ($this->applyFixFindingId === null) {
            return;
        }

        $findingId = $this->applyFixFindingId;
        $this->closeApplyFixModal();
        $this->applyFix($findingId);
    }

    public function applyFix(int $findingId): void
    {
        $this->authorize('update', $this->server);
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
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
        $this->toastSuccess(__('Fix has been queued. This may take up to a minute.'));
    }

    public function ignoreFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        // Ignore is for suggestions only. Problems should be fixed or auto-resolved, not silenced.
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->where('kind', InsightFinding::KIND_SUGGESTION)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'status' => InsightFinding::STATUS_IGNORED,
            'ignored_at' => now(),
            'ignored_by_user_id' => $user->id,
        ])->save();
    }

    public function acknowledgeFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereNull('acknowledged_at')
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
        ])->save();
    }

    public function render(): View
    {
        $org = $this->server->organization;
        $orgHasPro = $org?->onProSubscription() ?? false;

        $catalog = [];
        $enabledChecks = 0;
        $implementedChecks = 0;
        $installedServiceTags = ServerInstalledServices::tagsFor($this->server);
        $hasUnknownStack = array_key_exists('unknown', $installedServiceTags);
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
                continue;
            }

            // Skip checks whose backing service isn't installed (e.g. InnoDB on a
            // database-less server). Fail open if the stack summary is unavailable so
            // freshly-imported servers still surface everything.
            $requires = is_array($def['requires'] ?? null) ? $def['requires'] : [];
            if (! $hasUnknownStack && $requires !== []) {
                $present = false;
                foreach ($requires as $tag) {
                    if (array_key_exists($tag, $installedServiceTags)) {
                        $present = true;
                        break;
                    }
                }
                if (! $present) {
                    continue;
                }
            }

            $catalog[$key] = $def;

            $enabled = (bool) ($this->enabled_map[$key] ?? false);
            if ($enabled) {
                $enabledChecks++;
            }

            $runnerClass = $def['runner'] ?? null;
            if ($enabled && is_string($runnerClass) && class_exists($runnerClass)) {
                $implementedChecks++;
            }
        }

        $severityOrder = "CASE severity WHEN 'critical' THEN 30 WHEN 'warning' THEN 20 WHEN 'info' THEN 10 ELSE 0 END";
        $findings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->orderByRaw($severityOrder.' DESC')
            ->orderByDesc('detected_at')
            ->limit(100)
            ->get();

        // Split by kind: problems can page + populate the critical banner; suggestions are
        // tuning recommendations rendered in their own section, never in the banner.
        $problemFindings = $findings->where('kind', InsightFinding::KIND_PROBLEM)->values();
        $suggestionFindings = $findings->where('kind', InsightFinding::KIND_SUGGESTION)->values();

        // Banner: top 3 unacknowledged critical *problems*. Acknowledged
        // ones still appear in the list below — ack silences the
        // banner, not the whole row. Suggestions are excluded defensively
        // so a misconfigured suggestion runner can't hijack the banner.
        $bannerFindings = $problemFindings
            ->where('severity', InsightFinding::SEVERITY_CRITICAL)
            ->whereNull('acknowledged_at')
            ->take(3)
            ->values();

        return view('livewire.servers.workspace-insights', [
            'orgHasPro' => $orgHasPro,
            'findings' => $problemFindings,
            'suggestionFindings' => $suggestionFindings,
            'bannerFindings' => $bannerFindings,
            'insightsCatalog' => $catalog,
            'enabledChecks' => $enabledChecks,
            'implementedChecks' => $implementedChecks,
            'selectedFixFinding' => $this->applyFixFindingId === null
                ? null
                : $findings->firstWhere('id', $this->applyFixFindingId),
        ]);
    }
}
