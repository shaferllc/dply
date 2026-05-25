<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Livewire\Concerns\Edge\ManagesEdgeDanger;
use App\Livewire\Concerns\Edge\ManagesEdgeDeployCommit;
use App\Livewire\Concerns\Edge\ManagesEdgeDomains;
use App\Livewire\Concerns\Edge\ManagesEdgeLogs;
use App\Livewire\Concerns\Edge\ManagesEdgePreviews;
use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;

/**
 * Edge dashboard actions for Sites\Settings — redeploy, rollback,
 * preview teardown, custom domains. Mirrors {@see ManagesContainerSite}.
 *
 * Build settings live in {@see Edge\ManagesEdgeBuildSettings} on the
 * Build workspace section component.
 */
trait ManagesEdgeSite
{
    use ManagesEdgeDanger;
    use ManagesEdgeDeployCommit;
    use ManagesEdgeDeploymentLifecycle;
    use ManagesEdgeDomains;
    use ManagesEdgeLogs;
    use ManagesEdgePreviews;
    use ManagesEdgeRedeploy;
}
