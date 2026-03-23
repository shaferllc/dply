<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->with(['server:id,name'])
            ->orderBy('name')
            ->get(['id', 'server_id', 'name', 'deploy_strategy', 'status', 'document_root', 'created_at']);

        return response()->json([
            'data' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'server_id' => $s->server_id,
                'server_name' => $s->server?->name,
                'name' => $s->name,
                'deploy_strategy' => $s->deploy_strategy,
                'status' => $s->status,
                'document_root' => $s->document_root,
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function deploy(Request $request, Site $site): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($site->server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (empty(trim((string) $site->git_repository_url))) {
            return response()->json([
                'message' => 'Configure a Git repository URL for this site first.',
            ], 422);
        }

        $sync = filter_var($request->input('sync', false), FILTER_VALIDATE_BOOLEAN);

        if ($sync) {
            try {
                RunSiteDeploymentJob::dispatchSync($site, SiteDeployment::TRIGGER_API);
                $site->refresh();

                return response()->json(['message' => 'Deployment completed.', 'last_deploy_at' => $site->last_deploy_at?->toIso8601String()]);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'Deployment failed.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_API);

        return response()->json(['message' => 'Deployment queued.'], 202);
    }
}
