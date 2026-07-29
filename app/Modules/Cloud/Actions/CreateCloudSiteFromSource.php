<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\ProvisionCloudSiteJob;
use App\Models\CloudDeployTask;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Support\Str;

/**
 * Source-mode counterpart to {@see CreateCloudSite}: instead of a
 * pre-built container image, the operator points at a GitHub repo
 * and the backend (DO App Platform / AWS App Runner) handles the
 * build + deploy + auto-redeploy on push.
 *
 * The Site row carries no container_image; instead its meta records
 * the source spec under meta.container.source = { repo, branch,
 * dockerfile_path?, deploy_on_push }. ProvisionCloudSiteJob notices
 * the source key and routes to the backend's provisionFromSource
 * verb instead of provision.
 */
class CreateCloudSiteFromSource
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
        $repo = $this->normalizeRepo((string) ($payload['repo'] ?? ''));
        if ($repo === '') {
            throw new \InvalidArgumentException('A GitHub repository (owner/name) is required.');
        }
        $branch = (string) ($payload['branch'] ?? 'main') ?: 'main';
        $port = (int) ($payload['port'] ?? 8080);
        $instances = max(1, (int) ($payload['instances'] ?? 1));
        $sizeTier = (string) ($payload['size_tier'] ?? 'small');
        if (! array_key_exists($sizeTier, CloudDeployTask::SIZE_TIERS)) {
            $sizeTier = 'small';
        }
        $region = (string) ($payload['region'] ?? '');
        $dockerfilePath = (string) ($payload['dockerfile_path'] ?? '');
        $deployOnPush = ! array_key_exists('deploy_on_push', $payload) || (bool) $payload['deploy_on_push'];
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

        $sourceSpec = [
            'repo' => $repo,
            'branch' => $branch,
            'deploy_on_push' => $deployOnPush,
        ];
        if ($dockerfilePath !== '') {
            $sourceSpec['dockerfile_path'] = $dockerfilePath;
        }

        // Brand-canonical hostname for this site. Same generation as
        // the image-mode CreateCloudSite path; stays consistent if the
        // app ever moves between backends.
        $dplySubdomain = Site::generateDplyCloudSubdomain($name, Str::random(8));

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
            'container_image' => null,
            'container_port' => $port,
            'container_backend' => $backend,
            'container_region' => $region,
            'env_file_content' => $envFile,
            'status' => Site::STATUS_PENDING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'container' => [
                    'source' => $sourceSpec,
                    'instance_count' => $instances,
                    'size_tier' => $sizeTier,
                    'dply_subdomain' => $dplySubdomain,
                ],
            ],
        ]);

        $payload['domains'] = array_values(array_unique(array_merge(
            [$dplySubdomain],
            is_array($payload['domains'] ?? null) ? $payload['domains'] : [],
        )));

        (new ApplyCloudSiteExtras)->handle($site, $payload);

        ProvisionCloudSiteJob::dispatch($site->id);

        return $site;
    }

    /**
     * Accept either an "owner/name" pair or a full GitHub URL and
     * normalize to "owner/name" — that's the form DO App Platform
     * expects in its github spec, and the form we store on the Site.
     */
    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
