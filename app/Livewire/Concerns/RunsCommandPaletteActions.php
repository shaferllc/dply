<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Jobs\RedeployCloudSiteJob;
use App\Modules\Insights\Jobs\RunServerInsightsJob;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsCommandPaletteActions
{


    /**
     * Run an action row. Actions *do* something (dispatch a job, switch org)
     * rather than navigate; the view routes here via wire:click. Every action
     * re-resolves and re-authorizes its target server-side — the rendered row
     * is a convenience, never the authority.
     */
    public function run(string $key, ?string $id = null): mixed
    {
        $org = auth()->user()?->currentOrganization();

        return match ($key) {
            'site.deploy' => $this->runSiteDeploy($org, $id),
            'site.redeploy' => $this->runSiteRedeploy($org, $id),
            'site.deploy-sync' => $this->runDeploySync($org, $id),
            'server.insights' => $this->runServerInsights($org, $id),
            'org.switch' => $this->runOrgSwitch($id),
            default => null,
        };
    }

    /**
     * Tick a peer in or out of the "Deploy together" selection. Fires on its own
     * (no stack change, no cmdk-changed) so the palette stays open and the
     * keyboard cursor holds its place while the operator builds the set.
     */
    public function toggleDeploySync(string $id): void
    {
        $id = (string) $id;

        $this->deploySyncSelected = in_array($id, $this->deploySyncSelected, true)
            ? array_values(array_filter($this->deploySyncSelected, fn (string $v): bool => $v !== $id))
            : [...$this->deploySyncSelected, $id];
    }

    /** Queue a manual deployment for a VM-hosted, org-scoped site. */
    private function runSiteDeploy(?Organization $org, ?string $id): void
    {
        $site = $this->scopedSite($org, $id);
        if ($site === null) {
            return;
        }
        Gate::authorize('update', $site);

        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
        $this->dispatch('notify', message: __('Deployment queued for :site.', ['site' => $site->name]));
    }

    /** Roll a new container deployment for an org-scoped cloud/container site. */
    private function runSiteRedeploy(?Organization $org, ?string $id): void
    {
        $site = $this->scopedSite($org, $id);
        if ($site === null || ! $site->usesContainerRuntime()) {
            return;
        }
        Gate::authorize('update', $site);

        RedeployCloudSiteJob::dispatch($site->id);
        $this->dispatch('notify', message: __('Redeploy queued for :site.', ['site' => $site->name]));
    }

    /**
     * Deploy every site ticked in the "Deploy together" context — the anchor
     * site and any repo-sharing peers (e.g. its worker) — each on its own job,
     * exactly like pressing its own Deploy button. Re-resolves and re-authorizes
     * every target; the optimistic deploy-active marker mirrors the Sync panel so
     * each site's console shows "deploying" the instant it's queued.
     */
    private function runDeploySync(?Organization $org, ?string $id): void
    {
        $anchor = $this->scopedSite($org, $id);
        if ($anchor === null) {
            return;
        }

        $peers = $this->deploySyncPeers($anchor)->keyBy(fn (Site $site): string => (string) $site->id);
        $ids = array_values(array_intersect(
            array_map('strval', $this->deploySyncSelected),
            $peers->keys()->all()
        ));
        if ($ids === []) {
            return;
        }

        $queued = 0;
        foreach ($ids as $siteId) {
            $site = $peers->get($siteId);
            if ($site === null || ! Gate::allows('update', $site)) {
                continue;
            }

            if ($site->usesContainerRuntime()) {
                RedeployCloudSiteJob::dispatch($site->id);
            } else {
                Cache::put('site-deploy-active:'.$site->id, [
                    'started_at' => now()->toIso8601String(),
                    'deployment_id' => null,
                ], 600);
                RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
            }
            $queued++;
        }

        $this->dispatch('notify', message: trans_choice(
            '{1}Deployment queued for :count site.|[2,*]Deployments queued for :count sites.',
            $queued,
            ['count' => $queued],
        ));
    }

    /**
     * Sites that deploy *with* the given one — itself plus any peers sharing its
     * git repository (or, for repo-less sites, its server). This is the same
     * grouping the Deployments "Sync deploy" panel uses; filtered to peers the
     * operator may deploy so the palette never offers an unactionable row.
     *
     * @return Collection<int, Site>
     */
    private function deploySyncPeers(Site $site): Collection
    {
        $repo = trim((string) $site->git_repository_url);

        return Site::query()
            ->where('organization_id', $site->organization_id)
            ->where(function ($where) use ($repo, $site): void {
                $where->where('id', $site->id);
                if ($repo !== '') {
                    $where->orWhere('git_repository_url', $repo);
                } else {
                    $where->orWhere('server_id', $site->server_id);
                }
            })
            ->with('server')
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $peer): bool => Gate::allows('update', $peer))
            ->values();
    }

    /** Queue a full server insights scan; findings land on the Insights tab. */
    private function runServerInsights(?Organization $org, ?string $id): void
    {
        $server = $this->scopedServer($org, $id);
        if ($server === null) {
            return;
        }
        Gate::authorize('view', $server);

        RunServerInsightsJob::dispatch($server->id, null, (string) Str::ulid());
        $this->dispatch('notify', message: __('Insights scan queued for :server.', ['server' => $server->name]));
    }

    /**
     * The deploy action row for a site — "Redeploy" for container/cloud sites
     * (their roll uses a different job) or "Deploy now" for VM sites. Returns
     * null when the user can't deploy it. `$withName` labels it with the site
     * name for the shared root search; the in-context row stays terse.
     *
     * @return array<string, mixed>|null
     */
    private function siteDeployAction(Site $site, bool $withName): ?array
    {
        if (! Gate::allows('update', $site)) {
            return null;
        }
        $container = $site->usesContainerRuntime();

        return [
            'label' => $withName
                ? ($container ? __('Redeploy :site', ['site' => $site->name]) : __('Deploy :site', ['site' => $site->name]))
                : ($container ? __('Redeploy') : __('Deploy now')),
            'sublabel' => $withName
                ? $site->server?->name
                : ($container ? __('Roll a new container deployment') : __('Queue a manual deployment')),
            'action' => ['key' => $container ? 'site.redeploy' : 'site.deploy', 'id' => $site->id],
            'icon' => 'arrows-right-left',
            'confirm' => true,
        ];
    }

    /** Switch the active organization (membership-checked) and reload. */
    private function runOrgSwitch(?string $id): mixed
    {
        $user = auth()->user();
        if ($id === null || $user === null) {
            return null;
        }
        if (! $user->organizations()->where('organizations.id', $id)->exists()) {
            return null; // not a member — render is stale or tampered
        }

        Session::put('current_organization_id', $id);
        Session::forget('current_team_id');
        Session::flash('success', __('Organization switched.'));

        return $this->redirect(route('dashboard'), navigate: true);
    }
}
