<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdgeProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EdgeProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $query = EdgeProject::query()->orderBy('name');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $sanitized = preg_replace('/[%_\\\\]/', '', $q);
            $q = mb_substr(is_string($sanitized) ? $sanitized : '', 0, 128);
            if ($q !== '') {
                $query->where(function ($w) use ($q): void {
                    $w->where('name', 'like', '%'.$q.'%')
                        ->orWhere('slug', 'like', '%'.$q.'%');
                });
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (EdgeProject $p) => $this->projectPayload($p))->values()->all(),
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:edge_projects,slug'],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
        ]);

        $project = EdgeProject::query()->create($validated);

        return response()->json(['project' => $this->projectPayload($project)], 201);
    }

    public function update(Request $request, EdgeProject $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('name', $validated)) {
            $project->name = $validated['name'];
        }
        if (array_key_exists('settings', $validated)) {
            $project->settings = $validated['settings'];
        }
        if (array_key_exists('credentials', $validated)) {
            $project->credentials = $validated['credentials'];
        }
        $project->save();

        return response()->json(['project' => $this->projectPayload($project)]);
    }

    public function show(EdgeProject $project): JsonResponse
    {
        $latest = $project->deployments()->latest('id')->first();

        return response()->json([
            'project' => $this->projectPayload($project),
            'latest_deployment' => $latest === null ? null : [
                'id' => $latest->id,
                'deployment_url' => route('edge.deployments.show', $latest, absolute: true),
                'status' => $latest->status,
                'application_name' => $latest->application_name,
                'framework' => $latest->framework,
                'git_ref' => $latest->git_ref,
                'trigger' => $latest->trigger,
                'revision_id' => $latest->revision_id,
                'error_message' => $latest->error_message,
                'created_at' => $latest->created_at,
                'updated_at' => $latest->updated_at,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(EdgeProject $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'settings' => $project->settings ?? [],
            'has_credentials' => is_array($project->credentials) && $project->credentials !== [],
            'created_at' => $project->created_at,
            'updated_at' => $project->updated_at,
        ];
    }
}
