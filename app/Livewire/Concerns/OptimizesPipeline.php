<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\OptimizeSitePipelineJob;
use App\Jobs\VerifySiteOctaneJob;
use App\Livewire\Sites\Concerns\ManagesSiteDeploySteps;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use App\Services\Sites\OctaneRuntimeVerifier;
use App\Support\Sites\SitePipelineAdvisor;
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
 * `seedQueuedConsoleAction()`, and {@see WatchesConsoleActionOutcomes}.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait OptimizesPipeline
{
    use DispatchesToastNotifications;
    use WatchesConsoleActionOutcomes;

    /**
     * Deferred (wire:init) trigger that confirms Octane is actually installed
     * and serving this site before the "Reload Octane workers" suggestion is
     * allowed to show. The advisor can't SSH from the render path, so it gates
     * on a cached verdict; this queues the probe that writes it — but only when
     * Octane is plausibly relevant (Laravel + laravel/octane in composer, VM
     * host) and the last verdict is missing or stale, so we don't SSH on every
     * page load.
     */
    public function ensureOctaneVerificationProbe(): void
    {
        $site = $this->site;
        $server = $site->server;
        if ($server === null || ! $server->isVmHost() || ! $site->isLaravelFrameworkDetected()) {
            return;
        }

        $detection = $site->resolvedRuntimeAppDetection() ?? [];
        if (empty($detection['laravel_octane'])) {
            return;
        }

        if (! OctaneRuntimeVerifier::isStale($site)) {
            return;
        }

        VerifySiteOctaneJob::dispatch((string) $site->id);
    }

    public function optimizePipeline(): void
    {
        $this->authorize('update', $this->site);

        $run = $this->seedQueuedConsoleAction('pipeline_optimize', __('Optimizing pipeline'));
        OptimizeSitePipelineJob::dispatch((string) $run->id, (string) $this->site->id);

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction(
            $run,
            __('Scan complete — review the proposed changes before applying.'),
            __('Pipeline optimize did not finish — see the output.'),
        );
        $this->toastConsoleActionQueued();
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

        $this->toastSuccess(trans_choice(
            '{0} No new steps to add.|{1} Added :count step to the pipeline.|[2,*] Added :count steps to the pipeline.',
            $added,
            ['count' => $added],
        ));
    }

    /**
     * Autofix one "Pipeline check" suggestion — drop its ready-made step into the
     * default pipeline. Lives here (not on the heavy {@see ManagesSiteDeploySteps})
     * so the Deploy hub — which doesn't carry the full pipeline-editing trait — can
     * offer per-suggestion fixes too. DB-only, runs inline, idempotent.
     */
    public function addSuggestedPipelineStep(string $key): void
    {
        $this->authorize('update', $this->site);

        $suggestion = collect(SitePipelineAdvisor::suggestions($this->site, true))
            ->firstWhere('key', $key);
        if ($suggestion === null) {
            return;
        }

        $pipelines = app(SiteDeployPipelineManager::class);
        $pipeline = $pipelines->ensureDefaultPipeline($this->site);

        // Don't double-add a step the pipeline already has.
        $existing = $pipeline->steps()->get();
        $isCustom = $suggestion['step_type'] === SiteDeployStep::TYPE_CUSTOM;
        $already = $isCustom
            ? $existing->where('step_type', SiteDeployStep::TYPE_CUSTOM)
                ->contains(static fn ($s): bool => strtolower((string) $s->custom_command) === strtolower((string) $suggestion['command']))
            : $existing->contains(static fn ($s): bool => (string) $s->step_type === $suggestion['step_type']);

        if (! $already) {
            $pipelines->addStep(
                $pipeline,
                $suggestion['step_type'],
                $suggestion['command'],
                900,
                null,
                $suggestion['phase'],
            );
        }

        // Recompute the advisor against the just-added step so the suggestion
        // clears on re-render (see applyPipelineOptimization for the why).
        $this->site->unsetRelation('deploySteps');

        $this->toastSuccess($already
            ? __('That step is already in the pipeline.')
            : __(':label added to the :phase phase.', ['label' => $suggestion['label'], 'phase' => $suggestion['phase']]));
    }

    /**
     * Hide a suggestion the operator doesn't want — persisted on meta so it stays
     * gone across renders. Reversible via {@see restorePipelineSuggestions()}.
     */
    public function dismissPipelineSuggestion(string $key): void
    {
        $this->authorize('update', $this->site);

        $meta = $this->site->meta;
        $meta[SitePipelineAdvisor::DISMISSED_META_KEY] = array_values(array_unique(array_merge(
            (array) ($meta[SitePipelineAdvisor::DISMISSED_META_KEY] ?? []),
            [$key],
        )));
        $this->site->forceFill(['meta' => $meta])->save();

        $this->toastSuccess(__('Suggestion dismissed.'));
    }

    /**
     * Bring back every dismissed pipeline suggestion.
     */
    public function restorePipelineSuggestions(): void
    {
        $this->authorize('update', $this->site);

        $meta = $this->site->meta;
        unset($meta[SitePipelineAdvisor::DISMISSED_META_KEY]);
        $this->site->forceFill(['meta' => $meta])->save();

        $this->toastSuccess(__('Dismissed suggestions restored.'));
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
        $meta = $this->site->meta;
        unset($meta['pipeline_optimize_preview']);
        $this->site->forceFill(['meta' => $meta])->save();
    }
}
