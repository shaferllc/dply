<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use App\Services\SshConnection;
use App\Support\Servers\ServerIndexAssembler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * List servers for the token's organization (full fleet-card payload).
     */
    public function index(Request $request, OrganizationInsightsMetricsService $insightsMetrics): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->with([
                'workspace:id,name',
                'organization:id,name',
                'team:id,name',
                'sites',
                'databaseEngines',
                'cacheServices',
            ])
            ->withCount('sites')
            ->orderBy('name')
            ->get();

        $latestSnapshots = collect();
        if ($servers->isNotEmpty()) {
            $serverIds = $servers->pluck('id')->all();
            $latestSnapshots = ServerMetricSnapshot::query()
                ->whereIn('server_id', $serverIds)
                ->whereIn('id', function ($q) use ($serverIds): void {
                    $q->from('server_metric_snapshots')
                        ->selectRaw('MAX(id)')
                        ->whereIn('server_id', $serverIds)
                        ->groupBy('server_id');
                })
                ->get(['id', 'server_id', 'captured_at', 'payload'])
                ->keyBy('server_id');
        }

        $insightRollup = $servers->isNotEmpty()
            ? $insightsMetrics->perServerRollup($servers->pluck('id'))
            : collect();

        $relatedMap = ServerIndexAssembler::relatedServersMap($servers, $servers);

        $deployableIds = [];
        foreach ($servers as $server) {
            $hasDeployable = $server->sites->contains(function (Site $site) use ($server): bool {
                $site->setRelation('server', $server);

                return filled($site->git_repository_url)
                    && $server->status === Server::STATUS_READY
                    && $server->setup_status === Server::SETUP_STATUS_DONE;
            });
            if ($hasDeployable) {
                $deployableIds[$server->id] = true;
            }
        }

        return response()->json([
            'data' => $servers->map(function (Server $s) use ($latestSnapshots, $insightRollup, $relatedMap, $deployableIds) {
                $insights = $insightRollup[$s->id] ?? ['open' => 0, 'worst' => null];

                return ServerIndexAssembler::toArray(
                    $s,
                    $latestSnapshots->get($s->id),
                    (int) ($insights['open'] ?? 0),
                    isset($insights['worst']) ? (string) $insights['worst'] : null,
                    $relatedMap[$s->id] ?? [],
                    isset($deployableIds[$s->id]),
                );
            })->values(),
        ]);
    }

    /**
     * Run an arbitrary command on the server.
     */
    public function runCommand(Request $request, Server $server): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'command' => 'required|string|max:1000',
        ]);

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($validated['command']);

            return response()->json([
                'message' => 'Command completed.',
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Command failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
