<?php

namespace App\Modules\Projects\Livewire;

use App\Jobs\RunWorkspaceDeployJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\AuditLog;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDeployRun;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceMember;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Modules\Projects\Services\WorkspaceHealthSummaryService;
use App\Modules\Projects\Services\WorkspaceNotificationDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.projects';

    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public Workspace $workspace;

    public string $section = 'overview';

    public string $editName = '';

    public string $editDescription = '';

    public string $editNotes = '';

    public ?string $serverToAttach = null;

    public ?string $siteToAttach = null;

    public ?string $memberUserId = null;

    public string $memberRole = WorkspaceMember::ROLE_VIEWER;

    public string $environmentName = '';

    public string $environmentDescription = '';

    public string $labelName = '';

    public string $labelColor = 'slate';

    public string $runbookTitle = '';

    public string $runbookUrl = '';

    public string $runbookBody = '';

    public string $variableKey = '';

    public string $variableValue = '';

    public bool $variableIsSecret = true;

    public array $selectedProjectChannelIds = [];

    public array $selectedProjectEventKeys = [];

    public array $selectedDeploySiteIds = [];

    public function mount(Workspace $workspace, string $section = 'overview'): void
    {
        if ($workspace->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        $this->authorize('view', $workspace);

        abort_unless(in_array($section, ['overview', 'resources', 'access', 'operations', 'delivery'], true), 404);

        $this->section = $section;
        // Relations are eager-loaded once in render(); don't double-load
        // servers/sites here only for render() to discard and reload them.
        $this->workspace = $workspace;
        $this->editName = $workspace->name;
        $this->editDescription = (string) ($workspace->description ?? '');
        $this->editNotes = (string) ($workspace->notes ?? '');
        $this->selectedDeploySiteIds = $workspace->sites()->pluck('id')->all();
        $this->selectedProjectEventKeys = $workspace->notificationSubscriptions()
            ->pluck('event_key')
            ->unique()
            ->values()
            ->all();
        $this->selectedProjectChannelIds = $workspace->notificationSubscriptions()
            ->pluck('notification_channel_id')
            ->unique()
            ->values()
            ->all();
    }

    public function saveDetails(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'editName' => 'required|string|max:120',
            'editDescription' => 'nullable|string|max:2000',
            'editNotes' => 'nullable|string|max:20000',
        ]);

        $before = $this->workspace->only(['name', 'description', 'notes']);
        $this->workspace->update([
            'name' => $this->editName,
            'description' => $this->editDescription !== '' ? $this->editDescription : null,
            'notes' => $this->editNotes !== '' ? $this->editNotes : null,
        ]);

        $this->workspace->refresh();
        $this->editName = $this->workspace->name;
        $this->editDescription = (string) ($this->workspace->description ?? '');
        $this->editNotes = (string) ($this->workspace->notes ?? '');

        audit_log(
            $this->workspace->organization,
            auth()->user(),
            'project.updated',
            $this->workspace,
            $before,
            $this->workspace->only(['name', 'description', 'notes'])
        );

        $this->toastSuccess(__('Project updated.'));
    }

    public function attachServer(): void
    {
        $this->authorize('update', $this->workspace);

        if (! $this->serverToAttach) {
            return;
        }

        $server = Server::query()->findOrFail($this->serverToAttach);
        if ($server->organization_id !== $this->workspace->organization_id) {
            abort(403);
        }

        $this->authorize('update', $server);
        $server->update(['workspace_id' => $this->workspace->id]);
        $this->serverToAttach = null;
        $this->workspace->load(['servers', 'sites']);

        audit_log($this->workspace->organization, auth()->user(), 'project.server_attached', $this->workspace, null, [
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]);

        $this->toastSuccess(__('Server added to project.'));
    }

    public function detachServer(int $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        if ($server->workspace_id !== $this->workspace->id) {
            abort(404);
        }

        $this->authorize('update', $server);
        $server->update(['workspace_id' => null]);
        $this->workspace->load(['servers', 'sites']);

        audit_log($this->workspace->organization, auth()->user(), 'project.server_detached', $this->workspace, null, [
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]);

        $this->toastSuccess(__('Server removed from project.'));
    }

    public function attachSite(): void
    {
        $this->authorize('update', $this->workspace);

        if (! $this->siteToAttach) {
            return;
        }

        $site = Site::query()->findOrFail($this->siteToAttach);
        if ($site->organization_id !== $this->workspace->organization_id) {
            abort(403);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => $this->workspace->id]);
        $this->siteToAttach = null;
        $this->workspace->load(['servers', 'sites']);

        audit_log($this->workspace->organization, auth()->user(), 'project.site_attached', $this->workspace, null, [
            'site_id' => $site->id,
            'site_name' => $site->name,
        ]);

        $this->toastSuccess(__('Site added to project.'));
    }

    public function detachSite(int $siteId): void
    {
        $site = Site::query()->findOrFail($siteId);
        if ($site->workspace_id !== $this->workspace->id) {
            abort(404);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => null]);
        $this->workspace->load(['servers', 'sites']);

        audit_log($this->workspace->organization, auth()->user(), 'project.site_detached', $this->workspace, null, [
            'site_id' => $site->id,
            'site_name' => $site->name,
        ]);

        $this->toastSuccess(__('Site removed from project.'));
    }

    public function addMember(): void
    {
        abort_unless($this->workspace->userCanManageMembers(auth()->user()), 403);

        $this->validate([
            'memberUserId' => 'required|string',
            'memberRole' => ['required', Rule::in(WorkspaceMember::roles())],
        ]);

        $user = User::query()->findOrFail($this->memberUserId);
        abort_unless($this->workspace->organization->hasMember($user), 403);

        // Guard the upsert: this action only ADDS new members. Re-adding an
        // existing member (e.g. yourself) would silently overwrite their role —
        // an owner could demote themselves to viewer. Block it.
        if ($this->workspace->members()->where('user_id', $user->id)->exists()) {
            $this->addError('memberUserId', __('That person is already a member of this project.'));

            return;
        }

        $membership = $this->workspace->members()->create([
            'user_id' => $user->id,
            'role' => $this->memberRole,
        ]);

        audit_log($this->workspace->organization, auth()->user(), 'project.member_updated', $this->workspace, null, [
            'member_id' => $user->id,
            'member_name' => $user->name,
            'role' => $membership->role,
        ]);

        app(WorkspaceNotificationDispatcher::class)->notify(
            $this->workspace,
            'project.activity',
            '['.config('app.name').'] '.$this->workspace->name.' membership updated',
            $user->name.' now has the '.$membership->role.' role on '.$this->workspace->name.'.',
            route('projects.access', $this->workspace, absolute: true),
            __('Open project')
        );

        $this->memberUserId = null;
        $this->memberRole = WorkspaceMember::ROLE_VIEWER;
        $this->toastSuccess(__('Project member saved.'));
    }

    public function removeMember(string $memberId): void
    {
        abort_unless($this->workspace->userCanManageMembers(auth()->user()), 403);

        $member = $this->workspace->members()->findOrFail($memberId);

        if ($member->role === WorkspaceMember::ROLE_OWNER && $this->workspace->members()->where('role', WorkspaceMember::ROLE_OWNER)->count() <= 1) {
            $this->addError('memberUserId', __('Projects must keep at least one owner.'));

            return;
        }

        $name = $member->user?->name ?? 'member';
        $member->delete();

        audit_log($this->workspace->organization, auth()->user(), 'project.member_removed', $this->workspace, null, [
            'member_name' => $name,
        ]);

        $this->toastSuccess(__('Project member removed.'));
    }

    public function addEnvironment(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'environmentName' => 'required|string|max:120',
            'environmentDescription' => 'nullable|string|max:1000',
        ]);

        $baseSlug = Str::slug($this->environmentName) ?: 'environment';
        $slug = $baseSlug;
        $index = 1;
        while ($this->workspace->environments()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$index;
            $index++;
        }

        $environment = $this->workspace->environments()->create([
            'name' => $this->environmentName,
            'slug' => $slug,
            'description' => $this->environmentDescription !== '' ? $this->environmentDescription : null,
            'sort_order' => ((int) $this->workspace->environments()->max('sort_order')) + 1,
        ]);

        audit_log($this->workspace->organization, auth()->user(), 'project.environment_added', $this->workspace, null, [
            'environment' => $environment->name,
        ]);

        $this->reset('environmentName', 'environmentDescription');
        $this->toastSuccess(__('Environment added.'));
    }

    public function removeEnvironment(string $environmentId): void
    {
        $this->authorize('update', $this->workspace);

        $environment = $this->workspace->environments()->findOrFail($environmentId);
        $name = $environment->name;
        $environment->delete();

        audit_log($this->workspace->organization, auth()->user(), 'project.environment_removed', $this->workspace, null, [
            'environment' => $name,
        ]);

        $this->toastSuccess(__('Environment removed.'));
    }

    public function createLabel(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'labelName' => 'required|string|max:120',
            'labelColor' => 'required|string|max:24',
        ]);

        $slugBase = Str::slug($this->labelName) ?: 'label';
        $slug = $slugBase;
        $index = 1;
        while (WorkspaceLabel::query()->where('organization_id', $this->workspace->organization_id)->where('slug', $slug)->exists()) {
            $slug = $slugBase.'-'.$index;
            $index++;
        }

        $label = WorkspaceLabel::query()->create([
            'organization_id' => $this->workspace->organization_id,
            'name' => $this->labelName,
            'slug' => $slug,
            'color' => $this->labelColor,
        ]);

        $this->workspace->labels()->syncWithoutDetaching([$label->id]);
        $this->reset('labelName');
        $this->toastSuccess(__('Label created and attached.'));
    }

    public function toggleLabel(string $labelId): void
    {
        $this->authorize('update', $this->workspace);

        if ($this->workspace->labels()->whereKey($labelId)->exists()) {
            $this->workspace->labels()->detach($labelId);
        } else {
            $this->workspace->labels()->attach($labelId);
        }
    }

    public function addRunbook(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'runbookTitle' => 'required|string|max:160',
            'runbookUrl' => 'nullable|url|max:500',
            'runbookBody' => 'nullable|string|max:5000',
        ]);

        $this->workspace->runbooks()->create([
            'title' => $this->runbookTitle,
            'url' => $this->runbookUrl !== '' ? $this->runbookUrl : null,
            'body' => $this->runbookBody !== '' ? $this->runbookBody : null,
            'sort_order' => ((int) $this->workspace->runbooks()->max('sort_order')) + 1,
        ]);

        $this->reset('runbookTitle', 'runbookUrl', 'runbookBody');
        $this->toastSuccess(__('Runbook saved.'));
    }

    public function removeRunbook(string $runbookId): void
    {
        $this->authorize('update', $this->workspace);
        $this->workspace->runbooks()->findOrFail($runbookId)->delete();
        $this->toastSuccess(__('Runbook removed.'));
    }

    public function saveVariable(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'variableKey' => 'required|string|max:120',
            'variableValue' => 'nullable|string|max:5000',
        ]);

        $this->workspace->variables()->updateOrCreate(
            ['env_key' => strtoupper($this->variableKey)],
            [
                'env_value' => $this->variableValue !== '' ? $this->variableValue : null,
                'is_secret' => $this->variableIsSecret,
            ]
        );

        $this->toastSuccess(__('Project variable saved.'));
        $this->reset('variableKey', 'variableValue');
        $this->variableIsSecret = true;
    }

    public function deleteVariable(string $variableId): void
    {
        $this->authorize('update', $this->workspace);
        $this->workspace->variables()->findOrFail($variableId)->delete();
        $this->toastSuccess(__('Project variable removed.'));
    }

    public function saveNotifications(): void
    {
        $this->authorize('update', $this->workspace);

        $this->validate([
            'selectedProjectChannelIds' => 'array',
            'selectedProjectChannelIds.*' => 'string',
            'selectedProjectEventKeys' => 'array',
            'selectedProjectEventKeys.*' => ['string', Rule::in(['project.deployments', 'project.health', 'project.activity'])],
        ]);

        NotificationSubscription::query()
            ->where('subscribable_type', Workspace::class)
            ->where('subscribable_id', $this->workspace->id)
            ->delete();

        foreach ($this->selectedProjectChannelIds as $channelId) {
            foreach ($this->selectedProjectEventKeys as $eventKey) {
                NotificationSubscription::query()->firstOrCreate([
                    'notification_channel_id' => $channelId,
                    'subscribable_type' => Workspace::class,
                    'subscribable_id' => $this->workspace->id,
                    'event_key' => $eventKey,
                ]);
            }
        }

        $this->toastSuccess(__('Project notification routing updated.'));
    }

    public function queueWorkspaceDeploy(): void
    {
        abort_unless($this->workspace->userCanDeploy(auth()->user()), 403);

        $siteIds = array_values(array_filter($this->selectedDeploySiteIds));
        if ($siteIds === []) {
            $this->addError('selectedDeploySiteIds', __('Choose at least one site to deploy.'));

            return;
        }

        $run = WorkspaceDeployRun::query()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => auth()->id(),
            'status' => WorkspaceDeployRun::STATUS_QUEUED,
            'site_ids' => $siteIds,
        ]);

        audit_log($this->workspace->organization, auth()->user(), 'project.deploy.queued', $this->workspace, null, [
            'workspace_deploy_run_id' => $run->id,
            'site_ids' => $siteIds,
        ]);

        RunWorkspaceDeployJob::dispatch($run->id);
        $this->toastSuccess(__('Project deploy queued.'));
    }

    public function destroyWorkspace(): void
    {
        $this->authorize('delete', $this->workspace);

        $this->workspace->delete();
        $this->toastSuccess(__('Project deleted. Servers and sites are unchanged but no longer grouped.'));

        $this->redirect(route('projects.index'), navigate: true);
    }

    protected function activityUrl(AuditLog $event): ?string
    {
        if (str_starts_with($event->action, 'project.member_')) {
            return route('projects.access', $this->workspace);
        }

        if (str_starts_with($event->action, 'project.deploy.')) {
            return route('projects.delivery', $this->workspace);
        }

        if (in_array($event->action, ['project.updated', 'project.environment_added', 'project.environment_removed'], true)) {
            return route('projects.overview', $this->workspace);
        }

        if (str_starts_with($event->action, 'project.server_')) {
            $serverId = $event->new_values['server_id'] ?? null;
            // Reuse the already-loaded project servers; only hit the DB for a
            // server that's since been detached (project.server_removed).
            $server = $serverId
                ? ($this->workspace->servers->firstWhere('id', $serverId) ?? Server::query()->find($serverId))
                : null;

            return $server ? route('servers.show', $server) : route('projects.resources', $this->workspace);
        }

        if (str_starts_with($event->action, 'project.site_')) {
            $siteId = $event->new_values['site_id'] ?? null;
            // Reuse the already-loaded project sites (with their server); only
            // query for a site that's since been detached.
            $site = $siteId
                ? ($this->workspace->sites->firstWhere('id', $siteId) ?? Site::query()->with('server')->find($siteId))
                : null;

            return $site?->server ? route('sites.show', [$site->server, $site]) : route('projects.resources', $this->workspace);
        }

        if ($event->subject_type === Server::class) {
            /** @var Server|null $server */
            $server = $event->subject;

            if (! $server) {
                return route('projects.resources', $this->workspace);
            }

            if (str_contains($event->action, 'insight')) {
                return route('servers.insights', $server);
            }

            if (str_contains($event->action, 'firewall')
                || str_contains($event->action, 'ssh_keys')
                || str_contains($event->action, 'database')
                || str_contains($event->action, 'settings')) {
                return route('servers.manage', $server);
            }

            return route('servers.show', $server);
        }

        if ($event->subject_type === Site::class) {
            // Prefer the already-loaded project site (its server is loaded too);
            // only fall back to the bare subject + a server query for a site
            // that's no longer attached to this project.
            /** @var Site|null $site */
            $site = $this->workspace->sites->firstWhere('id', $event->subject_id) ?? $event->subject;
            $site?->loadMissing('server');

            return $site?->server ? route('sites.show', [$site->server, $site]) : route('projects.resources', $this->workspace);
        }

        return match (true) {
            str_contains($event->action, 'health') => route('projects.operations', $this->workspace),
            str_contains($event->action, 'deploy') => route('projects.delivery', $this->workspace),
            default => null,
        };
    }

    protected function activityLinkLabel(AuditLog $event): ?string
    {
        if (str_starts_with($event->action, 'project.member_')) {
            return __('Open access');
        }

        if (str_starts_with($event->action, 'project.deploy.')) {
            return __('Open delivery');
        }

        if (str_starts_with($event->action, 'project.server_') || $event->subject_type === Server::class) {
            return __('Open server');
        }

        if (str_starts_with($event->action, 'project.site_') || $event->subject_type === Site::class) {
            return __('Open site');
        }

        if (str_contains($event->action, 'health')) {
            return __('Open operations');
        }

        return __('Open project');
    }

    public function render(): View
    {
        $orgId = $this->workspace->organization_id;
        $user = auth()->user();

        $availableServers = Server::query()
            ->where('organization_id', $orgId)
            ->where(function ($q): void {
                $q->whereNull('workspace_id')
                    ->orWhere('workspace_id', '!=', $this->workspace->id);
            })
            ->orderBy('name')
            ->get();

        $availableSites = Site::query()
            ->where('organization_id', $orgId)
            ->where(function ($q): void {
                $q->whereNull('workspace_id')
                    ->orWhere('workspace_id', '!=', $this->workspace->id);
            })
            ->orderBy('name')
            ->get();

        // Load relations onto the existing instance instead of fresh(), which
        // re-SELECTs the workspace row on every render. Editing actions already
        // mutate $this->workspace in memory, so its base attributes are current
        // — only the relations need refreshing.
        $this->workspace->load([
            'organization',
            'members.user',
            'servers',
            'sites.server',
            'sites.deployments',
            'environments',
            'labels',
            'runbooks',
            'variables',
            'deployRuns.user',
        ]);
        $workspace = $this->workspace;

        // Every server/site in this project belongs to THIS workspace and its
        // organization, both already loaded above. Prime those inverse relations
        // so per-row @can('update'|'view', $server|$site) checks (Server/Site
        // policies read $resource->workspace->organization and
        // $resource->organization) don't lazy-load workspace + org once per row.
        $org = $workspace->organization;
        $workspace->servers->each(function (Server $server) use ($workspace, $org): void {
            $server->setRelation('workspace', $workspace)->setRelation('organization', $org);
        });
        $workspace->sites->each(function (Site $site) use ($workspace, $org): void {
            $site->setRelation('workspace', $workspace)->setRelation('organization', $org);
        });

        // Only org members who aren't already on the project — excludes the
        // current members (and therefore yourself/the owner) so the "Add member"
        // picker can't re-add and silently re-role an existing member.
        $existingMemberUserIds = $workspace->members->pluck('user_id')->all();
        $orgUsers = $workspace->organization->users()
            ->orderBy('name')
            ->get()
            ->reject(fn ($user) => in_array($user->id, $existingMemberUserIds, true))
            ->values();
        $labels = WorkspaceLabel::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->get();
        $assignableChannels = AssignableNotificationChannels::forUser($user, $workspace->organization);
        $health = app(WorkspaceHealthSummaryService::class)->summarize($workspace);
        $activity = AuditLog::query()
            ->where('organization_id', $orgId)
            ->where(function ($query) use ($workspace): void {
                $query->where(fn ($q) => $q
                    ->where('subject_type', Workspace::class)
                    ->where('subject_id', $workspace->id))
                    ->orWhere(fn ($q) => $q
                        ->where('subject_type', Server::class)
                        ->whereIn('subject_id', $workspace->servers->pluck('id')->all()))
                    ->orWhere(fn ($q) => $q
                        ->where('subject_type', Site::class)
                        ->whereIn('subject_id', $workspace->sites->pluck('id')->all()));
            })
            // Eager-load the actor (the feed prints $event->user->name per row)
            // and the polymorphic audit subject so the subject_summary accessor +
            // activityUrl() batch into one query per type instead of one per row.
            // A Site subject's server is resolved from the already-loaded project
            // sites in activityUrl(), so there's no need to nest ['server'] here.
            ->with(['user', 'subject'])
            ->latest()
            ->limit(20)
            ->get();
        $activityItems = $activity->map(fn (AuditLog $event): array => [
            'event' => $event,
            'url' => $this->activityUrl($event),
            'linkLabel' => $this->activityLinkLabel($event),
        ]);
        $workspaceRoutes = NotificationSubscription::query()
            ->where('subscribable_type', Workspace::class)
            ->where('subscribable_id', $workspace->id)
            ->get();
        $monitoredServerIds = $workspace->servers
            ->filter(fn (Server $server): bool => (bool) (($server->meta ?? [])['monitoring_python_installed'] ?? false))
            ->pluck('id');
        $serversWithSamples = $monitoredServerIds->isEmpty()
            ? 0
            : ServerMetricSnapshot::query()
                ->whereIn('server_id', $monitoredServerIds->all())
                ->distinct('server_id')
                ->count('server_id');
        $operationsSummary = [
            'runbook_count' => $workspace->runbooks->count(),
            'notification_route_count' => $workspaceRoutes->count(),
            'notification_event_count' => $workspaceRoutes->pluck('event_key')->unique()->count(),
            'monitored_servers' => $monitoredServerIds->count(),
            'servers_with_samples' => $serversWithSamples,
        ];

        // Show the org's current plan-TIER ceilings (Free = 1 server / 1 site,
        // Business = unlimited). These are the per-tier allotments — distinct from
        // maxServers(), which is the creation gate and is intentionally uncapped
        // (adding a server just bumps the usage-based tier). A null cap = unlimited
        // and renders as "Unlimited" rather than the raw PHP_INT_MAX.
        $serverCap = $workspace->organization->planServerLimit();
        $siteCap = $workspace->organization->planSiteLimit();
        $serversUnlimited = $serverCap === null;
        $sitesUnlimited = $siteCap === null;
        $serversRemaining = $serversUnlimited ? null : max(0, $serverCap - $workspace->servers->count());
        $sitesRemaining = $sitesUnlimited ? null : max(0, $siteCap - $workspace->sites->count());

        $costSummary = [
            'servers_used' => $workspace->servers->count(),
            'servers_remaining' => $serversRemaining,
            'servers_remaining_label' => $serversUnlimited
                ? __('Unlimited in org plan')
                : __('Remaining in org plan: :count', ['count' => $serversRemaining]),
            'sites_used' => $workspace->sites->count(),
            'sites_remaining' => $sitesRemaining,
            'sites_remaining_label' => $sitesUnlimited
                ? __('Unlimited in org plan')
                : __('Remaining in org plan: :count', ['count' => $sitesRemaining]),
            'variables_count' => $workspace->variables->count(),
            'deploy_runs_count' => $workspace->deployRuns->count(),
        ];

        return view('livewire.projects.show', [
            'availableServers' => $availableServers,
            'availableSites' => $availableSites,
            'orgUsers' => $orgUsers,
            'labels' => $labels,
            'assignableChannels' => $assignableChannels,
            'health' => $health,
            'activity' => $activityItems,
            'operationsSummary' => $operationsSummary,
            'costSummary' => $costSummary,
            'section' => $this->section,
            'workspaceRoles' => WorkspaceMember::roles(),
        ]);
    }
}
