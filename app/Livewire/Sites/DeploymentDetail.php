<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Permalink-friendly view for a single deployment's phase + step
 * tree. Renders the same data dply:site:show-deploy prints, but in
 * the dashboard so operators can bookmark and share the URL.
 *
 * Authorization: same rules as the parent site page — the user
 * must be in the site's organization.
 */
class DeploymentDetail extends Component
{
    public Server $server;

    public Site $site;

    public SiteDeployment $deployment;

    public bool $showOutput = false;

    public function mount(Server $server, Site $site, SiteDeployment $deployment): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }
        if ($deployment->site_id !== $site->id) {
            abort(404);
        }
        if ($server->organization_id !== auth()->user()?->currentOrganization()?->id) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
        $this->deployment = $deployment;
    }

    public function toggleOutput(): void
    {
        $this->showOutput = ! $this->showOutput;
    }

    public function render(): View
    {
        $phaseResults = is_array($this->deployment->phase_results ?? null)
            ? $this->deployment->phase_results
            : [];

        return view('livewire.sites.deployment-detail', [
            'phaseResults' => $phaseResults,
            'phases' => ['build', 'swap', 'release', 'restart'],
        ])->layout('layouts.app');
    }
}
