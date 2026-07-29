<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\EdgeDeployment;
use App\Models\Site;

/**
 * Compute the effective cron list for an Edge site at a given
 * deployment. Crons originate from two sources:
 *
 *   1. dply.yaml on the deployment (committed in the repo)
 *   2. dashboard overrides on `site.meta.edge.crons_overrides`
 *
 * Both lists are merged here — dashboard entries supplement the repo
 * file, never replace it. Duplicate (schedule, handler) pairs are
 * collapsed; repo entries win on conflict so the file remains the
 * "primary" source of truth and dashboard entries are clearly
 * additive.
 */
final class EdgeEffectiveCrons
{
    /**
     * @return list<array{schedule: string, handler: ?string, source: 'repo'|'dashboard'}>
     */
    public static function for(Site $site, ?EdgeDeployment $deployment): array
    {
        $repo = [];
        $repoConfig = is_array($deployment?->repo_config) ? $deployment->repo_config : [];
        $repoCrons = is_array($repoConfig['crons'] ?? null) ? $repoConfig['crons'] : [];
        foreach ($repoCrons as $entry) {
            $schedule = is_array($entry) && is_string($entry['schedule'] ?? null) ? trim($entry['schedule']) : '';
            if ($schedule === '') {
                continue;
            }
            $handler = is_string($entry['handler'] ?? null) ? trim($entry['handler']) : null;
            $repo[] = ['schedule' => $schedule, 'handler' => $handler !== '' ? $handler : null, 'source' => 'repo'];
        }

        $dashboard = [];
        $overrides = is_array($site->edgeMeta()['crons_overrides'] ?? null) ? $site->edgeMeta()['crons_overrides'] : [];
        foreach ($overrides as $entry) {
            $schedule = is_array($entry) && is_string($entry['schedule'] ?? null) ? trim($entry['schedule']) : '';
            if ($schedule === '') {
                continue;
            }
            $handler = is_string($entry['handler'] ?? null) ? trim($entry['handler']) : null;
            $dashboard[] = ['schedule' => $schedule, 'handler' => $handler !== '' ? $handler : null, 'source' => 'dashboard'];
        }

        $seen = [];
        $merged = [];
        foreach (array_merge($repo, $dashboard) as $entry) {
            $key = $entry['schedule'].'|'.($entry['handler'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $entry;
        }

        return $merged;
    }

    /**
     * Convenience for the CF uploaders — flat list of schedule strings.
     *
     * @return list<string>
     */
    public static function schedulesFor(Site $site, ?EdgeDeployment $deployment): array
    {
        $schedules = [];
        foreach (self::for($site, $deployment) as $entry) {
            $schedules[$entry['schedule']] = true;
        }

        return array_keys($schedules);
    }
}
