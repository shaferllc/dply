<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Sites\Concerns\InteractsWithScaffoldJourney;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Legacy standalone progress page for an in-flight scaffold pipeline.
 *
 * The product flow now renders the same pipeline inside the site workspace
 * shell via {@see \App\Livewire\Sites\Show} (the scaffold-install partial),
 * so create/choose-app land on `sites.show`. This route is kept for
 * back-compat / deep links. All behaviour lives in
 * {@see InteractsWithScaffoldJourney}; this class only adds the page chrome.
 */
#[Layout('layouts.app')]
class ScaffoldJourney extends Component
{
    use InteractsWithScaffoldJourney;

    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;

        if (! $this->siteIsScaffolded()) {
            abort(404);
        }
    }

    public function render(): View
    {
        $this->site->refresh();

        return view('livewire.sites.scaffold-journey', $this->scaffoldJourneyData());
    }
}
