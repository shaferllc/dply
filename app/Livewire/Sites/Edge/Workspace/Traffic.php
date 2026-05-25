<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Traffic extends Component
{
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
        return view('livewire.sites.edge.workspace.traffic', SiteSettingsViewData::for(
            $this->server,
            $this->site,
            'edge-traffic',
            null,
            [],
            auth()->user(),
        ));
    }
}
