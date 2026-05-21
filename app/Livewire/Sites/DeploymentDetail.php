<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\SiteSettingsSidebar;
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

        // Render whichever phases the deployment actually recorded. VM deploys
        // use build/swap/release/restart; serverless deploys record a single
        // "serverless" phase. Known phases come first in their canonical order,
        // then any others fall in afterwards so nothing is silently dropped.
        $canonicalOrder = ['build', 'swap', 'release', 'restart', 'serverless'];
        $phases = array_values(array_unique([
            ...array_filter($canonicalOrder, static fn (string $p): bool => isset($phaseResults[$p])),
            ...array_keys($phaseResults),
        ]));

        $runtimeMode = $this->site->runtimeTargetMode();

        return view('livewire.sites.deployment-detail', [
            'phaseResults' => $phaseResults,
            'phases' => $phases,
            // Sidebar context — keeps the workspace nav visible so operators
            // can pivot between deployment history and the rest of the site.
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'deploy',
        ])->layout('layouts.app');
    }
}
