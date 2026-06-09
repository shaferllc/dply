<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Support\Servers\SharedHostFairnessAdvisor;
use App\Support\Servers\SharedHostLlmAdvisor;
use App\Support\Servers\SharedHostReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerSharedHostController extends Controller
{
    public function explain(
        Request $request,
        Server $server,
        SharedHostReport $report,
        SharedHostFairnessAdvisor $fairnessAdvisor,
        SharedHostLlmAdvisor $llmAdvisor,
    ): JsonResponse {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! workspace_shared_host_active($organization) || $server->sites()->count() < 2) {
            return response()->json([
                'message' => 'Shared Host Radar requires at least two sites on this server.',
            ], 422);
        }

        $reportData = $report->forServer($server);
        $advisor = $fairnessAdvisor->advise($server, $reportData);
        $llmRun = $llmAdvisor->latestRun($server);

        return response()->json([
            'data' => [
                'server_id' => $server->id,
                'overall' => $reportData['overall'] ?? 'ok',
                'site_count' => (int) ($reportData['site_count'] ?? 0),
                'contention_count' => (int) ($reportData['contention_count'] ?? 0),
                'summary' => $advisor['summary'],
                'severity' => $advisor['severity'],
                'recommendations' => $advisor['recommendations'],
                'ai_narrative' => $llmAdvisor->narrativeFromRun($llmRun),
                'briefing' => $llmAdvisor->notificationBriefing($server, $reportData),
                'radar_url' => route('servers.shared-host', $server, absolute: true),
            ],
        ]);
    }
}
