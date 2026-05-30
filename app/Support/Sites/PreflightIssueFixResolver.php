<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Maps deployment preflight check keys to in-product fix destinations.
 */
final class PreflightIssueFixResolver
{
    /**
     * @param  Collection<int, array{key?: string, level?: string, message?: string}>  $checks
     * @return Collection<int, array{key: string, level: string, message: string, fix: ?array{label: string, url: string}}>
     */
    public static function actionableChecks(Site $site, Server $server, Collection $checks): Collection
    {
        return $checks
            ->filter(fn (array $check): bool => in_array((string) ($check['level'] ?? ''), ['warning', 'error'], true))
            ->map(function (array $check) use ($site, $server): array {
                $key = (string) ($check['key'] ?? 'check');

                return [
                    'key' => $key,
                    'level' => (string) ($check['level'] ?? 'warning'),
                    'message' => (string) ($check['message'] ?? ''),
                    'fix' => self::fixFor($site, $server, $key),
                ];
            })
            ->values();
    }

    /**
     * @return array{label: string, url: string}|null
     */
    public static function fixFor(Site $site, Server $server, string $key): ?array
    {
        return match ($key) {
            'publication' => self::link(
                __('Open routing'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'preview']),
            ),
            'app_key', 'redis', 'storage' => self::link(
                __('Open environment'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'environment']),
            ),
            'database' => $site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime()
                ? self::link(__('Open resources'), route('sites.resources', ['server' => $server, 'site' => $site]))
                : self::link(__('Open server databases'), route('servers.databases', $server)),
            'scheduler' => $site->usesFunctionsRuntime()
                ? self::link(__('Open schedule'), route('sites.schedule', ['server' => $server, 'site' => $site]))
                : self::link(__('Open cron jobs'), route('sites.cron', ['server' => $server, 'site' => $site])),
            'queue', 'workers' => $site->usesFunctionsRuntime()
                ? self::link(__('Open workers'), route('sites.workers', ['server' => $server, 'site' => $site]))
                : self::link(__('Open queue workers'), route('sites.queue-workers', ['server' => $server, 'site' => $site])),
            'runtime_revision' => self::link(
                __('Open deploy settings'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']),
            ),
            'repository' => self::link(
                __('Open repository'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'repository']),
            ),
            'server' => self::link(
                __('Open server overview'),
                route('servers.overview', $server),
            ),
            default => self::fixForResourceType($site, $server, $key),
        };
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private static function fixForResourceType(Site $site, Server $server, string $key): ?array
    {
        if (! in_array($key, ['cache', 'redis', 'storage', 'database', 'queue', 'scheduler', 'workers'], true)) {
            return null;
        }

        if ($site->usesFunctionsRuntime() || $site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            return self::link(__('Open resources'), route('sites.resources', ['server' => $server, 'site' => $site]));
        }

        return match ($key) {
            'cache', 'redis' => self::link(
                __('Open environment'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'environment']),
            ),
            'database' => self::link(__('Open server databases'), route('servers.databases', $server)),
            'scheduler' => $site->usesFunctionsRuntime()
                ? self::link(__('Open schedule'), route('sites.schedule', ['server' => $server, 'site' => $site]))
                : self::link(__('Open cron jobs'), route('sites.cron', ['server' => $server, 'site' => $site])),
            'queue', 'workers' => $site->usesFunctionsRuntime()
                ? self::link(__('Open workers'), route('sites.workers', ['server' => $server, 'site' => $site]))
                : self::link(__('Open queue workers'), route('sites.queue-workers', ['server' => $server, 'site' => $site])),
            default => self::link(
                __('Open runtime'),
                route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime']),
            ),
        };
    }

    /**
     * @return array{label: string, url: string}
     */
    private static function link(string $label, string $url): array
    {
        return [
            'label' => $label,
            'url' => $url,
        ];
    }
}
