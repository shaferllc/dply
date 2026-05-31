<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Maps deploy pipeline advisor checks to in-product fix destinations.
 */
final class DeployPipelineIssueFixResolver
{
    /**
     * @param  Collection<int, array{key?: string, level?: string, message?: string}>  $checks
     * @return Collection<int, array{key: string, level: string, message: string, fix: ?array{label: string, url: string}}>
     */
    public static function actionableChecks(Site $site, Server $server, Collection $checks): Collection
    {
        $url = route('sites.pipeline', [
            'server' => $server,
            'site' => $site,
            'tab' => 'steps',
        ]);

        return $checks
            ->filter(fn (array $check): bool => in_array((string) ($check['level'] ?? ''), ['warning', 'error'], true))
            ->map(fn (array $check): array => [
                'key' => (string) ($check['key'] ?? 'check'),
                'level' => (string) ($check['level'] ?? 'warning'),
                'message' => (string) ($check['message'] ?? ''),
                'fix' => [
                    'label' => __('Edit pipeline'),
                    'url' => $url,
                ],
            ])
            ->values();
    }
}
