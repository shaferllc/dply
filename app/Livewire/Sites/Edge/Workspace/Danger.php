<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDanger;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Danger extends Component
{
    use DispatchesToastNotifications;
    use ManagesEdgeDanger;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.danger', array_merge(
            EdgeSiteViewData::context($this->site, 'danger'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        ));
    }
}
