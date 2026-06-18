<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Models\Organization;
use App\Modules\Insights\Services\InsightSettingsRepository;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesInsightsSettings
{


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
        $this->tab = in_array($tab, ['overview', 'dismissed', 'notifications', 'settings'], true) ? $tab : 'overview';
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
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
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

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        $runId = (string) Str::ulid();
        $this->seedRunBannerMeta($runId);
        $this->running = true;
        RunServerInsightsJob::dispatch($this->server->id, null, $runId);
        $this->running = false;
        $this->toastSuccess(__('Insights check queued — watch the banner for live output.'));
    }

    public function rerunSingleCheck(string $insightKey): void
    {
        $this->authorize('view', $this->server);

        // Refuse unknown keys — the coordinator would no-op silently which is hard to debug.
        if (! is_array(config('insights.insights.'.$insightKey))) {
            return;
        }

        if ($this->rejectIfInsightsBusy()) {
            return;
        }

        $runId = (string) Str::ulid();
        $this->seedRunBannerMeta($runId);
        RunServerInsightsJob::dispatch($this->server->id, $insightKey, $runId);
        $this->toastSuccess(__('Re-running this check — watch the banner for live output.'));
        $this->closeFindingDetail();
    }
}
