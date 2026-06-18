<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Actions\Cloud\CreateCloudSiteFromSource;
use App\Modules\Edge\Jobs\ProvisionHybridEdgeStackJob;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Edge\Support\EdgeSsrDetection;
use App\Modules\Edge\Support\HybridEdgeOriginMatcher;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * One-click SSR stack: provision a Cloud origin (when needed) and
 * queue creation of the hybrid Edge front once the origin URL exists.
 */
class CreateHybridEdgeStack
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{cloud_site: Site, edge_site: ?Site, redirect_to: 'cloud'|'edge'}
     */
    public function handle(User $user, Organization $organization, array $payload): array
    {
        if (! Feature::active('surface.cloud')) {
            throw new RuntimeException('dply Cloud is not available for this organization.');
        }

        if (CloudRouter::pickAutoBackend($organization->id) === null) {
            throw new RuntimeException(
                'No container backend connected. Connect DigitalOcean App Platform or AWS App Runner credentials first.',
            );
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $repo = trim((string) ($payload['repo'] ?? ''));
        $branch = trim((string) ($payload['branch'] ?? 'main')) ?: 'main';
        $detectedPlan = is_array($payload['detected_plan'] ?? null) ? $payload['detected_plan'] : [];

        if ($name === '' || $repo === '') {
            throw new \InvalidArgumentException('App name and Git repository are required.');
        }

        if ($detectedPlan !== [] && ! EdgeSsrDetection::planLooksLikeSsr($detectedPlan)) {
            throw new RuntimeException('Hybrid stack deploy is only available for server-rendered JavaScript frameworks.');
        }

        $edgePayload = $this->edgePayloadFromForm($payload);

        $existingOrigin = HybridEdgeOriginMatcher::findForRepo($organization, $repo);
        if ($existingOrigin !== null) {
            $edgeSite = (new CreateEdgeSite)->handle($user, $organization, array_merge($edgePayload, [
                'runtime_mode' => 'hybrid',
                'origin_url' => (string) $existingOrigin->containerLiveUrl(),
                'cloud_site_id' => $existingOrigin->id,
                'origin_managed' => true,
            ]));

            return [
                'cloud_site' => $existingOrigin,
                'edge_site' => $edgeSite,
                'redirect_to' => 'edge',
            ];
        }

        $originName = $name.' origin';
        $port = (int) ($detectedPlan['app_port'] ?? $payload['app_port'] ?? 3000);
        if ($port < 1) {
            $port = 3000;
        }

        $cloudSite = (new CreateCloudSiteFromSource)->handle($user, $organization, [
            'name' => $originName,
            'repo' => $repo,
            'branch' => $branch,
            'port' => $port,
            'deploy_on_push' => $edgePayload['deploy_on_push'] ?? true,
            'backend' => 'auto',
            'size_tier' => 'small',
        ]);

        $meta = $cloudSite->meta;
        $meta['container'] = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $meta['container']['hybrid_edge_stack'] = [
            'status' => 'awaiting_origin',
            'edge_name' => $name,
            'edge_payload' => $edgePayload,
            'edge_site_id' => null,
            'poll_attempts' => 0,
            'error' => null,
            'created_at' => now()->toIso8601String(),
        ];
        $cloudSite->update(['meta' => $meta]);

        ProvisionHybridEdgeStackJob::dispatch($cloudSite->id);

        return [
            'cloud_site' => $cloudSite->fresh(),
            'edge_site' => null,
            'redirect_to' => 'cloud',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function edgePayloadFromForm(array $payload): array
    {
        $buildCommand = trim((string) ($payload['build_command'] ?? ''));
        $outputDir = trim((string) ($payload['output_dir'] ?? ''));
        $detectedPlan = is_array($payload['detected_plan'] ?? null) ? $payload['detected_plan'] : [];

        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'repo' => trim((string) ($payload['repo'] ?? '')),
            'branch' => trim((string) ($payload['branch'] ?? 'main')) ?: 'main',
            'build_command' => $buildCommand !== '' ? $buildCommand : 'npm ci && npm run build',
            'output_dir' => $outputDir !== '' ? $outputDir : 'dist',
            'spa_fallback' => ! array_key_exists('spa_fallback', $payload) || (bool) $payload['spa_fallback'],
            'deploy_on_push' => ! array_key_exists('deploy_on_push', $payload) || (bool) $payload['deploy_on_push'],
            'framework' => (string) ($detectedPlan['framework'] ?? $payload['framework'] ?? ''),
            'origin_routes' => is_array($payload['origin_routes'] ?? null)
                ? $payload['origin_routes']
                : ['/_next/*', '/api/*'],
            'edge_backend' => (string) ($payload['edge_backend'] ?? config('edge.default_backend', 'dply_edge')),
            'edge_provider_credential_id' => $payload['edge_provider_credential_id'] ?? null,
        ];
    }
}
