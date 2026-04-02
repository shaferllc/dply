<?php

namespace App\Actions\Sites;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Str;

class CreateContainerSiteFromInspection
{
    /**
     * @param  array<string, mixed>  $inspection
     */
    public function handle(
        Server $server,
        User $user,
        Organization $organization,
        array $inspection,
        string $targetFamily,
    ): Site {
        $detection = $inspection['detection'];
        $nameBase = (string) ($inspection['slug'] ?? 'project');
        $siteName = (string) ($inspection['name'] ?? 'Project');
        $hostname = $this->primaryHostname($nameBase, $targetFamily);

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'deploy_script_id' => $organization->default_site_script_id,
            'name' => $siteName,
            'slug' => Str::slug($siteName) ?: 'site',
            'type' => $detection['site_type'] instanceof SiteType ? $detection['site_type'] : SiteType::Php,
            'document_root' => (string) ($detection['document_root'] ?? '/var/www/app/public'),
            'repository_path' => (string) ($detection['repository_path'] ?? '/var/www/app'),
            'php_version' => ($detection['site_type'] ?? null) instanceof SiteType && $detection['site_type']->value === 'php' ? '8.3' : null,
            'app_port' => isset($detection['app_port']) ? (int) $detection['app_port'] : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => (string) ($inspection['repository_url'] ?? ''),
            'git_branch' => (string) ($inspection['repository_branch'] ?? 'main'),
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            'env_file_content' => $this->defaultEnvFileContent($detection, $hostname),
            'meta' => $this->siteMetaFromDetection($detection, $targetFamily),
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => $hostname,
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $serverMeta = is_array($server->meta) ? $server->meta : [];
        $serverMeta['container_launch'] = array_merge(is_array($serverMeta['container_launch'] ?? null) ? $serverMeta['container_launch'] : [], [
            'site_id' => (string) $site->id,
            'target_family' => $targetFamily,
        ]);
        $server->forceFill(['meta' => $serverMeta])->save();

        return $site;
    }

    /**
     * @param  array<string, mixed>  $detection
     * @return array<string, mixed>
     */
    private function siteMetaFromDetection(array $detection, string $targetFamily): array
    {
        $repositorySubdirectory = trim((string) ($detection['repository_subdirectory']
            ?? data_get($detection, 'repository.subdirectory')
            ?? ''));
        $detected = [
            'framework' => $detection['framework'] ?? 'unknown',
            'language' => $detection['language'] ?? 'unknown',
            'confidence' => $detection['confidence'] ?? 'low',
            'reasons' => $detection['reasons'] ?? [],
            'warnings' => $detection['warnings'] ?? [],
            'detected_files' => $detection['detected_files'] ?? [],
            'env_template' => $detection['env_template'] ?? ['path' => null, 'keys' => []],
        ];

        $mode = str_contains($targetFamily, 'kubernetes') ? 'kubernetes' : 'docker';
        $platform = str_starts_with($targetFamily, 'local_') ? 'local'
            : (str_starts_with($targetFamily, 'digitalocean_') ? 'digitalocean'
                : (str_starts_with($targetFamily, 'aws_') ? 'aws' : 'local'));
        $provider = str_starts_with($targetFamily, 'digitalocean_') ? 'digitalocean'
            : (str_starts_with($targetFamily, 'aws_') ? 'aws' : 'orbstack');

        $meta = [
            'runtime_profile' => $mode === 'kubernetes' ? 'kubernetes_web' : 'docker_web',
            'runtime_target' => [
                'family' => $targetFamily,
                'platform' => $platform,
                'provider' => $provider,
                'mode' => $mode,
                'status' => 'pending',
                'logs' => [],
                'detected' => $detected,
                'repository_subdirectory' => $repositorySubdirectory,
            ],
        ];

        if ($mode === 'kubernetes') {
            $meta['kubernetes_runtime'] = [
                'app_type' => $detection['site_type']->value,
                'namespace' => $detection['kubernetes_namespace'] ?: 'default',
                'auto_created' => true,
                'detected' => $detected,
                'repository_subdirectory' => $repositorySubdirectory,
            ];

            return $meta;
        }

        $meta['docker_runtime'] = [
            'app_type' => $detection['site_type']->value,
            'auto_created' => true,
            'detected' => $detected,
            'repository_subdirectory' => $repositorySubdirectory,
        ];

        return $meta;
    }

    private function primaryHostname(string $base, string $targetFamily): string
    {
        $candidateBase = Str::limit(Str::slug($base), 40, '');
        $suffix = Str::lower(Str::random(6));

        return match (true) {
            str_starts_with($targetFamily, 'local_') => "{$candidateBase}-{$suffix}.local.dply.test",
            str_starts_with($targetFamily, 'digitalocean_') => "{$candidateBase}-{$suffix}.do-preview.dply.test",
            str_starts_with($targetFamily, 'aws_') => "{$candidateBase}-{$suffix}.aws-preview.dply.test",
            default => "{$candidateBase}-{$suffix}.preview.dply.test",
        };
    }

    /**
     * @param  array<string, mixed>  $detection
     */
    private function defaultEnvFileContent(array $detection, string $hostname): string
    {
        if (($detection['framework'] ?? null) !== 'laravel') {
            return '';
        }

        return implode("\n", [
            'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_URL=http://'.$hostname,
        ]);
    }
}
