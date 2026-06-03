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
     * @param  Collection<int, array{key?: string, level?: string, message?: string, meta?: array<string, mixed>}>  $checks
     * @return Collection<int, array{key: string, level: string, message: string, fix: ?array{label: string, url?: string, action?: string}}>
     */
    public static function actionableChecks(Site $site, Server $server, Collection $checks): Collection
    {
        return $checks
            ->filter(fn (array $check): bool => in_array((string) ($check['level'] ?? ''), ['warning', 'error'], true))
            ->map(function (array $check) use ($site, $server): array {
                $key = (string) ($check['key'] ?? 'check');
                $meta = is_array($check['meta'] ?? null) ? $check['meta'] : [];

                return [
                    'key' => $key,
                    'level' => (string) ($check['level'] ?? 'warning'),
                    'message' => (string) ($check['message'] ?? ''),
                    'fix' => self::fixFor($site, $server, $key, $meta),
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{label: string, url?: string, action?: string}|null
     */
    public static function fixFor(Site $site, Server $server, string $key, array $meta = []): ?array
    {
        if (str_starts_with($key, 'duplicate_step_')) {
            $stepType = (string) ($meta['step_type'] ?? '');
            $phase = (string) ($meta['phase'] ?? '');

            if ($stepType !== '' && $phase !== '') {
                return [
                    'label' => __('Remove duplicate'),
                    'action' => sprintf("removeDuplicateDeployStep('%s', '%s')", $stepType, $phase),
                ];
            }

            return self::link(
                __('Remove duplicate'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if ($key === 'simple_deploy_migrations') {
            return [
                'label' => __('Enable zero downtime'),
                'action' => 'enableZeroDowntimeDeploys',
            ];
        }

        if ($key === 'migrate_without_backup') {
            return self::link(
                __('Open database backups'),
                route('servers.databases', $server),
            );
        }

        if ($key === 'empty_pipeline') {
            return self::link(
                __('Add build steps'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if ($key === 'empty_step_command') {
            return self::link(
                __('Edit step command'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if (str_starts_with($key, 'empty_shell_hook_')
            || str_starts_with($key, 'empty_webhook_')
            || str_starts_with($key, 'empty_notification_')
            || str_starts_with($key, 'orphan_hook_')
            || str_starts_with($key, 'maintenance_down_late_')
            || str_starts_with($key, 'maintenance_up_early_')
            || $key === 'migrate_without_maintenance_down'
            || $key === 'maintenance_down_without_up') {
            return self::link(
                __('Edit hooks'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if (str_starts_with($key, 'release_step_in_build_')) {
            return self::phaseMoveFix($site, $server, __('Move to release'), $meta);
        }

        if (str_starts_with($key, 'build_step_in_release_')) {
            return self::phaseMoveFix($site, $server, __('Move to build'), $meta);
        }

        if (in_array($key, [
            'seed_without_migrate',
            'optimize_before_migrate',
            'queue_before_migrate',
            'horizon_before_migrate',
            'pretend_after_migrate',
            'backup_after_pretend',
        ], true)) {
            return self::link(
                __('Fix release order'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if ($key === 'migrate_without_pretend') {
            return self::link(
                __('Review release safety'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        if (in_array($key, ['multiple_node_installers', 'npm_install_before_ci', 'cache_clear_before_warm'], true)
            || str_starts_with($key, 'scaffolding_')) {
            return self::link(
                __('Edit build steps'),
                self::pipelineTab($site, $server, 'steps'),
            );
        }

        return self::link(
            __('Edit pipeline'),
            self::pipelineTab($site, $server, 'steps'),
        );
    }

    /**
     * In-place "move this step to the other phase" fix, falling back to the
     * steps tab when the originating check didn't carry phase metadata.
     *
     * @param  array<string, mixed>  $meta
     * @return array{label: string, url?: string, action?: string}
     */
    private static function phaseMoveFix(Site $site, Server $server, string $label, array $meta): array
    {
        $stepType = (string) ($meta['step_type'] ?? '');
        $fromPhase = (string) ($meta['from_phase'] ?? '');
        $toPhase = (string) ($meta['to_phase'] ?? '');

        if ($stepType !== '' && $fromPhase !== '' && $toPhase !== '') {
            return [
                'label' => $label,
                'action' => sprintf("moveDeployStepsToPhase('%s', '%s', '%s')", $stepType, $fromPhase, $toPhase),
            ];
        }

        return self::link($label, self::pipelineTab($site, $server, 'steps'));
    }

    private static function pipelineTab(Site $site, Server $server, string $tab): string
    {
        return route('sites.pipeline', [
            'server' => $server,
            'site' => $site,
            'tab' => $tab,
        ]);
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
