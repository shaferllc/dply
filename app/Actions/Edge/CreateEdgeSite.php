<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Enums\SiteType;
use App\Jobs\BuildEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Edge\EdgeOrgCredentialConfig;
use Illuminate\Support\Str;
use RuntimeException;

class CreateEdgeSite
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, Organization $organization, array $payload): Site
    {
        $name = (string) ($payload['name'] ?? '');
        $slug = Str::slug($name) ?: 'edge-'.Str::random(6);
        $repo = $this->normalizeRepo((string) ($payload['repo'] ?? ''));
        if ($repo === '') {
            throw new \InvalidArgumentException('A Git repository (owner/name) is required.');
        }

        $branch = (string) ($payload['branch'] ?? 'main') ?: 'main';
        $deployOnPush = ! array_key_exists('deploy_on_push', $payload) || (bool) $payload['deploy_on_push'];
        $buildCommand = (string) ($payload['build_command'] ?? 'npm ci && npm run build');
        $outputDir = (string) ($payload['output_dir'] ?? 'dist');
        $framework = (string) ($payload['framework'] ?? '');
        $spaFallback = ! array_key_exists('spa_fallback', $payload) || (bool) $payload['spa_fallback'];
        $runtimeMode = (string) ($payload['runtime_mode'] ?? 'static');
        if ($runtimeMode === 'ssr') {
            throw new RuntimeException('SSR Edge sites are not supported yet. Use static export or SSG.');
        }

        $edgeBackend = (string) ($payload['edge_backend'] ?? config('edge.default_backend', 'dply_edge'));
        $edgeCredential = $this->resolveEdgeCredential($organization, $edgeBackend, $payload);
        $hostname = $this->resolveHostname($slug, $edgeBackend, $edgeCredential);

        $server = Server::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'edge-'.$slug,
            'status' => Server::STATUS_READY,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DPLY_EDGE,
            ],
        ]);

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::Static,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'edge_backend' => $edgeBackend,
            'edge_provider_credential_id' => $edgeCredential?->id,
            'status' => Site::STATUS_EDGE_PROVISIONING,
            'webhook_secret' => Str::random(48),
            'meta' => [
                'runtime_profile' => 'edge_web',
                'edge' => [
                    'runtime_mode' => 'static',
                    'source' => [
                        'repo' => $repo,
                        'branch' => $branch,
                        'deploy_on_push' => $deployOnPush,
                    ],
                    'build' => [
                        'command' => $buildCommand,
                        'output_dir' => $outputDir,
                        'framework' => $framework,
                    ],
                    'routing' => [
                        'spa_fallback' => $spaFallback,
                        'headers' => [],
                        'hostname' => $hostname,
                    ],
                    'live_url' => 'https://'.$hostname,
                ],
            ],
        ]);

        $prefix = trim((string) config('edge.r2.key_prefix', 'edge/'), '/')
            .'/'.$organization->id.'/'.$site->id.'/'.Str::ulid();

        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $organization->id,
            'status' => EdgeDeployment::STATUS_BUILDING,
            'git_branch' => $branch,
            'storage_prefix' => $prefix,
        ]);

        BuildEdgeSiteJob::dispatch($deployment->id);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveEdgeCredential(
        Organization $organization,
        string $edgeBackend,
        array $payload,
    ): ?ProviderCredential {
        if ($edgeBackend !== 'org_cloudflare') {
            return null;
        }

        $credentialId = trim((string) ($payload['edge_provider_credential_id'] ?? ''));
        if ($credentialId === '') {
            throw new RuntimeException('Select a Cloudflare account for BYO Edge delivery.');
        }

        $credential = ProviderCredential::query()
            ->where('organization_id', $organization->id)
            ->where('provider', 'cloudflare')
            ->find($credentialId);

        if ($credential === null) {
            throw new RuntimeException('Selected Cloudflare credential was not found for this organization.');
        }

        if (! EdgeOrgCredentialConfig::isBootstrapped($credential)) {
            throw new RuntimeException(
                'Cloudflare credential is not bootstrapped for Edge. Run: php artisan dply:edge:bootstrap-org '.$credential->id,
            );
        }

        return $credential;
    }

    private function resolveHostname(string $slug, string $edgeBackend, ?ProviderCredential $credential): string
    {
        $suffix = strtolower(Str::random(6));

        if ($edgeBackend === 'org_cloudflare' && $credential instanceof ProviderCredential) {
            $edge = EdgeOrgCredentialConfig::read($credential);
            $zone = strtolower(trim((string) ($edge['worker_zone_name'] ?? '')));
            if ($zone !== '') {
                return strtolower($slug.'-'.$suffix.'.'.$zone);
            }
        }

        $testingDomain = (string) (config('edge.testing_domains')[0] ?? 'dply.host');

        return strtolower($slug.'-'.$suffix.'.'.$testingDomain);
    }

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
