<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeDomains;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Domains extends Component
{
    use DispatchesToastNotifications;
    use ManagesEdgeDomains;
    use MountsEdgeWorkspaceSection;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);

        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->orderByDesc('created_at')->limit(20),
        ]);
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.domains', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-domains'),
            [
                'server' => $this->server,
                'site' => $this->site,
            ],
        ));
    }
}
