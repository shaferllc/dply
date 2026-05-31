<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\ManagesSiteSystemdProcesses;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteSystemdUnitBuilder;
use App\Support\Docs\ContextualDocResolver;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSystemd extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesSiteSystemdProcesses;

    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site->load('processes');

        $preset = request()->query('preset');
        if (Gate::allows('update', $site)
            && is_string($preset)
            && is_array(config("site_systemd_presets.{$preset}"))) {
            $this->applySystemdPreset($preset);
        }

        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, ['units', 'preview'], true)) {
            $this->services_workspace_tab = $tab;
        }
    }

    public function render(): View
    {
        $builder = app(SiteSystemdUnitBuilder::class);
        $supportsSystemd = Site::supportsSystemdServices($this->site, $this->server);
        $webUnitName = $builder->webUnitName($this->site);

        return view('livewire.sites.workspace-systemd', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'services',
                null,
                [],
                auth()->user(),
            ),
            [
                'section' => 'services',
                'routingTab' => 'domains',
                'laravel_tab' => 'commands',
                'supportsSystemd' => $supportsSystemd,
                'webUnitName' => $webUnitName,
                'systemdPresets' => config('site_systemd_presets', []),
                'unitPreviews' => $supportsSystemd ? $this->buildAllUnitPreviews() : [],
                'contextualDocSlug' => app(ContextualDocResolver::class)->resolveForSiteSection($this->site, 'services'),
            ],
        ));
    }
}
