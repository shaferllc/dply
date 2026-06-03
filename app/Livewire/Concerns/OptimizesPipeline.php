<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\OptimizeSitePipelineJob;
use App\Livewire\Sites\Concerns\ManagesSiteDeploySteps;
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
                __('Pipeline optimized — review the new steps on the Pipeline tab.'),
                __('Pipeline optimize did not finish — see the output.'),
            );
        }
        if (method_exists($this, 'toastConsoleActionQueued')) {
            $this->toastConsoleActionQueued();
        }
    }
}
