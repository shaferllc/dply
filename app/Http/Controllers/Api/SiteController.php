<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Support\Sites\SiteApiAccess;
use App\Support\Sites\SiteIndexAssembler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');
        $user = $request->user();

        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->with(['server:id,name,workspace_id,organization_id', 'domains', 'workspace:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s): bool => $user instanceof User && SiteApiAccess::userCanView($user, $s, $organization))
            ->values();

        return response()->json([
            'data' => $sites->map(fn (Site $s) => SiteIndexAssembler::toArray($s))->values(),
        ]);
    }

    public function deploy(Request $request, Site $site): JsonResponse
    {
        if ($response = $this->authorizeSite($request, $site, deploy: true)) {
            return $response;
        }

        if (empty(trim((string) $site->git_repository_url))) {
            return response()->json([
                'message' => 'Configure a Git repository URL for this site first.',
            ], 422);
        }

        $idemHeader = $request->header('Idempotency-Key');
        $idemHash = null;
        if ($idemHeader !== null && trim($idemHeader) !== '') {
            $idemHash = sha1($site->id.'|'.Str::limit(trim($idemHeader), 128));
            $cached = Cache::get('api-deploy-result:'.$idemHash);
            if (is_array($cached)) {
                return response()->json($cached, 200);
            }
            if (! Cache::add('api-deploy-inflight:'.$idemHash, 1, 120)) {
                return response()->json([
                    'message' => 'Deploy already in progress for this idempotency key.',
                ], 409);
            }
        }

        $sync = filter_var($request->input('sync', false), FILTER_VALIDATE_BOOLEAN);
        $userId = auth()->id();

        if ($sync) {
            try {
                RunSiteDeploymentJob::dispatchSync($site, SiteDeployment::TRIGGER_API, $idemHash, $userId);
            } catch (\Throwable $e) {
                if ($idemHash) {
                    Cache::forget('api-deploy-inflight:'.$idemHash);
                }

                return response()->json([
                    'message' => 'Deployment failed.',
                    'error' => $e->getMessage(),
                ], 500);
            }

            $site->refresh();
            $payload = $idemHash ? Cache::get('api-deploy-result:'.$idemHash) : null;

            return response()->json($payload ?? [
                'message' => 'Deployment completed.',
                'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            ], 200);
        }

        try {
            RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_API, $idemHash, $userId);
        } catch (\Throwable $e) {
            if ($idemHash) {
                Cache::forget('api-deploy-inflight:'.$idemHash);
            }
            throw $e;
        }

        return response()->json(['message' => 'Deployment queued.'], 202);
    }

    public function deployments(Request $request, Site $site): JsonResponse
    {
        if ($response = $this->authorizeSite($request, $site)) {
            return $response;
        }

        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $rows = SiteDeployment::query()
            ->where('site_id', $site->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SiteDeployment $d) => $this->deploymentPayload($d)),
        ]);
    }

    public function showDeployment(Request $request, Site $site, SiteDeployment $deployment): JsonResponse
    {
        if ($response = $this->authorizeSite($request, $site)) {
            return $response;
        }
        if ($deployment->site_id !== $site->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'data' => $this->deploymentPayload($deployment),
        ]);
    }

    protected function authorizeSite(Request $request, Site $site, bool $deploy = false): ?JsonResponse
    {
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('api_organization');
        $user = $request->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowed = $deploy
            ? SiteApiAccess::userCanDeploy($user, $site, $organization)
            : SiteApiAccess::userCanView($user, $site, $organization);

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function deploymentPayload(SiteDeployment $d): array
    {
        return [
            'id' => $d->id,
            'site_id' => $d->site_id,
            'trigger' => $d->trigger,
            'status' => $d->status,
            'git_sha' => $d->git_sha,
            'exit_code' => $d->exit_code,
            'log_output' => $d->log_output,
            'started_at' => $d->started_at?->toIso8601String(),
            'finished_at' => $d->finished_at?->toIso8601String(),
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
