<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class OverviewObservability extends Component
{
    use MountsEdgeWorkspaceSection;

    public bool $observabilityLoaded = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function loadObservabilityCards(): void
    {
        $this->observabilityLoaded = true;
    }

    public function render(): View
    {
        $analytics = $this->observabilityLoaded
            ? SiteSettingsViewData::edgeOverviewObservability($this->site)
            : [
                'edgeUsageBillingEnabled' => (bool) config('dply.edge.usage_billing.enabled', false),
                'edgeManagedFee' => ((int) config('subscription.standard.edge_cents', 0)) / 100,
                'edgeSiteBilling' => null,
                'edgeSiteTraffic' => null,
            ];

        return view('livewire.sites.edge.workspace.overview-observability', array_merge(
            $analytics,
            EdgeSiteViewData::context($this->site, 'general'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'observabilityLoaded' => $this->observabilityLoaded,
            ],
        ));
    }
}
