<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeBuildSettings;
use App\Livewire\Concerns\Edge\ManagesEdgeDeployCommit;
use App\Livewire\Concerns\Edge\ManagesEdgePreviews;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\ManagesEdgeDeploymentLifecycle;
use App\Livewire\Forms\EdgeBuildSettingsForm;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Previews extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesEdgeBuildSettings;
    use ManagesEdgeDeployCommit;
    use ManagesEdgeDeploymentLifecycle;
    use ManagesEdgePreviews;
    use MountsEdgeWorkspaceSection;

    public EdgeBuildSettingsForm $buildForm;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->site->loadMissing('edgeSiteAccessRule');
        $this->mountEdgeBuildSettings($site);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.previews', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-previews'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        ));
    }
}
