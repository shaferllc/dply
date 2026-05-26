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
            $deployment = (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        // Send the user to the live deployment-detail page so they see
        // the build log streaming + status transitions instead of being
        // left on the form with just a toast.
        $this->redirectRoute('sites.edge.deployments.show', [
            'server' => $this->site->server_id,
            'site' => $this->site->id,
            'deployment' => $deployment->id,
        ], navigate: true);
    }
}
