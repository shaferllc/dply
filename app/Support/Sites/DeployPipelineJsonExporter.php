<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;

final class DeployPipelineJsonExporter
{
    public const VERSION = 1;

    public function export(Site $site, SiteDeployPipeline $pipeline): string
    {
        $pipeline->loadMissing(['steps', 'hooks.anchorStep']);

        $framework = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? '')) ?: null;

        $payload = [
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'dply_app' => (string) config('app.name', 'dply'),
            'hints' => [
                'runtime' => $site->runtimeKey(),
                'framework' => $framework,
                'site_name' => $site->name,
            ],
            'pipeline' => [
                'name' => $pipeline->name,
                'deploy_branches' => $pipeline->deploy_branches ?? [],
                'clone_script' => $pipeline->clone_script,
                'activate_script' => $pipeline->activate_script,
                'steps' => $this->exportSteps($pipeline),
                'hooks' => $this->exportHooks($pipeline),
            ],
            'rollout' => $this->exportRollout($site),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportSteps(SiteDeployPipeline $pipeline): array
    {
        return $pipeline->steps
            ->sortBy('sort_order')
            ->values()
            ->map(fn (SiteDeployStep $step): array => [
                'step_type' => $step->step_type,
                'phase' => $step->phase ?? SiteDeployStep::PHASE_BUILD,
                'custom_command' => $step->custom_command,
                'timeout_seconds' => (int) ($step->timeout_seconds ?? 900),
                'sort_order' => (int) $step->sort_order,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportHooks(SiteDeployPipeline $pipeline): array
    {
        return $pipeline->hooks
            ->sortBy('sort_order')
            ->values()
            ->map(function (SiteDeployHook $hook): array {
                $row = [
                    'hook_kind' => $hook->hook_kind,
                    'anchor' => $hook->anchor,
                    'phase' => $hook->phase,
                    'label' => $hook->label,
                    'sort_order' => (int) $hook->sort_order,
                    'timeout_seconds' => (int) ($hook->timeout_seconds ?? 120),
                ];

                if ($hook->anchor === SiteDeployHook::ANCHOR_AFTER_STEP && $hook->anchor_step_id) {
                    $step = $hook->relationLoaded('anchorStep')
                        ? $hook->anchorStep
                        : $hook->anchorStep()->first();
                    if ($step) {
                        $row['after_step_sort_order'] = (int) $step->sort_order;
                    }
                }

                if ($hook->hook_kind === SiteDeployHook::KIND_SHELL) {
                    $row['script'] = $hook->script;
                }
                if ($hook->hook_kind === SiteDeployHook::KIND_WEBHOOK) {
                    $row['webhook_url'] = $hook->webhook_url;
                }
                if ($hook->hook_kind === SiteDeployHook::KIND_NOTIFICATION) {
                    $row['notification_channel_id'] = $hook->notification_channel_id;
                    $row['notification_event'] = $hook->notification_event;
                }

                return $row;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRollout(Site $site): array
    {
        $meta = ($site->meta );

        return [
            'deploy_strategy' => (string) ($site->deploy_strategy ?? 'simple'),
            'releases_to_keep' => (int) ($site->releases_to_keep ?? 5),
            'deploy_health_enabled' => (bool) ($meta['deploy_health_enabled'] ?? false),
            'deploy_health_auto_rollback' => (bool) ($meta['deploy_health_auto_rollback'] ?? false),
            'deploy_health_path' => (string) ($meta['deploy_health_path'] ?? '/up'),
            'deploy_health_expect_status' => (int) ($meta['deploy_health_expect_status'] ?? 200),
            'deploy_health_attempts' => (int) ($meta['deploy_health_attempts'] ?? 5),
            'deploy_health_delay_ms' => (int) ($meta['deploy_health_delay_ms'] ?? 500),
            'deploy_health_scheme' => (string) ($meta['deploy_health_scheme'] ?? 'http'),
            'deploy_health_host' => (string) ($meta['deploy_health_host'] ?? '127.0.0.1'),
            'deploy_health_port' => $meta['deploy_health_port'] ?? null,
        ];
    }
}
