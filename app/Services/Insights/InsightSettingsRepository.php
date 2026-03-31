<?php

namespace App\Services\Insights;

use App\Models\InsightSetting;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;

class InsightSettingsRepository
{
    /**
     * Default enabled flags: all non-Pro insights on; Pro insights on only when org is Pro.
     *
     * @return array<string, bool>
     */
    public function defaultEnabledMap(Organization $organization): array
    {
        $map = [];
        foreach (config('insights.insights', []) as $key => $def) {
            $requiresPro = (bool) ($def['requires_pro'] ?? false);
            $defaultOn = array_key_exists('default_enabled', $def)
                ? (bool) $def['default_enabled']
                : true;
            if ($requiresPro) {
                $map[$key] = $organization->onProSubscription() && $defaultOn;
            } else {
                $map[$key] = $defaultOn;
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultParameters(): array
    {
        $out = [];
        foreach (config('insights.insights', []) as $key => $def) {
            $params = $def['parameters'] ?? [];
            if ($params === []) {
                continue;
            }
            $built = [];
            foreach ($params as $pkey => $spec) {
                $built[$pkey] = $spec['default'] ?? null;
            }
            if ($built !== []) {
                $out[$key] = $built;
            }
        }

        return $out;
    }

    public function forServer(Server $server, Organization $organization): InsightSetting
    {
        return InsightSetting::query()->firstOrCreate(
            [
                'settingsable_type' => $server->getMorphClass(),
                'settingsable_id' => $server->getKey(),
            ],
            [
                'enabled_map' => $this->defaultEnabledMap($organization),
                'parameters' => $this->defaultParameters(),
            ]
        );
    }

    public function forSite(Site $site, Organization $organization): InsightSetting
    {
        return InsightSetting::query()->firstOrCreate(
            [
                'settingsable_type' => $site->getMorphClass(),
                'settingsable_id' => $site->getKey(),
            ],
            [
                'enabled_map' => $this->defaultEnabledMap($organization),
                'parameters' => $this->defaultParameters(),
            ]
        );
    }

    public function isInsightEnabled(string $insightKey, ?InsightSetting $setting, Organization $organization): bool
    {
        $defaults = $this->defaultEnabledMap($organization);
        $map = $setting?->enabled_map;

        return (bool) ($map[$insightKey] ?? $defaults[$insightKey] ?? false);
    }
}
