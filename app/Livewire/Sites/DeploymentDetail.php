<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\ApplyRemediationJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Remediations\RemediationCatalog;
use App\Support\Docs\ContextualDocResolver;
use App\Support\Sites\SiteWorkspaceBreadcrumbs;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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
    use DispatchesToastNotifications;

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

    /**
     * The recognized remediation for this deployment's failure, if any — drives
     * the "Fix" panel on a failed deploy. Null when the deploy didn't fail or no
     * catalog signature matches the failure output.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function remediation(): ?array
    {
        if ($this->deployment->status !== SiteDeployment::STATUS_FAILED) {
            return null;
        }

        return app(RemediationCatalog::class)->match($this->failureText());
    }

    /** Failure output to match against — the overall log plus any step outputs. */
    private function failureText(): string
    {
        $parts = [(string) $this->deployment->log_output];

        $phaseResults = is_array($this->deployment->phase_results ?? null) ? $this->deployment->phase_results : [];
        array_walk_recursive($phaseResults, function ($value) use (&$parts): void {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }

    /** Latest non-dismissed fix run for this site, for the in-page progress banner. */
    #[Computed]
    public function remediationRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'remediation_apply')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /** Queue a remediation action for the matched failure. */
    public function applyRemediation(string $actionKey): void
    {
        Gate::authorize('update', $this->site);

        $remediation = $this->remediation();
        $catalog = app(RemediationCatalog::class);
        if ($remediation === null || $catalog->action((string) $remediation['code'], $actionKey) === null) {
            $this->toastError(__('That fix is no longer available.'));

            return;
        }

        ApplyRemediationJob::dispatch(
            (string) $this->server->id,
            (string) $this->site->id,
            (string) $remediation['code'],
            $actionKey,
            (string) (auth()->id() ?? '') ?: null,
        );

        unset($this->remediationRun);
        $this->toastSuccess(__('Applying the fix — progress shows below. Re-deploy once it finishes.'));
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
