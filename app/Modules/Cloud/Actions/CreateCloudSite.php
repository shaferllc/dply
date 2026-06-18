<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

use App\Enums\SiteType;
use App\Jobs\ProvisionCloudSiteJob;
use App\Models\CloudDeployTask;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Support\Str;

/**
 * Creates a Site row representing a container app on the dply
 * cloud platform, then dispatches the provision job that talks
 * to the chosen backend (DO App Platform or AWS App Runner).
 */
class CreateCloudSite
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
        if (! array_key_exists($sizeTier, CloudDeployTask::SIZE_TIERS)) {
            $sizeTier = 'small';
        }
        $region = (string) ($payload['region'] ?? '');
        $envFile = (string) ($payload['env_file_content'] ?? '');

        $backend = (string) ($payload['backend'] ?? 'auto');
        if ($backend === 'auto') {
            $resolved = CloudRouter::pickAutoBackend($organization->id);
            if ($resolved === null) {
                throw new \RuntimeException(
                    'No container backend connected. Connect DigitalOcean App Platform or AWS App Runner credentials first.',
                );
            }
            $backend = $resolved;
        }

        // Each cloud site gets its own virtual "server" row of
        // host_kind 'dply_cloud'. Sites that share a backend live
        // on different rows so each one can be torn down without
        // touching peers.
        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_PENDING,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_CLOUD,
                'edge' => [
                    'backend' => $backend,
                    'region' => $region,
                ],
            ],
        ]);

        // Stable on-dply.cloud subdomain that travels with the site even
        // if we migrate it between backends. Derived from the slug +
        // short random suffix so collisions are negligible.
        $dplySubdomain = Site::generateDplyCloudSubdomain($name, Str::random(8));

        // GHCR images need a `ghcr` ProviderCredential attached so the
        // backend can pass `username:token` into the App Platform image
        // spec. Pick the org's first GHCR credential automatically; if
        // none exists, leave it null — DO will reject the spec with a
        // clear "image does not exist or is private" message at create
        // time, which is the surface the user already sees in propose.
        $imageCredentialId = null;
        if (str_starts_with(strtolower((string) ($payload['image'] ?? '')), 'ghcr.io/')) {
            $ghcr = ProviderCredential::query()
                ->where('organization_id', $organization->id)
                ->where('provider', 'ghcr')
                ->orderBy('created_at')
                ->first();
            $imageCredentialId = $ghcr?->id;
        }

        $containerMeta = [
            'instance_count' => $instances,
            'size_tier' => $sizeTier,
            'dply_subdomain' => $dplySubdomain,
        ];
        if ($imageCredentialId !== null) {
            $containerMeta['image_credential_id'] = $imageCredentialId;
        }

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
            'meta' => ['container' => $containerMeta],
        ]);

        // Prepend the dply subdomain to the user's domains list so the
        // existing pending_domains/attach flow registers it on DO as a
        // PRIMARY custom domain alongside any user-supplied domains.
        $payload['domains'] = array_values(array_unique(array_merge(
            [$dplySubdomain],
            is_array($payload['domains'] ?? null) ? $payload['domains'] : [],
        )));

        (new ApplyCloudSiteExtras)->handle($site, $payload);

        ProvisionCloudSiteJob::dispatch($site->id);

        return $site;
    }
}
