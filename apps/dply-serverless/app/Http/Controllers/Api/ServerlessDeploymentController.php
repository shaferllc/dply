<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServerlessFunctionDeployment;
use App\Models\ServerlessProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ServerlessDeploymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = ServerlessFunctionDeployment::query()
            ->with('project:id,name,slug')
            ->orderByDesc('id');

        $projectSlug = trim((string) $request->query('project_slug', ''));
        if ($projectSlug !== '') {
            $project = ServerlessProject::query()->where('slug', $projectSlug)->first();
            if ($project === null) {
                return response()->json(['message' => 'Unknown project_slug.'], 422);
            }
            $query->where('serverless_project_id', $project->id);
        }

        $functionName = trim((string) $request->query('function_name', ''));
        if ($functionName !== '') {
            $query->where('function_name', mb_substr($functionName, 0, 255));
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $allowed = [
                ServerlessFunctionDeployment::STATUS_QUEUED,
                ServerlessFunctionDeployment::STATUS_RUNNING,
                ServerlessFunctionDeployment::STATUS_SUCCEEDED,
                ServerlessFunctionDeployment::STATUS_FAILED,
            ];
            if (! in_array($status, $allowed, true)) {
                return response()->json(['message' => 'Invalid status filter.'], 422);
            }
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ServerlessFunctionDeployment $d) => $this->deploymentPayload($d, includeProvisionerOutput: false))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function show(ServerlessFunctionDeployment $deployment): JsonResponse
    {
        $deployment->loadMissing('project:id,name,slug');

        return response()->json([
            'deployment' => $this->deploymentPayload($deployment, includeProvisionerOutput: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentPayload(ServerlessFunctionDeployment $deployment, bool $includeProvisionerOutput = true): array
    {
        $project = $deployment->project;

        $row = [
            'id' => $deployment->id,
            'deployment_url' => route('serverless.deployments.show', $deployment, absolute: true),
            'status' => $deployment->status,
            'function_name' => $deployment->function_name,
            'runtime' => $deployment->runtime,
            'artifact_path' => $deployment->artifact_path,
            'trigger' => $deployment->trigger,
            'revision_id' => $deployment->revision_id,
            'error_message' => $deployment->error_message,
            'serverless_project_id' => $deployment->serverless_project_id,
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'created_at' => $deployment->created_at,
            'updated_at' => $deployment->updated_at,
        ];

        if ($includeProvisionerOutput) {
            $row['provisioner_output'] = $deployment->provisioner_output;
        }

        return $row;
    }
}
