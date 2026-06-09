<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\OptimizeSitePipelineJob;
use App\Livewire\Sites\Concerns\ManagesSiteDeploySteps;
use App\Models\SiteDeployStep;
use App\Services\Deploy\SiteDeployPipelineManager;
use Livewire\Component;

/**
 * "Optimize pipeline" action — reads the deployed repo's package.json /
 * composer.json + lockfiles and adds the deploy steps the pipeline is missing.
 * Extracted into its own trait (rather than living on the heavy
 * {@see ManagesSiteDeploySteps}) so BOTH the
 * Pipeline page and the Deploy hub can offer the button — the Deploy hub
 * component doesn't carry the full pipeline-editing trait.
 *
 * Requires the host component to expose `$this->site`, `authorize()`,
 * `seedQueuedConsoleAction()` and (optionally) `watchConsoleAction()`.
 *
 * @phpstan-require-extends Component
 */
trait OptimizesPipeline
{
    public function optimizePipeline(): void
    {
        $this->authorize('update', $this->site);

        if (! method_exists($this, 'seedQueuedConsoleAction')) {
            return;
        }

        $run = $this->seedQueuedConsoleAction('pipeline_optimize', __('Optimizing pipeline'));
        OptimizeSitePipelineJob::dispatch((string) $run->id, (string) $this->site->id);

        $this->dispatch('dply-console-action-focus');
        if (method_exists($this, 'watchConsoleAction')) {
            $this->watchConsoleAction(
                $run,
                __('Scan complete — review the proposed changes before applying.'),
                __('Pipeline optimize did not finish — see the output.'),
            );
        }
        if (method_exists($this, 'toastConsoleActionQueued')) {
            $this->toastConsoleActionQueued();
        }
    }

    /**
     * Apply the steps the scan proposed (stored on meta.pipeline_optimize_preview).
     * Adding steps is DB-only, so it runs inline. Idempotent: skips anything
     * the pipeline already has, then clears the preview.
     */
    public function applyPipelineOptimization(): void
    {
        $this->authorize('update', $this->site);

        $preview = $this->site->meta['pipeline_optimize_preview']['steps'] ?? null;
        if (! is_array($preview) || $preview === []) {
            $this->discardPipelineOptimization();

            return;
        }

        $pipelines = app(SiteDeployPipelineManager::class);
        $pipeline = $pipelines->ensureDefaultPipeline($this->site);
        $existing = $pipeline->steps()->get();
        $existingTypes = $existing->pluck('step_type')->map(static fn ($t): string => (string) $t)->all();
        $existingCustom = $existing->where('step_type', SiteDeployStep::TYPE_CUSTOM)
            ->map(static fn ($s): string => strtolower((string) $s->custom_command));

        $added = 0;
        foreach ($preview as $step) {
            $type = (string) ($step['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $isCustom = $type === SiteDeployStep::TYPE_CUSTOM;
            $command = $step['command'] ?? null;
            $already = $isCustom
                ? $existingCustom->contains(static fn (string $c): bool => $c === strtolower((string) $command))
                : in_array($type, $existingTypes, true);

            if ($already) {
                continue;
            }

            $pipelines->addStep($pipeline, $type, $command, 900, null, (string) ($step['phase'] ?? SiteDeployStep::PHASE_BUILD));
            $existingTypes[] = $type;
            if ($isCustom) {
                $existingCustom->push(strtolower((string) $command));
            }
            $added++;
        }

        $this->clearPipelineOptimizePreview();
        $this->dispatch('close-modal', 'pipeline-optimize-preview');

        // Drop the cached `deploySteps` relation so SitePipelineAdvisor recomputes
        // against the steps we just added and the "Pipeline check" card clears on
        // re-render. Livewire restores loaded relations from its snapshot, so the
        // advisor's loadMissing('deploySteps') would otherwise reuse a stale
        // collection that still flags the just-added steps as missing. The deploy
        // hub host (DeploymentsList) has no syncEditingPipelineBranches/refresh of
        // its own, so this unset is what actually makes its card disappear.
        $this->site->unsetRelation('deploySteps');

        if (method_exists($this, 'syncEditingPipelineBranches')) {
            // Pipeline editor: refresh so the new steps render immediately.
            $this->site->refresh();
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(trans_choice(
                '{0} No new steps to add.|{1} Added :count step to the pipeline.|[2,*] Added :count steps to the pipeline.',
                $added,
                ['count' => $added],
            ));
        }
    }

    /**
     * Dismiss the proposed changes without applying them.
     */
    public function discardPipelineOptimization(): void
    {
        $this->authorize('update', $this->site);
        $this->clearPipelineOptimizePreview();
        $this->dispatch('close-modal', 'pipeline-optimize-preview');
    }

    private function clearPipelineOptimizePreview(): void
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['pipeline_optimize_preview']);
        $this->site->forceFill(['meta' => $meta])->save();
    }
}
