<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Support\Docs\ContextualDocResolver;
use App\Support\Sites\SiteWorkspaceBreadcrumbs;
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
        $canonicalOrder = ['clone', 'build', 'swap', 'activate', 'release', 'restart', 'serverless'];
        $phases = array_values(array_unique([
            ...array_filter($canonicalOrder, static fn (string $p): bool => isset($phaseResults[$p])),
            ...array_keys($phaseResults),
        ]));

        $runtimeMode = $this->site->runtimeTargetMode();

        // Build only the chrome this view actually uses — the Deploy sidebar,
        // the workspace breadcrumb trail, and the per-deployment content.
        // (Deliberately NOT SiteSettingsViewData::for(): that assembles ~130
        // view vars for the full settings workspace, almost none of which
        // this page touches.)
        $breadcrumbs = SiteWorkspaceBreadcrumbs::items($this->server, $this->site, __('Deploy'), 'rocket-launch');
        // Link the trailing "Deploy" crumb back to the deploy hub…
        $lastKey = array_key_last($breadcrumbs);
        $breadcrumbs[$lastKey]['href'] = route('sites.deployments.index', [
            'server' => $this->server,
            'site' => $this->site,
            'tab' => 'history',
        ]);
        // …then add this deployment as the current (non-linked) crumb.
        $breadcrumbs[] = [
            'label' => $this->deployment->id,
            'icon' => 'rocket-launch',
        ];

        return view('livewire.sites.deployment-detail', [
            'phaseResults' => $phaseResults,
            'phases' => $phases,
            // Deploy workspace chrome (sidebar + breadcrumb trail).
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'settingsBreadcrumbs' => $breadcrumbs,
            'contextualDocSlug' => app(ContextualDocResolver::class)->resolveForSiteSection($this->site, 'deploy'),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'deploy',
        ])->layout('layouts.app');
    }
}
