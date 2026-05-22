<?php

namespace App\Livewire\Concerns;

use Laravel\Pennant\Feature;

/**
 * Defense-in-depth flag check on every Livewire request (mount + hydrate).
 * Route middleware (`feature:X`) is the primary enforcement; this trait
 * catches the residual cases — child components rendered outside their
 * route, stale Livewire connections after a flag flip, or any future page
 * that forgets the middleware.
 *
 * Usage:
 *
 *   class WorkspaceCluster extends Component
 *   {
 *       use RequiresFeature;
 *       protected string $requiredFeature = 'workspace.cluster';
 *   }
 *
 * Livewire auto-runs `bootedRequiresFeature` on every request because the
 * method name matches the trait name — see Livewire's lifecycle docs.
 */
trait RequiresFeature
{
    public function bootedRequiresFeature(): void
    {
        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }
}
