<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;

/**
 * Applies a full pipeline starter (Rollout + replace steps/hooks on a pipeline).
 */
final class DeployPipelineStarterApplier
{
    public function __construct(
        private readonly DeployPipelineStarterCatalog $catalog,
        private readonly DeployPipelineSafetyPresets $safetyPresets,
        private readonly SiteDeployPipelineManager $pipelineManager,
    ) {}

    /**
     * @return array{pipeline: SiteDeployPipeline, steps_count: int, hooks_count: int, activated: bool}
     */
    public function apply(
        Site $site,
        SiteDeployPipeline $pipeline,
        string $starterKey,
        bool $activatePipeline = false,
    ): array {
        if (! $this->catalog->visibleForSite($site, key: $starterKey)) {
            throw new \InvalidArgumentException(__('This starter is not available for this site.'));
        }

        $this->applyRollout($site, $starterKey);

        $pipeline->hooks()->delete();
        $pipeline->steps()->delete();

        $steps = $this->catalog->resolveSteps($site, $starterKey);
        foreach ($steps as $index => $step) {
            $pipeline->steps()->create([
                'site_id' => $site->id,
                'sort_order' => ($index + 1) * 10,
                'step_type' => $step['step_type'],
                'phase' => $step['phase'],
                'custom_command' => $step['custom_command'] ?? null,
                'timeout_seconds' => $step['timeout_seconds'],
            ]);
        }

        $pipeline->unsetRelation('steps');
        $pipeline->unsetRelation('hooks');
        $pipeline->load(['steps', 'hooks']);

        $hooksCount = 0;
        if ($this->catalog->includesSafetyBundle($starterKey)) {
            $result = $this->safetyPresets->apply(
                DeployPipelineSafetyPresets::BUNDLE_LARAVEL_V1,
                $pipeline,
                $site,
            );
            $hooksCount = $result['hooks_added'];
            $pipeline->unsetRelation('steps');
            $pipeline->unsetRelation('hooks');
            $pipeline->load(['steps', 'hooks']);
        }

        if ($activatePipeline) {
            $this->pipelineManager->activatePipeline($site, $pipeline);
            $site->setAttribute('active_deploy_pipeline_id', $pipeline->id);
        }

        return [
            'pipeline' => $pipeline,
            'steps_count' => count($steps),
            'hooks_count' => $hooksCount,
            'activated' => $activatePipeline,
        ];
    }

    public function applyRollout(Site $site, string $starterKey): void
    {
        $changes = $this->catalog->rolloutChangesFor($site, $starterKey);
        $meta = is_array($site->meta) ? $site->meta : [];

        $meta['deploy_health_enabled'] = $changes['deploy_health_enabled'];
        $meta['deploy_health_auto_rollback'] = $changes['deploy_health_auto_rollback'];
        $path = $changes['deploy_health_path'];
        $meta['deploy_health_path'] = $path[0] === '/' ? $path : '/'.$path;

        $update = [
            'deploy_strategy' => $changes['deploy_strategy'],
            'meta' => $meta,
        ];

        if ($changes['releases_to_keep'] !== null) {
            $update['releases_to_keep'] = $changes['releases_to_keep'];
        }

        $site->update($update);
    }

    public function pipelineIsEmpty(SiteDeployPipeline $pipeline): bool
    {
        $counts = $this->pipelineCounts($pipeline);

        return $counts['steps'] === 0 && $counts['hooks'] === 0;
    }

    /**
     * @return list<string>
     */
    public function previewSummaryLines(Site $site, SiteDeployPipeline $pipeline, string $starterKey): array
    {
        $strategy = $this->catalog->strategyFor($starterKey);
        $rollout = $this->catalog->rolloutChangesFor($site, $starterKey);
        $steps = $this->catalog->resolveSteps($site, $starterKey);
        $counts = $this->pipelineCounts($pipeline);
        $stepCount = $counts['steps'];
        $hookCount = $counts['hooks'];

        $lines = [
            __('Deploy strategy → :strategy', ['strategy' => $strategy === 'atomic' ? __('Zero downtime (atomic)') : __('Simple (in-place)')]),
            __('Post-deploy health check → :state', ['state' => $rollout['deploy_health_enabled'] ? __('On') : __('Off')]),
            __('Remove :steps step(s) and :hooks hook(s) on this pipeline', [
                'steps' => $stepCount,
                'hooks' => $hookCount,
            ]),
            __('Add :count step(s)', ['count' => count($steps)]),
        ];

        if ($this->catalog->includesSafetyBundle($starterKey)) {
            $lines[] = __('Add Laravel safety hooks and pre-migrate steps');
        }

        if ($rollout['releases_to_keep'] !== null) {
            $lines[] = __('Keep :count releases', ['count' => $rollout['releases_to_keep']]);
        }

        return $lines;
    }

    /**
     * @return array{steps: int, hooks: int}
     */
    private function pipelineCounts(SiteDeployPipeline $pipeline): array
    {
        return [
            'steps' => $pipeline->relationLoaded('steps')
                ? $pipeline->steps->count()
                : $pipeline->steps()->count(),
            'hooks' => $pipeline->relationLoaded('hooks')
                ? $pipeline->hooks->count()
                : $pipeline->hooks()->count(),
        ];
    }
}
