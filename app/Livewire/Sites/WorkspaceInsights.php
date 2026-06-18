<?php

namespace App\Livewire\Sites;

use App\Modules\Insights\Jobs\ApplyInsightFixJob;
use App\Modules\Insights\Jobs\RunSiteInsightsJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\InsightSettingsRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceInsights extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

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
            if (($def['requires_pro'] ?? false) && ! $org->onAnyPaidPlan()) {
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
            if (($def['requires_pro'] ?? false) && $org && ! $org->onAnyPaidPlan()) {
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

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        $runId = (string) Str::ulid();
        $this->seedRunBannerMeta($runId);
        $this->running = true;
        RunSiteInsightsJob::dispatch($this->site->id, null, $runId);
        $this->running = false;
        $this->toastSuccess(__('Insights check queued — watch the banner for live output.'));
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

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        $runId = (string) Str::ulid();
        $this->seedFixBannerMeta($runId, $finding->id);
        ApplyInsightFixJob::dispatch($finding->id, $user->id, $runId);
        $this->toastSuccess(__('Fix queued — watch the banner for live output.'));
    }

    // ─── Workspace banner state ──────────────────────────────────────────────────────────
    //
    // Mirrors the server insights workspace, but writes to `site.meta` instead of
    // `server.meta` so the site page only surfaces banner activity for THIS site.
    // Two banner sources participate here (`run` and `fix`); revert is server-only
    // today since site-scoped fixes don't yet record backups.

    public const STALE_THRESHOLD_SECONDS = 300;

    protected function isInsightsBusy(): bool
    {
        $meta = $this->site->fresh()->meta ?? [];
        foreach (['run', 'fix'] as $kind) {
            if ($this->kindBusy($meta, $kind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function kindBusy(array $meta, string $kind): bool
    {
        $status = (string) data_get($meta, (string) config("insights_workspace.meta_{$kind}_status_key"));
        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        $startedAt = (string) data_get($meta, (string) config("insights_workspace.meta_{$kind}_started_at_key"));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! Carbon::parse($startedAt)->lt(now()->subSeconds(self::STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function rejectIfInsightsBusy(): bool
    {
        if (! $this->isInsightsBusy()) {
            return false;
        }
        $this->toastError(__('An insights operation is already running on this site. Wait for it to finish before starting another.'));

        return true;
    }

    protected function seedRunBannerMeta(string $runId): void
    {
        $this->writeBannerSeed([
            (string) config('insights_workspace.meta_run_run_id_key') => $runId,
            (string) config('insights_workspace.meta_run_status_key') => 'queued',
            (string) config('insights_workspace.meta_run_started_at_key') => now()->toIso8601String(),
            (string) config('insights_workspace.meta_run_finished_at_key') => null,
            (string) config('insights_workspace.meta_run_error_key') => null,
        ]);
    }

    protected function seedFixBannerMeta(string $runId, int $findingId): void
    {
        $this->writeBannerSeed([
            (string) config('insights_workspace.meta_fix_run_id_key') => $runId,
            (string) config('insights_workspace.meta_fix_finding_id_key') => $findingId,
            (string) config('insights_workspace.meta_fix_status_key') => 'queued',
            (string) config('insights_workspace.meta_fix_started_at_key') => now()->toIso8601String(),
            (string) config('insights_workspace.meta_fix_finished_at_key') => null,
            (string) config('insights_workspace.meta_fix_error_key') => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function writeBannerSeed(array $patch): void
    {
        $fresh = $this->site->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ($patch as $k => $v) {
            $meta[$k] = $v;
        }
        $fresh->update(['meta' => $meta]);
        $this->site->refresh();
    }

    public function pollInsightsStatus(): void
    {
        $this->site->refresh();
        $this->reapStaleInsightsBanner();
    }

    /**
     * Surface a worker-gone-away as a normal banner failure. See the server-side
     * counterpart for the rationale.
     */
    protected function reapStaleInsightsBanner(): void
    {
        $fresh = $this->site->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        $changed = false;
        $threshold = (int) config('insights_workspace.stale_threshold_seconds', 300);

        foreach (['run', 'fix'] as $kind) {
            $statusKey = (string) config("insights_workspace.meta_{$kind}_status_key");
            $startedAtKey = (string) config("insights_workspace.meta_{$kind}_started_at_key");
            $finishedAtKey = (string) config("insights_workspace.meta_{$kind}_finished_at_key");
            $errorKey = (string) config("insights_workspace.meta_{$kind}_error_key");

            $status = (string) data_get($meta, $statusKey);
            if (! in_array($status, ['queued', 'running'], true)) {
                continue;
            }

            $startedAt = (string) data_get($meta, $startedAtKey);
            if ($startedAt === '') {
                continue;
            }
            try {
                $started = Carbon::parse($startedAt);
            } catch (\Throwable) {
                continue;
            }
            if ($started->gt(now()->subSeconds($threshold))) {
                continue;
            }

            $meta[$statusKey] = 'failed';
            $meta[$finishedAtKey] = now()->toIso8601String();
            $meta[$errorKey] = null;
            $changed = true;
        }

        if ($changed) {
            $fresh->update(['meta' => $meta]);
            $this->site->refresh();
        }
    }

    public function dismissInsightsBanner(string $kind): void
    {
        $this->authorize('view', $this->site);
        if (! in_array($kind, ['run', 'fix'], true)) {
            return;
        }

        $statusKey = (string) config("insights_workspace.meta_{$kind}_status_key");
        $status = (string) data_get($this->site->fresh()->meta ?? [], $statusKey);
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $fresh = $this->site->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ([
            "meta_{$kind}_run_id_key",
            "meta_{$kind}_status_key",
            "meta_{$kind}_started_at_key",
            "meta_{$kind}_finished_at_key",
            "meta_{$kind}_error_key",
        ] as $configKey) {
            unset($meta[(string) config("insights_workspace.{$configKey}")]);
        }
        if ($kind === 'fix') {
            unset($meta[(string) config('insights_workspace.meta_fix_finding_id_key')]);
        }
        $fresh->update(['meta' => $meta]);
        $this->site->refresh();
    }

    /**
     * @return list<string>
     */
    public function getRunOutputLinesProperty(): array
    {
        return $this->readBannerLines('run');
    }

    /**
     * @return list<string>
     */
    public function getFixOutputLinesProperty(): array
    {
        return $this->readBannerLines('fix');
    }

    /**
     * @return list<string>
     */
    private function readBannerLines(string $kind): array
    {
        $runId = (string) data_get($this->site->meta ?? [], (string) config("insights_workspace.meta_{$kind}_run_id_key"));
        if ($runId === '') {
            return [];
        }
        $prefix = (string) config("insights_workspace.{$kind}_output_cache_key_prefix");
        $payload = Cache::get($prefix.$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    public function render(): View
    {
        $org = $this->site->organization ?? $this->server->organization;
        $orgHasPro = $org?->onAnyPaidPlan() ?? false;

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
