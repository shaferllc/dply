<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunWorkspaceDeployJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDeployRun;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceRunbook;
use App\Models\WorkspaceVariable;
use App\Services\Projects\WorkspaceHealthSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');
        /** @var User $user */
        $user = $request->user();

        $query = $organization->workspaces()
            ->withCount(['servers', 'sites'])
            ->orderBy('name');

        if (! $organization->hasAdminAccess($user)) {
            $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
        }

        $rows = $query->get()->map(fn (Workspace $workspace): array => $this->listPayload($workspace, $user));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        if ($organization->userIsDeployer($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $workspace = $organization->workspaces()->create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $workspace->loadCount(['servers', 'sites']);

        return response()->json([
            'data' => $this->detailPayload($workspace->fresh(['members.user']), $user),
        ], 201);
    }

    public function show(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $project->loadCount(['servers', 'sites']);
        $project->load(['members.user', 'servers:id,name,status,workspace_id', 'sites:id,name,status,server_id,workspace_id']);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->detailPayload($project, $user),
        ]);
    }

    public function update(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        $project->update($validated);
        $project->loadCount(['servers', 'sites']);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->detailPayload($project->fresh(['members.user']), $user),
        ]);
    }

    public function destroy(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'delete')) {
            return $response;
        }

        $project->delete();

        return response()->json([
            'message' => 'Project deleted. Servers and sites are unchanged but no longer grouped.',
        ]);
    }

    public function health(Request $request, Workspace $project, WorkspaceHealthSummaryService $health): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        return response()->json([
            'data' => $health->summarize($project),
        ]);
    }

    public function members(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $rows = $project->members()
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMember $member): array => [
                'id' => (string) $member->id,
                'user_id' => (string) $member->user_id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'role' => $member->role,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function storeMember(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'manage_members')) {
            return $response;
        }

        $validated = $request->validate([
            'user_id' => ['required', 'string'],
            'role' => ['required', Rule::in(WorkspaceMember::roles())],
        ]);

        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');
        $memberUser = User::query()->findOrFail($validated['user_id']);

        if (! $organization->hasMember($memberUser)) {
            return response()->json(['message' => 'User is not a member of this organization.'], 422);
        }

        $membership = $project->members()->updateOrCreate(
            ['user_id' => $memberUser->id],
            ['role' => $validated['role']],
        );

        $membership->load('user:id,name,email');

        return response()->json([
            'data' => [
                'id' => (string) $membership->id,
                'user_id' => (string) $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'role' => $membership->role,
            ],
        ]);
    }

    public function destroyMember(Request $request, Workspace $project, WorkspaceMember $member): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'manage_members')) {
            return $response;
        }

        if ($member->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($member->role === WorkspaceMember::ROLE_OWNER
            && $project->members()->where('role', WorkspaceMember::ROLE_OWNER)->count() <= 1) {
            return response()->json(['message' => 'Projects must keep at least one owner.'], 422);
        }

        $member->delete();

        return response()->json(['message' => 'Project member removed.']);
    }

    public function attachServer(Request $request, Workspace $project, Server $server): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->authorize('update', $server);

        $server->update(['workspace_id' => $project->id]);

        return response()->json([
            'message' => 'Server added to project.',
            'server_id' => (string) $server->id,
        ]);
    }

    public function detachServer(Request $request, Workspace $project, Server $server): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        if ($server->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->authorize('update', $server);
        $server->update(['workspace_id' => null]);

        return response()->json(['message' => 'Server removed from project.']);
    }

    public function attachSite(Request $request, Workspace $project, Site $site): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        if ($site->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => $project->id]);

        return response()->json([
            'message' => 'Site added to project.',
            'site_id' => (string) $site->id,
        ]);
    }

    public function detachSite(Request $request, Workspace $project, Site $site): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        if ($site->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->authorize('update', $site);
        $site->update(['workspace_id' => null]);

        return response()->json(['message' => 'Site removed from project.']);
    }

    public function deploys(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $rows = $project->deployRuns()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (WorkspaceDeployRun $run): array => $this->deployRunPayload($run))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function deploy(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'deploy')) {
            return $response;
        }

        $validated = $request->validate([
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $siteIds = array_values(array_filter(
            $validated['site_ids'] ?? $project->sites()->pluck('id')->all(),
            fn (mixed $id): bool => is_string($id) && $id !== '',
        ));

        if ($siteIds === []) {
            return response()->json(['message' => 'Choose at least one site to deploy.'], 422);
        }

        $run = WorkspaceDeployRun::query()->create([
            'workspace_id' => $project->id,
            'user_id' => $user->id,
            'status' => WorkspaceDeployRun::STATUS_QUEUED,
            'site_ids' => $siteIds,
        ]);

        RunWorkspaceDeployJob::dispatch($run->id);

        return response()->json([
            'data' => $this->deployRunPayload($run->fresh(['user:id,name,email'])),
            'message' => 'Project deploy queued.',
        ], 202);
    }

    public function showDeploy(Request $request, Workspace $project, WorkspaceDeployRun $deployRun): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        if ($deployRun->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $deployRun->load('user:id,name,email');

        return response()->json([
            'data' => $this->deployRunPayload($deployRun),
        ]);
    }

    public function environments(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $rows = $project->environments()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($environment): array => [
                'id' => (string) $environment->id,
                'name' => (string) $environment->name,
                'slug' => (string) $environment->slug,
                'description' => $environment->description,
                'sort_order' => (int) $environment->sort_order,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function storeEnvironment(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'environment';
        $slug = $baseSlug;
        $index = 1;
        while ($project->environments()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$index;
            $index++;
        }

        $environment = $project->environments()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'sort_order' => ((int) $project->environments()->max('sort_order')) + 1,
        ]);

        return response()->json([
            'data' => [
                'id' => (string) $environment->id,
                'name' => (string) $environment->name,
                'slug' => (string) $environment->slug,
                'description' => $environment->description,
                'sort_order' => (int) $environment->sort_order,
            ],
        ], 201);
    }

    public function destroyEnvironment(Request $request, Workspace $project, string $environment): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        $row = $project->environments()->findOrFail($environment);
        $row->delete();

        return response()->json(['message' => 'Environment removed.']);
    }

    public function variables(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $rows = $project->variables()
            ->orderBy('env_key')
            ->get()
            ->map(fn (WorkspaceVariable $variable): array => [
                'id' => (string) $variable->id,
                'key' => (string) $variable->env_key,
                'is_secret' => (bool) $variable->is_secret,
                'has_value' => $variable->env_value !== null && $variable->env_value !== '',
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function upsertVariable(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'value' => ['nullable', 'string', 'max:5000'],
            'secret' => ['sometimes', 'boolean'],
        ]);

        $envKey = strtoupper($validated['key']);

        $variable = $project->variables()->updateOrCreate(
            ['env_key' => $envKey],
            [
                'env_value' => ($validated['value'] ?? '') !== '' ? $validated['value'] : null,
                'is_secret' => filter_var($validated['secret'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        );

        return response()->json([
            'data' => [
                'id' => (string) $variable->id,
                'key' => (string) $variable->env_key,
                'is_secret' => (bool) $variable->is_secret,
                'has_value' => $variable->env_value !== null && $variable->env_value !== '',
            ],
        ]);
    }

    public function destroyVariable(Request $request, Workspace $project, WorkspaceVariable $variable): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        if ($variable->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $variable->delete();

        return response()->json(['message' => 'Project variable removed.']);
    }

    public function runbooks(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'view')) {
            return $response;
        }

        $rows = $project->runbooks()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->map(fn (WorkspaceRunbook $runbook): array => [
                'id' => (string) $runbook->id,
                'title' => (string) $runbook->title,
                'url' => $runbook->url,
                'body' => $runbook->body,
                'sort_order' => (int) $runbook->sort_order,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function storeRunbook(Request $request, Workspace $project): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'url' => ['nullable', 'url', 'max:500'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $runbook = $project->runbooks()->create([
            'title' => $validated['title'],
            'url' => ($validated['url'] ?? '') !== '' ? $validated['url'] : null,
            'body' => ($validated['body'] ?? '') !== '' ? $validated['body'] : null,
            'sort_order' => ((int) $project->runbooks()->max('sort_order')) + 1,
        ]);

        return response()->json([
            'data' => [
                'id' => (string) $runbook->id,
                'title' => (string) $runbook->title,
                'url' => $runbook->url,
                'body' => $runbook->body,
                'sort_order' => (int) $runbook->sort_order,
            ],
        ], 201);
    }

    public function destroyRunbook(Request $request, Workspace $project, WorkspaceRunbook $runbook): JsonResponse
    {
        if ($response = $this->authorizeProject($request, $project, 'update')) {
            return $response;
        }

        if ($runbook->workspace_id !== $project->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $runbook->delete();

        return response()->json(['message' => 'Runbook removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function listPayload(Workspace $workspace, User $user): array
    {
        return [
            'id' => (string) $workspace->id,
            'name' => (string) $workspace->name,
            'slug' => (string) $workspace->slug,
            'description' => $workspace->description,
            'servers_count' => (int) ($workspace->servers_count ?? $workspace->servers()->count()),
            'sites_count' => (int) ($workspace->sites_count ?? $workspace->sites()->count()),
            'role' => $workspace->memberRole($user),
            'created_at' => $workspace->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detailPayload(Workspace $workspace, User $user): array
    {
        return array_merge($this->listPayload($workspace, $user), [
            'notes' => $workspace->notes,
            'servers' => $workspace->relationLoaded('servers')
                ? $workspace->servers->map(fn (Server $server): array => [
                    'id' => (string) $server->id,
                    'name' => (string) $server->name,
                    'status' => (string) $server->status,
                ])->values()->all()
                : [],
            'sites' => $workspace->relationLoaded('sites')
                ? $workspace->sites->map(fn (Site $site): array => [
                    'id' => (string) $site->id,
                    'name' => (string) $site->name,
                    'status' => (string) $site->status,
                    'server_id' => (string) $site->server_id,
                ])->values()->all()
                : [],
            'members' => $workspace->relationLoaded('members')
                ? $workspace->members->map(fn (WorkspaceMember $member): array => [
                    'id' => (string) $member->id,
                    'user_id' => (string) $member->user_id,
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'role' => $member->role,
                ])->values()->all()
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deployRunPayload(WorkspaceDeployRun $run): array
    {
        return [
            'id' => (string) $run->id,
            'status' => (string) $run->status,
            'site_ids' => $run->site_ids ?? [],
            'result_summary' => $run->result_summary,
            'output' => $run->output,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'user' => $run->user ? [
                'id' => (string) $run->user->id,
                'name' => $run->user->name,
                'email' => $run->user->email,
            ] : null,
        ];
    }

    protected function authorizeProject(Request $request, Workspace $project, string $action): ?JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');
        /** @var User $user */
        $user = $request->user();

        if ($project->organization_id !== $organization->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $allowed = match ($action) {
            'view' => $project->userCanView($user),
            'update' => $project->userCanUpdate($user),
            'delete' => $project->userCanView($user) && $organization->hasAdminAccess($user),
            'manage_members' => $project->userCanManageMembers($user),
            'deploy' => $project->userCanDeploy($user),
            default => false,
        };

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }
}
