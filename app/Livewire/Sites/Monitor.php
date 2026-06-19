<?php

namespace App\Livewire\Sites;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CorrelatesWindowLogs;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\ManagesUptimeNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeIncident;
use App\Models\SiteUptimeMonitor;
use App\Modules\Serverless\Services\FunctionStatsRangeQuery;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Services\Sites\SiteUptimeHistorySummary;
use App\Services\Sites\UptimeProbeRegionResolver;
use App\Services\Sites\UptimeProbeWorkerResolver;
use App\Services\Status\MonitorOperationalState;
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
    use CorrelatesWindowLogs;
    use CreatesNotificationChannelInline;
    use DismissesConsoleActionRun;
    use DispatchesToastNotifications;
    use ManagesUptimeNotifications;

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    /** @var list<string> */
    public const MONITOR_TABS = ['monitors', 'alerts'];

    public Server $server;

    public Site $site;

    /** Active workspace tab: monitors | alerts. */
    #[Url(as: 'tab', except: 'monitors')]
    public string $monitorTab = 'monitors';

    /** Monitor being edited in the modal; null while adding a new one. */
    public ?string $editingMonitorId = null;

    public string $newLabel = '';

    public string $newCheckType = SiteUptimeMonitor::CHECK_HTTP;

    public string $newPath = '';

    public string $newProbeRegion = 'eu-amsterdam';

    /** Selected probe worker key for a new monitor; null when none configured. */
    public ?string $newProbeWorker = null;

    // HTTP assertion config (empty = off).
    public string $newKeyword = '';

    public string $newMatchMode = SiteUptimeMonitor::MATCH_CONTAIN;

    public string $newExpectedStatus = '';

    public string $newResponseThresholdMs = '';

    // SSL config (empty = use the site_uptime default warn window).
    public string $newSslWarnDays = '';

    /** Per-monitor inline history detail toggled open by the user. */
    public ?string $expandedMonitorId = null;

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

        // Needed by CorrelatesWindowLogs to gate the "logs around this incident" jump.
        $this->server->loadMissing('logAgent');

        // Default the probe worker to the one nearest the host; the region
        // label follows the worker (falling back to nearest region when no
        // worker is configured).
        $workerResolver = app(UptimeProbeWorkerResolver::class);
        $this->newProbeWorker = $workerResolver->forSite($this->site);
        $this->newProbeRegion = $workerResolver->regionFor($this->newProbeWorker)
            ?? app(UptimeProbeRegionResolver::class)->forSite($this->site);

        if (! FunctionStatsRangeQuery::isValidRange($this->statsRange)) {
            $this->statsRange = FunctionStatsRangeQuery::defaultRange();
        }

        if (! in_array($this->monitorTab, self::MONITOR_TABS, true)) {
            $this->monitorTab = 'monitors';
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

        $workerResolver = app(UptimeProbeWorkerResolver::class);
        $worker = $workerResolver->forSite($this->site);

        $monitor = SiteUptimeMonitor::query()->firstOrCreate(
            ['site_id' => $this->site->id, 'sort_order' => 0],
            [
                'label' => __('Homepage check'),
                'path' => null,
                'probe_region' => $workerResolver->regionFor($worker)
                    ?? app(UptimeProbeRegionResolver::class)->forSite($this->site),
                'probe_worker' => $worker,
            ],
        );

        $this->site->load('uptimeMonitors');

        if ($monitor->wasRecentlyCreated) {
            RunSiteUptimeMonitorCheckJob::dispatchWithConsoleAction($this->site, $monitor, auth()->id());
        }
    }

    public function setMonitorWorkspaceTab(string $tab): void
    {
        $this->monitorTab = in_array($tab, self::MONITOR_TABS, true) ? $tab : 'monitors';
    }

    public function setStatsRange(string $range): void
    {
        $this->statsRange = FunctionStatsRangeQuery::isValidRange($range)
            ? $range
            : FunctionStatsRangeQuery::defaultRange();
    }

    /** Re-renders, which re-queries the function-activity series. */
    public function refreshStats(): void {}

    /** Reset the form to add-mode defaults and open the modal. */
    public function startAddMonitor(): void
    {
        Gate::authorize('update', $this->site);

        $this->resetMonitorForm();
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'uptime-monitor-modal');
    }

    /** Populate the form from an existing monitor and open the modal in edit-mode. */
    public function editMonitor(string $monitorId): void
    {
        Gate::authorize('update', $this->site);

        $monitor = SiteUptimeMonitor::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($monitorId);

        $this->editingMonitorId = $monitor->id;
        $this->newLabel = (string) $monitor->label;
        $this->newCheckType = $monitor->isSslCheck() ? SiteUptimeMonitor::CHECK_SSL : SiteUptimeMonitor::CHECK_HTTP;
        $this->newPath = (string) ($monitor->path ?? '');
        $this->newProbeWorker = $monitor->probe_worker;
        $this->newKeyword = (string) ($monitor->keywordAssertion() ?? '');
        $this->newMatchMode = $monitor->keywordMatchMode();
        $this->newExpectedStatus = $monitor->expectedStatus() !== null ? (string) $monitor->expectedStatus() : '';
        $this->newResponseThresholdMs = $monitor->responseThresholdMs() !== null ? (string) $monitor->responseThresholdMs() : '';
        $sslDays = is_array($monitor->config) ? ($monitor->config['ssl_warn_days'] ?? null) : null;
        $this->newSslWarnDays = is_numeric($sslDays) ? (string) (int) $sslDays : '';

        $this->resetErrorBag();
        $this->dispatch('open-modal', 'uptime-monitor-modal');
    }

    public function saveMonitor(SiteUptimeCheckUrlResolver $resolver): void
    {
        Gate::authorize('update', $this->site);

        $workerResolver = app(UptimeProbeWorkerResolver::class);
        $workerOptions = $workerResolver->options();
        $isSsl = $this->newCheckType === SiteUptimeMonitor::CHECK_SSL;

        $rules = [
            'newLabel' => 'required|string|max:120',
            'newCheckType' => ['required', Rule::in([SiteUptimeMonitor::CHECK_HTTP, SiteUptimeMonitor::CHECK_SSL])],
        ];
        if (! $isSsl) {
            $rules['newPath'] = 'nullable|string|max:2048';
            $rules['newKeyword'] = 'nullable|string|max:255';
            $rules['newMatchMode'] = ['nullable', Rule::in([SiteUptimeMonitor::MATCH_CONTAIN, SiteUptimeMonitor::MATCH_NOT_CONTAIN])];
            $rules['newExpectedStatus'] = 'nullable|integer|min:100|max:599';
            $rules['newResponseThresholdMs'] = 'nullable|integer|min:1|max:120000';
        } else {
            $rules['newSslWarnDays'] = 'nullable|integer|min:1|max:90';
        }
        // Only require a worker when some are configured; with none the feature
        // falls back to the central egress (null worker → default queue).
        if ($workerOptions !== []) {
            $rules['newProbeWorker'] = ['required', 'string', Rule::in(array_keys($workerOptions))];
        }

        $this->validate($rules);

        if ($resolver->resolveBaseUrl($this->site) === null) {
            $this->addError('newLabel', __('Add a primary domain, preview hostname, or publication URL before creating monitors.'));

            return;
        }

        $worker = $workerOptions !== [] ? $this->newProbeWorker : null;
        $region = $workerResolver->regionFor($worker)
            ?? app(UptimeProbeRegionResolver::class)->forSite($this->site);

        $attributes = [
            'label' => $this->newLabel,
            'check_type' => $isSsl ? SiteUptimeMonitor::CHECK_SSL : SiteUptimeMonitor::CHECK_HTTP,
            'path' => $isSsl ? null : $this->normalizePathInput($this->newPath),
            'config' => $this->buildConfig($isSsl),
            'probe_region' => $region,
            'probe_worker' => $worker,
        ];

        if ($this->editingMonitorId !== null) {
            $monitor = SiteUptimeMonitor::query()
                ->where('site_id', $this->site->id)
                ->findOrFail($this->editingMonitorId);
            $monitor->update($attributes);
            $this->toastSuccess(__('Monitor updated.'));
        } else {
            $monitor = SiteUptimeMonitor::query()->create($attributes + [
                'site_id' => $this->site->id,
                'sort_order' => (int) $this->site->uptimeMonitors()->max('sort_order') + 1,
            ]);
            $this->toastSuccess(__('Monitor added.'));
        }

        $this->resetMonitorForm();
        $this->site->load('uptimeMonitors');
        $this->dispatch('close-modal', 'uptime-monitor-modal');

        // Re-run immediately so the new config takes effect and the row updates.
        RunSiteUptimeMonitorCheckJob::dispatchWithConsoleAction($this->site, $monitor, auth()->id());
    }

    /**
     * Type-specific config blob; only non-empty knobs are stored.
     *
     * @return array<string, mixed>|null
     */
    private function buildConfig(bool $isSsl): ?array
    {
        $config = [];

        if ($isSsl) {
            if ($this->newSslWarnDays !== '') {
                $config['ssl_warn_days'] = (int) $this->newSslWarnDays;
            }

            return $config === [] ? null : $config;
        }

        $keyword = trim($this->newKeyword);
        if ($keyword !== '') {
            $config['keyword'] = $keyword;
            $config['match_mode'] = $this->newMatchMode === SiteUptimeMonitor::MATCH_NOT_CONTAIN
                ? SiteUptimeMonitor::MATCH_NOT_CONTAIN
                : SiteUptimeMonitor::MATCH_CONTAIN;
        }
        if ($this->newExpectedStatus !== '') {
            $config['expected_status'] = (int) $this->newExpectedStatus;
        }
        if ($this->newResponseThresholdMs !== '') {
            $config['response_threshold_ms'] = (int) $this->newResponseThresholdMs;
        }

        return $config === [] ? null : $config;
    }

    private function resetMonitorForm(): void
    {
        $this->reset([
            'editingMonitorId', 'newLabel', 'newCheckType', 'newPath',
            'newKeyword', 'newMatchMode', 'newExpectedStatus', 'newResponseThresholdMs', 'newSslWarnDays',
        ]);

        $workerResolver = app(UptimeProbeWorkerResolver::class);
        $this->newProbeWorker = $workerResolver->forSite($this->site);
    }

    /** Toggle the inline history detail for a monitor row. */
    public function toggleHistory(string $monitorId): void
    {
        $this->expandedMonitorId = $this->expandedMonitorId === $monitorId ? null : $monitorId;
    }

    /**
     * Open the dply Logs correlation drawer on the host log slice that spans a
     * downtime incident — started_at..resolved_at (now() while still ongoing),
     * padded by the correlator. The integrated "why was it down?" jump: a
     * standalone uptime checker can't show you the host logs at the dip.
     */
    public function openLogsForIncident(string $incidentId): void
    {
        $incident = SiteUptimeIncident::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($incidentId);

        $from = $incident->started_at ?? $incident->created_at;
        $to = $incident->resolved_at ?? now();

        $this->presentWindowLogs(
            $from,
            $to,
            __('Logs around :severity incident', ['severity' => $incident->severity]),
        );
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
        $probeWorkerOptions = app(UptimeProbeWorkerResolver::class)->options();
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

        // Inline history for the expanded monitor only — keeps the page cheap
        // when nothing is expanded.
        $expandedHistory = null;
        if ($this->expandedMonitorId !== null) {
            $expanded = $this->site->uptimeMonitors->firstWhere('id', $this->expandedMonitorId);
            if ($expanded !== null) {
                $expandedHistory = app(SiteUptimeHistorySummary::class)->forMonitor($expanded);
            }
        }

        $operationalState = app(MonitorOperationalState::class);
        $onAlertsTab = $this->monitorTab === 'alerts';

        return view('livewire.sites.monitor', [
            'probeRegions' => is_array($probeRegions) ? $probeRegions : [],
            'probeWorkerOptions' => $probeWorkerOptions,
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
            'expandedHistory' => $expandedHistory,
            'operationalState' => $operationalState,
            'uptimeNotifChannels' => $onAlertsTab ? $this->assignableUptimeNotificationChannels() : collect(),
            'uptimeNotifSubscriptions' => $onAlertsTab ? $this->uptimeNotificationSubscriptions() : collect(),
            'uptimeNotifEventLabels' => $onAlertsTab ? $this->uptimeEventLabels() : [],
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
