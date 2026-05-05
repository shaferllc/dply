<?php

namespace App\Livewire\Servers;

use App\Jobs\ApplyInsightFixJob;
use App\Jobs\RevertInsightFixJob;
use App\Jobs\RunServerInsightsJob;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Insights\InsightSettingsRepository;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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

    /**
     * The finding currently being inspected in the detail modal.
     * Null when the modal is closed.
     */
    public ?int $detailFindingId = null;

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

    public function rerunSingleCheck(string $insightKey): void
    {
        $this->authorize('view', $this->server);

        // Refuse unknown keys — the coordinator would no-op silently which is hard to debug.
        if (! is_array(config('insights.insights.'.$insightKey))) {
            return;
        }

        RunServerInsightsJob::dispatch($this->server->id, $insightKey);
        $this->toastSuccess(__('Re-running this check. Refresh in a moment for results.'));
        $this->closeFindingDetail();
    }

    /**
     * Open the per-finding detail modal. Scope guard: only findings on
     * THIS server (server-scoped, not site-scoped) can be inspected here —
     * site-specific findings have their own page.
     */
    public function openFindingDetail(int $findingId): void
    {
        $this->authorize('view', $this->server);

        $exists = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($findingId)
            ->exists();

        if (! $exists) {
            return;
        }

        $this->detailFindingId = $findingId;
    }

    public function closeFindingDetail(): void
    {
        $this->detailFindingId = null;
    }

    /**
     * Decorated finding for the detail modal. Returns null when no finding
     * is selected or it can no longer be loaded (e.g., resolved away while
     * the modal was open).
     *
     * @return array{
     *     finding: InsightFinding,
     *     config: array<string, mixed>|null,
     *     label: string|null,
     *     signalRows: array<string, scalar|array<int|string, mixed>|null>,
     *     fixHistory: array{
     *         applied_at: Carbon|null,
     *         applied_by: ?string,
     *         output: ?string,
     *         failed_reason: ?string,
     *         refused_reason: ?string,
     *         backup_path: ?string,
     *     },
     *     correlationFindings: Collection<int, InsightFinding>,
     *     acknowledgedByName: ?string,
     *     ignoredByName: ?string,
     *     actions: array{
     *         canRerun: bool,
     *         canApplyFix: bool,
     *         canRevertFix: bool,
     *         canAcknowledge: bool,
     *         canUnacknowledge: bool,
     *         canIgnore: bool,
     *         canUnignore: bool,
     *     }
     * }|null
     */
    #[Computed]
    public function selectedFindingDetail(): ?array
    {
        if ($this->detailFindingId === null) {
            return null;
        }

        $finding = InsightFinding::query()
            ->with('acknowledgedBy:id,name,email')
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($this->detailFindingId)
            ->first();

        if ($finding === null) {
            return null;
        }

        $config = config('insights.insights.'.$finding->insight_key);
        $config = is_array($config) ? $config : null;

        $meta = is_array($finding->meta) ? $finding->meta : [];
        $signal = is_array($meta['signal'] ?? null) ? $meta['signal'] : [];
        // Flatten nested signal arrays so the modal can render a single
        // key/value table without needing recursive markup.
        $signalRows = $signal === [] ? [] : Arr::dot($signal);

        $parseTs = static fn (mixed $v): ?\Illuminate\Support\Carbon => (is_string($v) && $v !== '') ? Carbon::parse($v) : null;

        $appliedAt = $parseTs($meta['fix_applied_at'] ?? null);
        $failedAt = $parseTs($meta['fix_failed_at'] ?? null);
        $refusedAt = $parseTs($meta['fix_refused_at'] ?? null);
        $runStartedAt = $parseTs($meta['fix_run_started_at'] ?? null);

        $appliedByName = null;
        $appliedById = $meta['fix_applied_by'] ?? $meta['fix_failed_by'] ?? $meta['fix_refused_by'] ?? null;
        if (is_int($appliedById) || (is_string($appliedById) && $appliedById !== '')) {
            $appliedByName = User::query()->whereKey($appliedById)->value('name');
        }

        // Derive a single run status from the terminal/in-flight meta
        // keys. The job (ApplyInsightFixJob) writes one of:
        //   fix_applied_at  → succeeded
        //   fix_failed_at   → failed
        //   fix_refused_at  → refused at preflight
        // runFix() stamps fix_run_started_at and clears the terminal
        // keys, so the modal can show "queued" until the job lands.
        $runStatus = match (true) {
            $appliedAt !== null => 'succeeded',
            $failedAt !== null => 'failed',
            $refusedAt !== null => 'refused',
            $runStartedAt !== null => 'queued',
            default => 'idle',
        };

        $ignoredByName = null;
        if ($finding->ignored_by_user_id !== null) {
            $ignoredByName = User::query()->whereKey($finding->ignored_by_user_id)->value('name');
        }

        $correlationIds = [];
        if (is_array($finding->correlation)) {
            foreach ($finding->correlation as $entry) {
                if (is_int($entry)) {
                    $correlationIds[] = $entry;
                } elseif (is_array($entry) && isset($entry['finding_id']) && is_int($entry['finding_id'])) {
                    $correlationIds[] = $entry['finding_id'];
                }
            }
        }
        $correlationFindings = $correlationIds === []
            ? collect()
            : InsightFinding::query()
                ->where('server_id', $this->server->id)
                ->whereNull('site_id')
                ->whereKey($correlationIds)
                ->where('id', '!=', $finding->id)
                ->orderByDesc('detected_at')
                ->limit(10)
                ->get(['id', 'insight_key', 'severity', 'status', 'title', 'detected_at']);

        $fixConfig = is_array($config['fix'] ?? null) ? $config['fix'] : null;
        $hasFixHandler = $fixConfig !== null && ($fixConfig['handler'] ?? null);
        $backupPath = is_string($meta['backup_path'] ?? null) ? $meta['backup_path'] : null;

        $isOpen = $finding->isOpen();
        $isProblem = $finding->kind !== InsightFinding::KIND_SUGGESTION;

        // canRunFix: handler is wired AND the fix isn't currently
        // in-flight. The button re-enables once a terminal key lands.
        $fixInFlight = $runStatus === 'queued';
        $canRunFix = $isOpen && $hasFixHandler && ! $fixInFlight;

        return [
            'finding' => $finding,
            'config' => $config,
            'label' => is_string($config['label'] ?? null) ? $config['label'] : null,
            'signalRows' => $signalRows,
            'fixHistory' => [
                'run_status' => $runStatus,
                'run_started_at' => $runStartedAt,
                'applied_at' => $appliedAt,
                'failed_at' => $failedAt,
                'refused_at' => $refusedAt,
                'applied_by' => $appliedByName,
                'output' => is_string($meta['fix_output'] ?? null) ? $meta['fix_output'] : null,
                'failed_reason' => is_string($meta['fix_failure_reason'] ?? null) ? $meta['fix_failure_reason'] : null,
                'refused_reason' => is_string($meta['fix_refusal_reason'] ?? null) ? $meta['fix_refusal_reason'] : null,
                'backup_path' => $backupPath,
            ],
            'correlationFindings' => $correlationFindings,
            'acknowledgedByName' => $finding->acknowledgedBy?->name,
            'ignoredByName' => $ignoredByName,
            'actions' => [
                'canRerun' => true,
                'canRunFix' => $canRunFix,
                'canApplyFix' => $isOpen && $hasFixHandler,
                'canRevertFix' => $backupPath !== null && $backupPath !== '',
                'canAcknowledge' => $isOpen && $isProblem && $finding->acknowledged_at === null,
                'canUnacknowledge' => $isOpen && $isProblem && $finding->acknowledged_at !== null,
                'canIgnore' => $isOpen && ! $isProblem,
                'canUnignore' => $finding->isIgnored(),
                'fixInFlight' => $fixInFlight,
            ],
        ];
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
        $this->closeFindingDetailIfMatches($finding->id);
    }

    /**
     * Direct "Run fix now" path used by the detail modal — skips the
     * confirm dialog and immediately stamps a tracking timestamp on the
     * finding so the modal can render an in-flight pill ("Queued") until
     * ApplyInsightFixJob writes its terminal meta keys
     * (fix_applied_at | fix_failed_at | fix_refused_at).
     *
     * The modal stays open: while fix_run_started_at is set without a
     * terminal key, the modal polls and shows live progress.
     */
    public function runFix(int $findingId): void
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

        $fix = config('insights.insights.'.$finding->insight_key.'.fix');
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || $handlerClass === '') {
            return;
        }

        // Pre-flight the handler class HERE in the request cycle so the
        // operator sees a useful toast immediately instead of the queue
        // worker writing fix_handler_missing minutes later. This also
        // catches stale-worker scenarios — if the user added a new fix
        // handler but the long-running queue worker hasn't been restarted
        // yet, the request process knows about the class but the worker
        // doesn't. Better to refuse here than fail mid-run.
        if (! class_exists($handlerClass)) {
            $this->toastError(__('Fix handler class :class is not loadable. Check your queue worker has been restarted after deploying the handler.', [
                'class' => $handlerClass,
            ]));

            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        // Stamp the run-start markers and clear any prior terminal keys
        // from a previous attempt so the modal pill flips back to "Queued"
        // for THIS run instead of staying on the old "Failed".
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_run_started_at'] = now()->toIso8601String();
        $meta['fix_run_started_by'] = $user->id;
        $meta['fix_run_queue'] = config('queue.default');
        unset(
            $meta['fix_applied_at'],
            $meta['fix_applied_by'],
            $meta['fix_failed_at'],
            $meta['fix_failed_by'],
            $meta['fix_failure_reason'],
            $meta['fix_refused_at'],
            $meta['fix_refused_by'],
            $meta['fix_refusal_reason'],
            $meta['fix_output'],
        );
        $finding->forceFill(['meta' => $meta])->save();

        // ApplyInsightFixJob implements ShouldQueue + Queueable, so this
        // dispatches to the configured queue connection (sync only when
        // QUEUE_CONNECTION=sync — in production it lands in the worker).
        ApplyInsightFixJob::dispatch($finding->id, $user->id);

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.fix_run_dispatched', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'queue' => config('queue.default'),
            ]);
        }

        $this->toastSuccess(__('Fix queued — tracking progress here.'));
    }

    public function revertFix(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->whereKey($findingId)
            ->first();
        if ($finding === null) {
            return;
        }

        $backupPath = $finding->meta['backup_path'] ?? null;
        if (! is_string($backupPath) || $backupPath === '') {
            return;
        }

        RevertInsightFixJob::dispatch($finding->id, $user->id);
        $this->toastSuccess(__('Revert has been queued. This may take up to a minute.'));
        $this->closeFindingDetailIfMatches($finding->id);
    }

    public function unignoreFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_IGNORED)
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        // Reopen and clear ignore breadcrumbs so a future ignore restarts the cooldown clock.
        $finding->forceFill([
            'status' => InsightFinding::STATUS_OPEN,
            'ignored_at' => null,
            'ignored_by_user_id' => null,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.unignored', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
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

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.ignored', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    public function unacknowledgeFinding(int $findingId): void
    {
        $this->authorize('update', $this->server);

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        // Only acknowledged-and-still-open findings can be un-acknowledged. We don't reach back
        // into resolved/ignored to prevent surprising state transitions.
        $finding = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->whereNotNull('acknowledged_at')
            ->whereKey($findingId)
            ->first();

        if ($finding === null) {
            return;
        }

        $finding->forceFill([
            'acknowledged_at' => null,
            'acknowledged_by_user_id' => null,
        ])->save();

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.unacknowledged', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'severity' => $finding->severity,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
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

        $org = $this->server->organization;
        if ($org instanceof Organization) {
            audit_log($org, $user, 'insight.acknowledged', $this->server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $finding->insight_key,
                'severity' => $finding->severity,
            ]);
        }

        $this->closeFindingDetailIfMatches($finding->id);
    }

    /**
     * Close the detail modal only when the action targeted the *currently
     * displayed* finding. Without this guard, a row-level action button
     * (e.g., the existing inline "Acknowledge" on the banner) would
     * unexpectedly close an open detail modal pointing at a *different*
     * finding.
     */
    protected function closeFindingDetailIfMatches(int $findingId): void
    {
        if ($this->detailFindingId === $findingId) {
            $this->closeFindingDetail();
        }
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

        // Ignored suggestions still within their cooldown window — surfaced separately so
        // the user has a way to restore one if they change their mind. Server-scoped only.
        $ignoredSuggestions = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_IGNORED)
            ->where('kind', InsightFinding::KIND_SUGGESTION)
            ->orderByDesc('ignored_at')
            ->limit(20)
            ->get();

        // Recently applied config-mutating fixes that still have an on-disk backup we can revert from.
        // Pulled from resolved findings with meta.backup_path set, last 30 days, server-scoped.
        $recentlyAppliedFindings = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_RESOLVED)
            ->where('resolved_at', '>=', now()->subDays(30))
            ->whereRaw("(meta->>'backup_path') is not null and (meta->>'backup_path') <> ''")
            ->orderByDesc('resolved_at')
            ->limit(20)
            ->get();

        // Split by kind: problems can page + populate the critical banner; suggestions are
        // tuning recommendations rendered in their own section, never in the banner.
        // Treat NULL kind as PROBLEM — earlier records pre-date the kind column and
        // would otherwise vanish from the page even though the badge counts them.
        $problemFindings = $findings
            ->filter(fn (InsightFinding $f): bool => $f->kind !== InsightFinding::KIND_SUGGESTION)
            ->values();
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
            'ignoredSuggestions' => $ignoredSuggestions,
            'bannerFindings' => $bannerFindings,
            'recentlyAppliedFindings' => $recentlyAppliedFindings,
            'insightsCatalog' => $catalog,
            'enabledChecks' => $enabledChecks,
            'implementedChecks' => $implementedChecks,
            'selectedFixFinding' => $this->applyFixFindingId === null
                ? null
                : $findings->firstWhere('id', $this->applyFixFindingId),
        ]);
    }
}
