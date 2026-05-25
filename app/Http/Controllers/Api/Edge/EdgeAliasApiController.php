<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Models\EdgeDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeAliasApiController extends EdgeApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $rows = EdgeDeployment::query()
            ->where('site_id', $found->id)
            ->whereIn('status', [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $data = [];
        foreach ($rows as $deployment) {
            foreach ($deployment->aliasHostnames() as $alias) {
                $data[] = [
                    'hostname' => $alias,
                    'deployment_id' => (string) $deployment->id,
                    'git_commit' => $deployment->git_commit,
                    'git_branch' => $deployment->git_branch,
                    'published_at' => $deployment->published_at?->toIso8601String(),
                ];
            }
        }

        return response()->json(['data' => $data]);
    }
}
