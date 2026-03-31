<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsightFinding;
use App\Models\InsightHealthSnapshot;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    /**
     * Open findings for a server (org-scoped token).
     */
    public function serverFindings(Request $request, Server $server): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $findings = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->orderByDesc('severity')
            ->orderByDesc('detected_at')
            ->get();

        $playbooks = config('insights_playbooks', []);

        return response()->json([
            'data' => $findings->map(fn (InsightFinding $f) => [
                'id' => $f->id,
                'insight_key' => $f->insight_key,
                'severity' => $f->severity,
                'title' => $f->title,
                'body' => $f->body,
                'site_id' => $f->site_id,
                'team_id' => $f->team_id,
                'correlation' => $f->correlation,
                'meta' => $f->meta,
                'detected_at' => $f->detected_at?->toIso8601String(),
                'playbook' => $playbooks[$f->insight_key] ?? null,
            ]),
        ]);
    }

    /**
     * Fleet summary: open counts by severity and latest health score per server.
     */
    public function organizationSummary(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        $serverIds = Server::query()
            ->where('organization_id', $organization->id)
            ->pluck('id');

        $bySeverity = InsightFinding::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->selectRaw('severity, count(*) as c')
            ->groupBy('severity')
            ->pluck('c', 'severity');

        $latestScores = collect();
        if ($serverIds->isNotEmpty()) {
            $sub = DB::table('insight_health_snapshots')
                ->select('server_id', DB::raw('MAX(captured_at) as max_captured_at'))
                ->whereIn('server_id', $serverIds)
                ->groupBy('server_id');

            $latestScores = InsightHealthSnapshot::query()
                ->joinSub($sub, 'latest', function ($join): void {
                    $join->on('insight_health_snapshots.server_id', '=', 'latest.server_id')
                        ->on('insight_health_snapshots.captured_at', '=', 'latest.max_captured_at');
                })
                ->select('insight_health_snapshots.*')
                ->get()
                ->keyBy('server_id');
        }

        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'open_by_severity' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'warning' => (int) ($bySeverity['warning'] ?? 0),
                'info' => (int) ($bySeverity['info'] ?? 0),
            ],
            'servers' => $servers->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'health_score' => $latestScores[$s->id]->score ?? null,
                'health_captured_at' => $latestScores[$s->id]->captured_at?->toIso8601String() ?? null,
            ]),
        ]);
    }
}
