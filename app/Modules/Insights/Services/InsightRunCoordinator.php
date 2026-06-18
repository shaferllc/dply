<?php

namespace App\Modules\Insights\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Support\Servers\ServerInstalledServices;

class InsightRunCoordinator
{
    public function __construct(
        protected InsightSettingsRepository $settingsRepository,
        protected InsightFindingRecorder $recorder,
    ) {}

    /**
     * @param  string|null  $onlyKey  When set, run only this single insight (used by post-fix recheck).
     * @param  (callable(string $event, string $insightKey, array<string, mixed> $context): void)|null  $onProgress
     *                                                                                                               Optional progress hook for live workspace banner output. Events emitted:
     *                                                                                                               'check.start'     — about to invoke the runner for $insightKey
     *                                                                                                               'check.complete'  — runner finished; context: ['candidates' => int]
     *                                                                                                               'check.error'     — runner threw; context: ['message' => string]
     *                                                                                                               Runners themselves are unaware of the hook — the coordinator brackets each call.
     */
    public function runForServer(Server $server, ?string $onlyKey = null, ?callable $onProgress = null): void
    {
        if (! $server->isReady()) {
            return;
        }

        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $settings = $this->settingsRepository->forServer($server, $org);
        $installedTags = ServerInstalledServices::tagsFor($server);

        foreach (config('insights.insights', []) as $key => $def) {
            if ($onlyKey !== null && $key !== $onlyKey) {
                continue;
            }
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
                continue;
            }

            if (! $this->settingsRepository->isInsightEnabled($key, $settings, $org)) {
                continue;
            }

            if (($def['requires_pro'] ?? false) && ! $org->onAnyPaidPlan()) {
                continue;
            }

            if (! $this->stackRequirementsMet($def, $installedTags)) {
                continue;
            }

            $runnerClass = $def['runner'] ?? null;
            if (! is_string($runnerClass) || ! class_exists($runnerClass)) {
                continue;
            }

            /** @var InsightRunnerInterface $runner */
            $runner = app($runnerClass);
            $params = ($settings->parameters ?? [])[$key] ?? [];

            if ($onProgress !== null) {
                $onProgress('check.start', $key, []);
            }
            try {
                $candidates = $runner->run($server, null, is_array($params) ? $params : []);
                $this->recorder->syncCandidates($server, null, $key, $candidates);
                if ($onProgress !== null) {
                    $onProgress('check.complete', $key, ['candidates' => count($candidates)]);
                }
            } catch (\Throwable $e) {
                if ($onProgress !== null) {
                    $onProgress('check.error', $key, ['message' => $e->getMessage()]);
                }
                throw $e;
            }
        }
    }

    /**
     * @param  string|null  $onlyKey  When set, run only this single insight (used by post-fix recheck).
     * @param  (callable(string $event, string $insightKey, array<string, mixed> $context): void)|null  $onProgress
     *                                                                                                               See {@see runForServer} for event semantics.
     */
    public function runForSite(Site $site, ?string $onlyKey = null, ?callable $onProgress = null): void
    {
        $server = $site->server;
        if (! $server->isReady()) {
            return;
        }

        $org = $site->organization ?? $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $settings = $this->settingsRepository->forSite($site, $org);
        $installedTags = ServerInstalledServices::tagsFor($server);

        foreach (config('insights.insights', []) as $key => $def) {
            if ($onlyKey !== null && $key !== $onlyKey) {
                continue;
            }
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['site', 'both'], true)) {
                continue;
            }

            if (! $this->settingsRepository->isInsightEnabled($key, $settings, $org)) {
                continue;
            }

            if (($def['requires_pro'] ?? false) && ! $org->onAnyPaidPlan()) {
                continue;
            }

            if (! $this->stackRequirementsMet($def, $installedTags)) {
                continue;
            }

            $runnerClass = $def['runner'] ?? null;
            if (! is_string($runnerClass) || ! class_exists($runnerClass)) {
                continue;
            }

            /** @var InsightRunnerInterface $runner */
            $runner = app($runnerClass);
            $params = ($settings->parameters ?? [])[$key] ?? [];

            if ($onProgress !== null) {
                $onProgress('check.start', $key, []);
            }
            try {
                $candidates = $runner->run($server, $site, is_array($params) ? $params : []);
                $this->recorder->syncCandidates($server, $site, $key, $candidates);
                if ($onProgress !== null) {
                    $onProgress('check.complete', $key, ['candidates' => count($candidates)]);
                }
            } catch (\Throwable $e) {
                if ($onProgress !== null) {
                    $onProgress('check.error', $key, ['message' => $e->getMessage()]);
                }
                throw $e;
            }
        }
    }

    /**
     * Mirror the UI catalog gating in WorkspaceInsights: skip runners whose backing
     * service isn't installed. Fail open when the stack summary is unavailable
     * (`unknown` tag) so freshly-imported servers still surface everything.
     *
     * @param  array<string, mixed> $def
     * @param  array<string, mixed> $installedTags
     */
    private function stackRequirementsMet(array $def, array $installedTags): bool
    {
        $requires = is_array($def['requires'] ?? null) ? $def['requires'] : [];
        if ($requires === []) {
            return true;
        }
        if (array_key_exists('unknown', $installedTags)) {
            return true;
        }
        foreach ($requires as $tag) {
            if (is_string($tag) && array_key_exists($tag, $installedTags)) {
                return true;
            }
        }

        return false;
    }
}
