<?php

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;

class Settings extends Show
{
    public string $section = 'general';

    public function mount(Server $server, Site $site, ?string $section = null): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        if ($section === null) {
            $this->redirect(route('sites.settings', ['server' => $server, 'site' => $site, 'section' => 'general']), navigate: true);

            return;
        }

        $allowed = array_keys(config('site_settings.workspace_tabs', []));

        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        parent::mount($server, $site);
    }

    public function render(): View
    {
        $this->site->load([
            'domains',
            'environmentVariables',
            'redirects',
            'deployHooks',
            'deploySteps',
            'workspace.variables',
        ]);

        return view('livewire.sites.settings', [
            'tabs' => config('site_settings.workspace_tabs', []),
            'deployHookUrl' => $this->site->deployHookUrl(),
            'sitePhpData' => $this->server->hostCapabilities()->supportsMachinePhpManagement()
                ? app(\App\Services\Servers\ServerPhpManager::class)->sitePhpData($this->server, $this->site)
                : null,
        ]);
    }
}
