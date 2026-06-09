<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Actions\Edge\RedeployEdgeSite;
use App\Models\Site;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeRedeploy
{
    public function redeployEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        // Stay on the current workspace page (overview / deploys list).
        // The overview live-progress card and the deploys list both
        // wire:poll, so the new build's status shows up in place —
        // no need to bounce the user to the deployment-detail page.
        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Deploy queued.'));
        }
    }
}
