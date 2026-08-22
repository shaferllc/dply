<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Function list + detail for `dply serverless list` / `dply serverless status`.
 *
 * Serverless sites are Site rows whose runtime profile is a web action, so they
 * do surface in the generic /v1/sites list — but flattened into VM vocabulary
 * (document root, SSL status) that means nothing for a function. This endpoint
 * speaks the function's own language: runtime, limits, invoke URL, and the
 * recent-invocation health that the workspace header shows.
 */
class ServerlessSiteApiController extends ServerlessApiController
{
    /** Window used for the health rollup on show(). */
    private const HEALTH_WINDOW_HOURS = 24;

    public function index(Request $request): JsonResponse
    {
        $sites = Site::query()
            ->where('organization_id', $this->organization($request)->id)
            ->whereIn('meta->runtime_profile', Site::SERVERLESS_RUNTIME_PROFILES)
            ->with('server:id,name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sites->map(fn (Site $site) => $this->summary($site))->values(),
        ]);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $config = $found->serverlessConfig();

        return response()->json([
            'data' => $this->summary($found) + [
                'limits' => $found->serverlessLimits(),
                'keep_warm' => $found->serverlessKeepWarmEnabled(),
                'background_processing' => $found->serverlessBackgroundProcessingEnabled(),
                'action_name' => $found->serverlessActionName(),
                'namespace' => isset($config['namespace']) ? (string) $config['namespace'] : null,
                'health' => $this->health($found),
                // `dply init` follows two phases, and only the second one has
                // a SiteDeployment to poll. Namespace provisioning lives on
                // the host Server, so without this a bad DigitalOcean
                // credential — the likeliest first-run failure — would show up
                // as an endless wait for a deployment that never gets
                // dispatched.
                'provision' => $this->provisionState($found),
                'source_kind' => $found->serverlessConfig()['source_kind'] ?? 'git',
            ],
        ]);
    }

    /**
     * @return array{status: string, ready: bool, failed: bool, error: ?string}
     */
    private function provisionState(Site $site): array
    {
        $server = $site->server;
        $status = (string) ($server?->status ?? '');
        $meta = is_array($server?->meta) ? $server->meta : [];
        $error = trim((string) ($meta['provision_error'] ?? ''));

        return [
            'status' => $status,
            'ready' => $server !== null && $status === Server::STATUS_READY,
            'failed' => $status === Server::STATUS_ERROR,
            // Already redacted where it is written (DeployLogRedactor +
            // ServerlessCustomerCopy), so it is safe to hand back.
            'error' => $error !== '' ? $error : null,
        ];
    }

    /**
     * Fields shared by index and show. Keep these two in sync — the CLI
     * renders `serverless list` and `serverless status` from the same columns.
     *
     * @return array<string, mixed>
     */
    private function summary(Site $site): array
    {
        $config = $site->serverlessConfig();
        $runtime = trim((string) ($config['runtime'] ?? ''));
        $lastDeployedAt = $config['last_deployed_at'] ?? null;

        return [
            'id' => (string) $site->id,
            'name' => (string) $site->name,
            'status' => (string) $site->status,
            'is_live' => $site->status === Site::STATUS_FUNCTIONS_ACTIVE,
            'runtime' => $runtime !== '' ? $runtime : null,
            'runtime_profile' => $site->runtimeProfile(),
            'url' => $site->serverlessPublicUrl(),
            'git_repository_url' => $site->git_repository_url ?: null,
            'git_branch' => $site->git_branch ?: null,
            'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            'last_deployed_at' => is_string($lastDeployedAt) && $lastDeployedAt !== '' ? $lastDeployedAt : null,
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }

    /**
     * Recent-invocation rollup. Only settled rows count — an in-flight async
     * row carries a zero duration that would drag the average down and read
     * as a success it has not earned yet.
     *
     * @return array<string, mixed>
     */
    private function health(Site $site): array
    {
        $since = now()->subHours(self::HEALTH_WINDOW_HOURS);

        $rows = FunctionInvocation::query()
            ->where('site_id', $site->id)
            ->settled()
            ->where('created_at', '>=', $since)
            ->get(['success', 'duration_ms', 'cold']);

        $total = $rows->count();
        $failed = $rows->where('success', false)->count();

        return [
            'window_hours' => self::HEALTH_WINDOW_HOURS,
            'invocations' => $total,
            'failed' => $failed,
            'error_rate' => $total > 0 ? round($failed / $total, 4) : null,
            'cold_starts' => $rows->where('cold', true)->count(),
            'avg_duration_ms' => $total > 0 ? (int) round((float) $rows->avg('duration_ms')) : null,
        ];
    }
}
