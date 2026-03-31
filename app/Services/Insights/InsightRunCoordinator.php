<?php

namespace App\Services\Insights;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;

class InsightRunCoordinator
{
    public function __construct(
        protected InsightSettingsRepository $settingsRepository,
        protected InsightFindingRecorder $recorder,
    ) {}

    public function runForServer(Server $server): void
    {
        if (! $server->isReady()) {
            return;
        }

        $org = $server->organization;
        if (! $org instanceof Organization) {
            return;
        }

        $settings = $this->settingsRepository->forServer($server, $org);

        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['server', 'both'], true)) {
                continue;
            }

            if (! $this->settingsRepository->isInsightEnabled($key, $settings, $org)) {
                continue;
            }

            if (($def['requires_pro'] ?? false) && ! $org->onProSubscription()) {
                continue;
            }

            $runnerClass = $def['runner'] ?? null;
            if (! is_string($runnerClass) || ! class_exists($runnerClass)) {
                continue;
            }

            /** @var InsightRunnerInterface $runner */
            $runner = app($runnerClass);
            $params = ($settings->parameters ?? [])[$key] ?? [];
            $candidates = $runner->run($server, null, is_array($params) ? $params : []);
            $this->recorder->syncCandidates($server, null, $key, $candidates);
        }
    }

    public function runForSite(Site $site): void
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

        foreach (config('insights.insights', []) as $key => $def) {
            $scope = $def['scope'] ?? 'server';
            if (! in_array($scope, ['site', 'both'], true)) {
                continue;
            }

            if (! $this->settingsRepository->isInsightEnabled($key, $settings, $org)) {
                continue;
            }

            if (($def['requires_pro'] ?? false) && ! $org->onProSubscription()) {
                continue;
            }

            $runnerClass = $def['runner'] ?? null;
            if (! is_string($runnerClass) || ! class_exists($runnerClass)) {
                continue;
            }

            /** @var InsightRunnerInterface $runner */
            $runner = app($runnerClass);
            $params = ($settings->parameters ?? [])[$key] ?? [];
            $candidates = $runner->run($server, $site, is_array($params) ? $params : []);
            $this->recorder->syncCandidates($server, $site, $key, $candidates);
        }
    }
}
