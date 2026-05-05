<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\SiteDeployment;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\InstalledStack;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Server workspace landing page — at-a-glance dashboard.
 *
 * Job is "is anything broken? what's running here?" Operator scans the
 * tiles and click-through cards, then drills into the dedicated sub-page
 * (sites, databases, monitor, deploys, insights) for detail.
 *
 * Heavy content lives on the dedicated sub-pages — this component is
 * intentionally thin. If you find yourself adding a 100-line panel here,
 * it probably belongs on the matching workspace nav entry instead.
 */
#[Layout('layouts.app')]
class WorkspaceOverview extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->toastError('This server is not ready for a setup re-run yet.');

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);
        unset($meta['provision_step_snapshots']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        $fresh = $server->fresh();
        WaitForServerSshReadyJob::dispatch($fresh ?? $server);

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    public function render(): View
    {
        $this->server->refresh();

        $sites = $this->server->sites()->get(['id', 'status']);
        $siteIds = $sites->pluck('id');

        $latestDeployment = $siteIds->isEmpty()
            ? null
            : SiteDeployment::query()
                ->with('site:id,name,server_id')
                ->whereIn('site_id', $siteIds)
                ->latest('created_at')
                ->first();

        $databaseSummary = [
            'count' => $this->server->serverDatabases()->count(),
            // 'errors' field intended for a future health probe; leaving the
            // shape stable so the view can always pull a 'X errors' badge.
            'errors' => 0,
        ];

        $openInsightsCount = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count();

        $criticalInsightsCount = InsightFinding::query()
            ->where('server_id', $this->server->id)
            ->whereNull('site_id')
            ->where('status', InsightFinding::STATUS_OPEN)
            ->where('severity', InsightFinding::SEVERITY_CRITICAL)
            ->count();

        $deployingCount = $sites
            ->whereIn('status', ['deploying', 'queued'])
            ->count();

        $healthSummary = [
            'status' => $this->server->health_status,
            'last_checked_at' => $this->server->last_health_check_at,
        ];

        $currentUser = Auth::user();
        $hasProfileSshKeys = $currentUser?->sshKeys()->exists() ?? false;
        $serverHasPersonalProfileKey = $this->server->hasPersonalUserSshKey($currentUser);

        $notificationSummary = [
            'channel_count' => $this->server->notificationSubscriptions()->distinct('notification_channel_id')->count('notification_channel_id'),
            'manage_url' => $this->server->organization_id
                ? route('profile.notification-channels.bulk-assign', ['server' => $this->server->id])
                : null,
        ];

        return view('livewire.servers.workspace-overview', [
            'siteCount' => $sites->count(),
            'deployingCount' => $deployingCount,
            'latestDeployment' => $latestDeployment,
            'databaseSummary' => $databaseSummary,
            'healthSummary' => $healthSummary,
            'containerLaunch' => $this->containerLaunchSummary(),
            'hasProfileSshKeys' => $hasProfileSshKeys,
            'serverHasPersonalProfileKey' => $serverHasPersonalProfileKey,
            'installedStack' => InstalledStack::fromMeta($this->server),
            'installedStackDiverges' => InstalledStack::fromMeta($this->server)->divergesFromRequest($this->server),
            'openInsightsCount' => $openInsightsCount,
            'criticalInsightsCount' => $criticalInsightsCount,
            'notificationSummary' => $notificationSummary,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function containerLaunchSummary(): ?array
    {
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $launch = is_array($meta['container_launch'] ?? null) ? $meta['container_launch'] : [];
        if ($launch === []) {
            return null;
        }

        $status = (string) ($launch['status'] ?? '');
        if ($status === '' || $status === 'completed') {
            return null;
        }

        $siteId = is_string($launch['site_id'] ?? null) ? $launch['site_id'] : null;
        $site = $siteId ? $this->server->sites()->with('domains')->find($siteId) : null;
        $events = collect(is_array($launch['events'] ?? null) ? $launch['events'] : [])
            ->filter(fn (mixed $event): bool => is_array($event) && is_string($event['message'] ?? null))
            ->take(-5)
            ->values()
            ->all();

        return [
            'status' => $status,
            'target_family' => (string) ($launch['target_family'] ?? 'container'),
            'repository_url' => (string) ($launch['repository_url'] ?? ''),
            'repository_branch' => (string) ($launch['repository_branch'] ?? ''),
            'repository_subdirectory' => (string) ($launch['repository_subdirectory'] ?? ''),
            'current_step_label' => (string) ($launch['current_step_label'] ?? 'Container launch in progress'),
            'summary' => (string) ($launch['summary'] ?? 'Dply is still preparing this container launch.'),
            'updated_at' => isset($launch['updated_at']) ? Carbon::parse((string) $launch['updated_at']) : null,
            'events' => $events,
            'site' => $site,
            'site_route' => $site ? route('sites.show', ['server' => $this->server, 'site' => $site]) : null,
        ];
    }
}
