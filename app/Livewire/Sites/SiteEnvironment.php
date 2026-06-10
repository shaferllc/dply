<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\ManagesSiteEnvironment;
use App\Livewire\Sites\Concerns\SurfacesDeploymentRemediation;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Standalone Environment page — a first-class workspace section, no longer
 * buried inside the Deployments hub's tab strip. It reuses the exact same
 * environment editor partial (and the traits it needs) that the Deploy hub
 * used, rendered in the normal site sidebar chrome so it sits next to
 * Resources.
 *
 * The editor is trait-heavy (env CRUD + sync/push, resource bindings, the
 * missing-env remediation surface, console-action banners), so rather than
 * bolt all of that onto the already-large {@see Settings} component we give it
 * a focused home here.
 */
class SiteEnvironment extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesSiteBindings;
    use ManagesSiteEnvironment;
    use SurfacesDeploymentRemediation;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
    }

    public function render(): View
    {
        // The env editor's missing-var gate + suggested fixes read the
        // deployment contract/preflight (same as the Deploy hub did for its
        // Environment tab).
        $contract = app(DeploymentContractBuilder::class)->build($this->site);
        // Pass the contract we just built — validate() rebuilds it otherwise,
        // doubling the most expensive piece of every render on this page.
        $preflight = app(DeploymentPreflightValidator::class)->validate($this->site, $contract);

        return view('livewire.sites.site-environment', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'environment',
                $contract,
                $preflight,
                auth()->user(),
            ),
            [
                'section' => 'environment',
                'routingTab' => 'domains',
                'laravel_tab' => 'commands',
            ],
        ))->layout('layouts.app');
    }
}
