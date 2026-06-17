<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SiteDeployPipelineManager
{
    public function __construct(
        private readonly DeployPipelineTemplateCatalog $templates,
        private readonly RuntimeAwareDeployStepDefaults $runtimeDefaults,
    ) {}

    public function seedRuntimeDefaults(Site $site, ?string $runtime, ?string $framework = null): void
    {
        $pipeline = $this->ensureDefaultPipeline($site);
        foreach ($this->runtimeDefaults->defaultsFor($runtime, $framework) as $step) {
            $pipeline->steps()->create([
                'site_id' => $site->id,
                'sort_order' => $step['sort_order'],
                'step_type' => $step['step_type'],
                'phase' => $step['phase'],
                'custom_command' => $step['custom_command'] ?? null,
                'timeout_seconds' => $step['timeout_seconds'],
            ]);
        }
    }

    public function ensureDefaultPipeline(Site $site): SiteDeployPipeline
    {
        $existing = $site->relationLoaded('deployPipelines')
            ? $site->deployPipelines->firstWhere('slug', 'default')
            : $site->deployPipelines()->where('slug', 'default')->first();

        if ($existing) {
            if (! $site->active_deploy_pipeline_id) {
                $site->forceFill(['active_deploy_pipeline_id' => $existing->id])->save();
            }

            return $existing;
        }

        $pipeline = $site->deployPipelines()->create([
            'name' => __('Default'),
            'slug' => 'default',
            'description' => null,
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $site->forceFill(['active_deploy_pipeline_id' => $pipeline->id])->save();

        return $pipeline;
    }

    /**
     * Eager-load pipelines (with steps/hooks) and align activeDeployPipeline from the collection when possible.
     */
    public function primeSiteForPipelineWorkspace(Site $site): void
    {
        if ($this->deployPipelinesAreWorkspacePrimed($site)) {
            $this->syncActiveDeployPipelineRelation($site);

            return;
        }

        if ($site->relationLoaded('deployPipelines')) {
            $site->loadMissing([
                'deployPipelines.steps',
                'deployPipelines.hooks.notificationChannel',
            ]);
        } else {
            $site->load([
                'deployPipelines' => static fn ($query) => $query->with([
                    'steps',
                    'hooks.notificationChannel',
                ]),
            ]);
        }

        $this->syncActiveDeployPipelineRelation($site);
    }

    private function deployPipelinesAreWorkspacePrimed(Site $site): bool
    {
        if (! $site->relationLoaded('deployPipelines') || $site->deployPipelines->isEmpty()) {
            return false;
        }

        return $site->deployPipelines->every(
            fn (SiteDeployPipeline $pipeline): bool => $pipeline->relationLoaded('steps')
                && $pipeline->relationLoaded('hooks'),
        );
    }

    public function resolveEditing(Site $site, ?string $pipelineId): SiteDeployPipeline
    {
        $this->primeSiteForPipelineWorkspace($site);

        if ($pipelineId !== null && $pipelineId !== '') {
            $pipeline = $site->deployPipelines->firstWhere('id', $pipelineId);
            if ($pipeline) {
                return $pipeline;
            }
        }

        if ($site->relationLoaded('activeDeployPipeline') && $site->activeDeployPipeline) {
            return $site->activeDeployPipeline;
        }

        return $this->ensureDefaultPipeline($site);
    }

    public function invalidatePrimedPipelines(Site $site): void
    {
        $site->unsetRelation('deployPipelines');
        $site->unsetRelation('activeDeployPipeline');
    }

    /**
     * Swap one pipeline (with fresh steps/hooks) into an already-loaded deployPipelines collection.
     */
    public function mergePrimedPipeline(Site $site, SiteDeployPipeline $pipeline): void
    {
        $pipeline->loadMissing(['steps', 'hooks.notificationChannel']);

        if (! $site->relationLoaded('deployPipelines')) {
            return;
        }

        $pipelines = $site->deployPipelines;
        $index = $pipelines->search(
            fn (SiteDeployPipeline $candidate): bool => (string) $candidate->id === (string) $pipeline->id,
        );

        if ($index === false) {
            $pipelines->push($pipeline);
        } else {
            $pipelines[$index] = $pipeline;
        }

        $site->setRelation('deployPipelines', $pipelines->values());

        if ((string) $site->active_deploy_pipeline_id === (string) $pipeline->id) {
            $site->setRelation('activeDeployPipeline', $pipeline);
        }
    }

    private function syncActiveDeployPipelineRelation(Site $site): void
    {
        if (! $site->active_deploy_pipeline_id || $site->relationLoaded('activeDeployPipeline')) {
            return;
        }

        $active = $site->deployPipelines->firstWhere('id', $site->active_deploy_pipeline_id);

        if ($active) {
            $site->setRelation('activeDeployPipeline', $active);

            return;
        }

        $site->loadMissing([
            'activeDeployPipeline' => static fn ($query) => $query->with([
                'steps',
                'hooks.notificationChannel',
            ]),
        ]);
    }

    public function createPipeline(Site $site, string $name, ?string $duplicateFromId = null): SiteDeployPipeline
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException(__('Pipeline name is required.'));
        }

        $slug = $this->uniqueSlug($site, Str::slug($name) ?: 'pipeline');
        $sortOrder = (int) ($site->deployPipelines()->max('sort_order') ?? 0) + 1;

        $pipeline = $site->deployPipelines()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'is_default' => false,
            'sort_order' => $sortOrder,
        ]);

        if ($duplicateFromId) {
            $source = $site->deployPipelines()->whereKey($duplicateFromId)->first();
            if ($source) {
                $stepMap = [];
                foreach ($source->steps()->orderBy('sort_order')->get() as $step) {
                    $copy = $pipeline->steps()->create([
                        'site_id' => $site->id,
                        'sort_order' => $step->sort_order,
                        'step_type' => $step->step_type,
                        'phase' => $step->phase,
                        'custom_command' => $step->custom_command,
                        'timeout_seconds' => $step->timeout_seconds,
                    ]);
                    $stepMap[(string) $step->id] = (string) $copy->id;
                }
                $pipeline->forceFill([
                    'clone_script' => $source->clone_script,
                    'activate_script' => $source->activate_script,
                ])->save();

                foreach ($source->hooks()->orderBy('sort_order')->get() as $hook) {
                    $pipeline->hooks()->create([
                        'site_id' => $site->id,
                        'sort_order' => $hook->sort_order,
                        'phase' => $hook->phase,
                        'hook_kind' => $hook->hook_kind,
                        'anchor' => $hook->anchor,
                        'anchor_step_id' => $hook->anchor_step_id
                            ? ($stepMap[(string) $hook->anchor_step_id] ?? null)
                            : null,
                        'label' => $hook->label,
                        'script' => $hook->script,
                        'webhook_url' => $hook->webhook_url,
                        'notification_channel_id' => $hook->notification_channel_id,
                        'notification_event' => $hook->notification_event,
                        'timeout_seconds' => $hook->timeout_seconds,
                    ]);
                }
            }
        }

        return $pipeline;
    }

    public function updateAnchorScripts(
        SiteDeployPipeline $pipeline,
        ?string $cloneScript,
        ?string $activateScript,
    ): void {
        $pipeline->update([
            'clone_script' => $this->normalizeAnchorScript($cloneScript),
            'activate_script' => $this->normalizeAnchorScript($activateScript),
        ]);
    }

    private function normalizeAnchorScript(?string $script): ?string
    {
        if ($script === null) {
            return null;
        }

        $trimmed = trim($script);

        return $trimmed === '' ? null : $trimmed;
    }

    public function activatePipeline(Site $site, SiteDeployPipeline $pipeline): void
    {
        if ((string) $pipeline->site_id !== (string) $site->id) {
            throw new InvalidArgumentException(__('Pipeline does not belong to this site.'));
        }

        $site->forceFill(['active_deploy_pipeline_id' => $pipeline->id])->save();
    }

    public function deletePipeline(Site $site, SiteDeployPipeline $pipeline): void
    {
        if ((string) $pipeline->site_id !== (string) $site->id) {
            throw new InvalidArgumentException(__('Pipeline does not belong to this site.'));
        }

        if ($site->deployPipelines()->count() <= 1) {
            throw new InvalidArgumentException(__('Keep at least one pipeline on this site.'));
        }

        $wasActive = (string) $site->active_deploy_pipeline_id === (string) $pipeline->id;
        $pipeline->delete();

        if ($wasActive) {
            $fallback = $site->deployPipelines()->orderBy('sort_order')->orderBy('name')->first();
            if ($fallback) {
                $site->forceFill(['active_deploy_pipeline_id' => $fallback->id])->save();
            }
        }
    }

    public function applyTemplate(SiteDeployPipeline $pipeline, string $templateKey): void
    {
        $steps = $this->templates->stepsForTemplateKey($templateKey);
        if ($steps === []) {
            throw new InvalidArgumentException(__('Unknown pipeline template.'));
        }

        $pipeline->steps()->delete();

        foreach ($steps as $index => $step) {
            $pipeline->steps()->create([
                'site_id' => $pipeline->site_id,
                'sort_order' => ($index + 1) * 10,
                'step_type' => $step['step_type'],
                'phase' => $step['phase'],
                'custom_command' => $step['custom_command'] ?? null,
                'timeout_seconds' => $step['timeout_seconds'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed> $orderedStepIds
     */
    public function reorderSteps(SiteDeployPipeline $pipeline, array $orderedStepIds): void
    {
        $all = array_merge(
            $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_BUILD),
            $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_RELEASE),
        );
        $this->validateOrderedSubset($all, $orderedStepIds);
        $this->persistStepOrder($orderedStepIds);
    }

    /**
     * @param  array<string, mixed> $orderedBuildStepIds
     */
    public function reorderBuildSteps(SiteDeployPipeline $pipeline, array $orderedBuildStepIds): void
    {
        $build = $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_BUILD);
        $release = $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_RELEASE);
        $this->validateOrderedSubset($build, $orderedBuildStepIds);
        $this->persistStepOrder(array_merge($orderedBuildStepIds, $release));
    }

    /**
     * @param  array<string, mixed> $orderedReleaseStepIds
     */
    public function reorderReleaseSteps(SiteDeployPipeline $pipeline, array $orderedReleaseStepIds): void
    {
        $build = $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_BUILD);
        $release = $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_RELEASE);
        $this->validateOrderedSubset($release, $orderedReleaseStepIds);
        $this->persistStepOrder(array_merge($build, $orderedReleaseStepIds));
    }

    public function addStep(
        SiteDeployPipeline $pipeline,
        string $stepType,
        ?string $customCommand,
        int $timeoutSeconds,
        ?int $insertIndex = null,
        ?string $phase = null,
    ): SiteDeployStep {
        $phase = $phase ?? SiteDeployStep::defaultPhaseFor($stepType);

        $step = $pipeline->steps()->create([
            'site_id' => $pipeline->site_id,
            'sort_order' => (int) ($pipeline->steps()->max('sort_order') ?? 0) + 10,
            'step_type' => $stepType,
            'phase' => $phase,
            'custom_command' => $customCommand !== null && trim($customCommand) !== '' ? trim($customCommand) : null,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        if ($insertIndex !== null) {
            $phaseIds = $this->phaseStepIds($pipeline, $phase);
            $phaseIds = array_values(array_filter($phaseIds, fn (string $id) => $id !== (string) $step->id));
            $position = max(0, min($insertIndex, count($phaseIds)));
            array_splice($phaseIds, $position, 0, [(string) $step->id]);

            if ($phase === SiteDeployStep::PHASE_RELEASE) {
                $this->reorderReleaseSteps($pipeline, $phaseIds);
            } else {
                $this->reorderBuildSteps($pipeline, $phaseIds);
            }
        }

        return $step;
    }

    public function updateStep(
        SiteDeployPipeline $pipeline,
        SiteDeployStep $step,
        string $stepType,
        ?string $customCommand,
        int $timeoutSeconds,
        string $phase,
    ): SiteDeployStep {
        if ((string) $step->pipeline_id !== (string) $pipeline->id) {
            throw new InvalidArgumentException(__('Step does not belong to this pipeline.'));
        }

        if (! in_array($phase, SiteDeployStep::userPhases(), true)) {
            throw new InvalidArgumentException(__('Invalid pipeline phase.'));
        }

        $oldPhase = $step->phase ?? SiteDeployStep::PHASE_BUILD;

        $step->update([
            'step_type' => $stepType,
            'phase' => $phase,
            'custom_command' => $customCommand !== null && trim($customCommand) !== '' ? trim($customCommand) : null,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        if ($oldPhase !== $phase) {
            $build = array_values(array_filter(
                $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_BUILD),
                fn (string $id) => $id !== (string) $step->id,
            ));
            $release = array_values(array_filter(
                $this->phaseStepIds($pipeline, SiteDeployStep::PHASE_RELEASE),
                fn (string $id) => $id !== (string) $step->id,
            ));

            if ($phase === SiteDeployStep::PHASE_RELEASE) {
                $release[] = (string) $step->id;
            } else {
                $build[] = (string) $step->id;
            }

            $this->persistStepOrder(array_merge($build, $release));
        }

        return $step->fresh();
    }

    /**
     * @param  array<string, mixed> $expectedIds
     * @param  array<string, mixed> $orderedSubset
     */
    private function validateOrderedSubset(array $expectedIds, array $orderedSubset): void
    {
        $ordered = array_values(array_filter(
            $orderedSubset,
            fn ($id) => in_array((string) $id, $expectedIds, true),
        ));

        if (count($ordered) !== count($expectedIds)) {
            throw new InvalidArgumentException(__('Invalid step order.'));
        }
    }

    /**
     * @param  array<string, mixed> $mergedIds
     */
    private function persistStepOrder(array $mergedIds): void
    {
        foreach ($mergedIds as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => ($i + 1) * 10]);
        }
    }

    /** @return list<string> */
    private function phaseStepIds(SiteDeployPipeline $pipeline, string $phase): array
    {
        return $pipeline->steps()
            ->where('phase', $phase)
            ->orderBy('sort_order')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    private function uniqueSlug(Site $site, string $base): string
    {
        $slug = $base;
        $n = 2;
        while ($site->deployPipelines()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }
}
