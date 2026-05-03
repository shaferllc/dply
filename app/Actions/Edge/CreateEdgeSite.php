<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Enums\SiteType;
use App\Jobs\ProvisionEdgeSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgeRouter;
use Illuminate\Support\Str;

/**
 * Creates a Site row representing a container app on the dply
 * edge platform, then dispatches the provision job that talks
 * to the chosen backend (DO App Platform or AWS App Runner).
 */
class CreateEdgeSite
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        User $user,
        Organization $organization,
        array $payload,
    ): Site {
        $name = (string) ($payload['name'] ?? '');
        $slug = Str::slug($name) ?: 'edge-app-'.Str::random(6);
        $image = (string) ($payload['image'] ?? '');
        $port = (int) ($payload['port'] ?? 8080);
        $instances = max(1, (int) ($payload['instances'] ?? 1));
        $sizeTier = (string) ($payload['size_tier'] ?? 'small');
        if (! in_array($sizeTier, ['small', 'medium', 'large', 'xlarge'], true)) {
            $sizeTier = 'small';
        }
        $region = (string) ($payload['region'] ?? '');
        $envFile = (string) ($payload['env_file_content'] ?? '');

        $backend = (string) ($payload['backend'] ?? 'auto');
        if ($backend === 'auto') {
            $resolved = EdgeRouter::pickAutoBackend($organization->id);
            if ($resolved === null) {
                throw new \RuntimeException(
                    'No container backend connected. Connect DigitalOcean App Platform or AWS App Runner credentials first.',
                );
            }
            $backend = $resolved;
        }

        // Each edge site gets its own virtual "server" row of
        // host_kind 'dply_edge'. Sites that share a backend live
        // on different rows so each one can be torn down without
        // touching peers.
        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_PENDING,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
                'edge' => [
                    'backend' => $backend,
                    'region' => $region,
                ],
            ],
        ]);

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => $image,
            'container_port' => $port,
            'container_backend' => $backend,
            'container_region' => $region,
            'env_file_content' => $envFile,
            'status' => Site::STATUS_PENDING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'container' => [
                    'instance_count' => $instances,
                    'size_tier' => $sizeTier,
                ],
            ],
        ]);

        ProvisionEdgeSiteJob::dispatch($site->id);

        return $site;
    }
}
