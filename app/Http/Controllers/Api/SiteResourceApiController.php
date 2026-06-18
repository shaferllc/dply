<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteCertificate;
use App\Models\SiteDeploymentSchedule;
use App\Models\SiteDomain;
use App\Models\SiteProcess;
use App\Models\SiteUptimeMonitor;
use App\Modules\SourceControl\Services\SiteGitCommitsFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteResourceApiController extends Controller
{
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        return response()->json([
            'data' => [
                'id' => $site->id,
                'slug' => $site->slug,
                'name' => $site->name,
                'server_id' => $site->server_id,
                'server_name' => $site->server?->name,
                'type' => $site->type,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
                'status' => $site->status,
                'deploy_strategy' => $site->deploy_strategy,
                'document_root' => $site->document_root,
                'git_repository_url' => $site->git_repository_url,
                'git_branch' => $site->git_branch,
                'ssl_status' => $site->ssl_status,
                'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
                'created_at' => $site->created_at->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $site->update($data);

        return response()->json([
            'data' => ['id' => $site->id, 'slug' => $site->slug, 'name' => $site->name],
        ]);
    }

    public function workers(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $processes = SiteProcess::query()
            ->where('site_id', $site->id)
            ->whereIn('type', [SiteProcess::TYPE_WORKER, SiteProcess::TYPE_SCHEDULER, SiteProcess::TYPE_CUSTOM])
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'command', 'scale', 'working_directory', 'user', 'is_active']);

        return response()->json([
            'data' => $processes->map(fn (SiteProcess $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'name' => $p->name,
                'command' => $p->command,
                'scale' => $p->scale,
                'working_directory' => $p->working_directory,
                'user' => $p->user,
                'is_active' => $p->is_active,
            ]),
        ]);
    }

    public function schedules(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $deploySchedules = SiteDeploymentSchedule::query()
            ->where('site_id', $site->id)
            ->get(['id', 'cron_expression', 'timezone', 'git_branch', 'is_active', 'last_run_at']);

        $cronJobs = ServerCronJob::query()
            ->where('site_id', $site->id)
            ->get(['id', 'cron_expression', 'command', 'user', 'enabled', 'description', 'last_run_at']);

        return response()->json([
            'deploy_schedules' => $deploySchedules->map(fn (SiteDeploymentSchedule $s) => [
                'id' => $s->id,
                'type' => 'deploy',
                'cron_expression' => $s->cron_expression,
                'timezone' => $s->timezone,
                'git_branch' => $s->git_branch,
                'is_active' => $s->is_active,
                'last_run_at' => $s->last_run_at?->toIso8601String(),
            ]),
            'cron_jobs' => $cronJobs->map(fn (ServerCronJob $j) => [
                'id' => $j->id,
                'type' => 'cron',
                'cron_expression' => $j->cron_expression,
                'command' => $j->command,
                'user' => $j->user,
                'enabled' => $j->enabled,
                'description' => $j->description,
                'last_run_at' => $j->last_run_at?->toIso8601String(),
            ]),
        ]);
    }

    public function errors(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $events = ErrorEvent::query()
            ->where('site_id', $site->id)
            ->whereNull('dismissed_at')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get(['id', 'category', 'title', 'detail', 'link_url', 'occurred_at', 'remediation_code']);

        return response()->json([
            'data' => $events->map(fn (ErrorEvent $e) => [
                'id' => $e->id,
                'category' => $e->category,
                'title' => $e->title,
                'detail' => $e->detail,
                'link_url' => $e->link_url,
                'remediation_code' => $e->remediation_code,
                'occurred_at' => $e->occurred_at->toIso8601String(),
            ]),
        ]);
    }

    public function uptime(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $monitors = SiteUptimeMonitor::query()
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get(['id', 'label', 'path', 'probe_region', 'last_checked_at', 'last_ok', 'last_http_status', 'last_latency_ms', 'last_error']);

        return response()->json([
            'data' => $monitors->map(fn (SiteUptimeMonitor $m) => [
                'id' => $m->id,
                'label' => $m->label,
                'path' => $m->path,
                'probe_region' => $m->probe_region,
                'status' => $m->last_ok ? 'up' : ($m->last_checked_at ? 'down' : 'unchecked'),
                'http_status' => $m->last_http_status,
                'latency_ms' => $m->last_latency_ms,
                'last_error' => $m->last_error,
                'last_checked_at' => $m->last_checked_at?->toIso8601String(),
            ]),
        ]);
    }

    public function basicAuth(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $users = SiteBasicAuthUser::query()
            ->where('site_id', $site->id)
            ->whereNull('pending_removal_at')
            ->orderBy('sort_order')
            ->orderBy('username')
            ->get(['id', 'username', 'path', 'source_file_path']);

        return response()->json([
            'data' => $users->map(fn (SiteBasicAuthUser $u) => [
                'id' => $u->id,
                'username' => $u->username,
                'path' => $u->path,
            ]),
        ]);
    }

    public function addBasicAuth(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
        ]);

        $exists = SiteBasicAuthUser::query()
            ->where('site_id', $site->id)
            ->where('username', $data['username'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Username already exists for this site.'], 422);
        }

        $user = SiteBasicAuthUser::create([
            'site_id' => $site->id,
            'username' => $data['username'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'path' => $data['path'] ?? '/',
        ]);

        return response()->json(['data' => ['id' => $user->id, 'username' => $user->username]], 201);
    }

    public function removeBasicAuth(Request $request, Site $site, string $username): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $user = SiteBasicAuthUser::query()
            ->where('site_id', $site->id)
            ->where('username', $username)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User removed.']);
    }

    public function ssl(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $certs = SiteCertificate::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->get(['id', 'provider_type', 'challenge_type', 'status', 'expires_at', 'last_requested_at', 'last_installed_at']);

        return response()->json([
            'ssl_status' => $site->ssl_status,
            'data' => $certs->map(fn (SiteCertificate $c) => [
                'id' => $c->id,
                'provider_type' => $c->provider_type,
                'challenge_type' => $c->challenge_type,
                'status' => $c->status,
                'expires_at' => $c->expires_at?->toIso8601String(),
                'last_requested_at' => $c->last_requested_at?->toIso8601String(),
                'last_installed_at' => $c->last_installed_at?->toIso8601String(),
            ]),
        ]);
    }

    public function domains(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $domains = SiteDomain::query()
            ->where('site_id', $site->id)
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'is_primary', 'www_redirect', 'comment']);

        return response()->json([
            'data' => $domains->map(fn (SiteDomain $d) => [
                'id' => $d->id,
                'hostname' => $d->hostname,
                'is_primary' => $d->is_primary,
                'www_redirect' => $d->www_redirect,
                'comment' => $d->comment,
            ]),
        ]);
    }

    public function addDomain(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'is_primary' => ['boolean'],
            'www_redirect' => ['boolean'],
        ]);

        $exists = SiteDomain::query()
            ->where('site_id', $site->id)
            ->where('hostname', $data['hostname'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Hostname already added to this site.'], 422);
        }

        if (! empty($data['is_primary'])) {
            SiteDomain::query()->where('site_id', $site->id)->update(['is_primary' => false]);
        }

        $domain = SiteDomain::create([
            'site_id' => $site->id,
            'hostname' => $data['hostname'],
            'is_primary' => $data['is_primary'] ?? false,
            'www_redirect' => $data['www_redirect'] ?? false,
        ]);

        return response()->json(['data' => ['id' => $domain->id, 'hostname' => $domain->hostname]], 201);
    }

    public function removeDomain(Request $request, Site $site, string $hostname): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $domain = SiteDomain::query()
            ->where('site_id', $site->id)
            ->where('hostname', $hostname)
            ->first();

        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        if ($domain->is_primary) {
            return response()->json(['message' => 'Cannot remove the primary domain. Set another domain as primary first.'], 422);
        }

        $domain->delete();

        return response()->json(['message' => 'Domain removed.']);
    }

    public function databases(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $databases = ServerDatabase::query()
            ->where('site_id', $site->id)
            ->orWhere(fn ($q) => $q->where('server_id', $site->server_id)->whereNull('site_id'))
            ->get(['id', 'name', 'engine', 'username', 'host', 'site_id', 'description']);

        return response()->json([
            'data' => $databases->map(fn (ServerDatabase $db) => [
                'id' => $db->id,
                'name' => $db->name,
                'engine' => $db->engine,
                'username' => $db->username,
                'host' => $db->host ?? '127.0.0.1',
                'site_owned' => $db->site_id === $site->id,
                'description' => $db->description,
            ]),
        ]);
    }

    public function commits(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        if (empty($site->git_repository_url)) {
            return response()->json(['message' => 'No repository configured for this site.'], 422);
        }

        $fetcher = app(SiteGitCommitsFetcher::class);
        $result = $fetcher->fetch($site, auth()->user());

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'] ?? 'Could not fetch commits.',
            ], 422);
        }

        return response()->json(['data' => $result['commits']]);
    }

    public function systemUser(Request $request, Site $site): JsonResponse
    {
        $this->checkOwnership($request, $site);

        $effectiveUser = $site->effectiveSystemUser($site->server);

        return response()->json([
            'data' => [
                'username' => $effectiveUser,
                'server_id' => $site->server_id,
                'server_name' => $site->server?->name,
            ],
        ]);
    }

    private function checkOwnership(Request $request, Site $site): void
    {
        $organization = $request->attributes->get('api_organization');
        if ($site->server?->organization_id !== $organization?->id) {
            abort(403);
        }
    }
}
