<?php

namespace App\Services\Sites\Clone;

use App\Livewire\Forms\SiteCreateForm;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Builds attributes for a cloned {@see Site} from a source site (resets secrets, SSL, env; strips provisioning noise).
 */
final class SiteCloneAttributeMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function baseAttributes(Site $source, Server $destServer, string $name, string $slug, string $primaryHostname): array
    {
        $host = strtolower(trim($primaryHostname));
        $repositoryPath = self::defaultRepositoryPathForHostname($host);
        $documentRoot = self::documentRootForSiteType($source, $repositoryPath);

        $meta = self::sanitizedMetaCopy($source);

        return [
            'server_id' => $destServer->id,
            'user_id' => $source->user_id,
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'dns_provider_credential_id' => null,
            'dns_zone' => null,
            'name' => $name,
            'slug' => $slug,
            'type' => $source->type,
            'document_root' => $documentRoot,
            'repository_path' => $source->usesFunctionsRuntime() || $source->usesDockerRuntime() || $source->usesKubernetesRuntime()
                ? $source->repository_path
                : $repositoryPath,
            'php_version' => $source->php_version,
            'app_port' => $source->app_port,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'nginx_installed_at' => null,
            'ssl_installed_at' => null,
            'last_deploy_at' => null,
            'suspended_at' => null,
            'suspended_reason' => null,
            'git_repository_url' => $source->git_repository_url,
            'git_branch' => $source->git_branch ?? 'main',
            'git_deploy_key_private' => null,
            'git_deploy_key_public' => null,
            'webhook_secret' => Str::random(48),
            'webhook_allowed_ips' => $source->webhook_allowed_ips,
            'post_deploy_command' => $source->post_deploy_command,
            'deploy_script_id' => $source->deploy_script_id,
            'deploy_strategy' => $source->deploy_strategy,
            'releases_to_keep' => $source->releases_to_keep,
            'nginx_extra_raw' => null,
            'engine_http_cache_enabled' => false,
            'octane_port' => $source->octane_port,
            'laravel_scheduler' => $source->laravel_scheduler,
            'restart_supervisor_programs_after_deploy' => $source->restart_supervisor_programs_after_deploy,
            'deployment_environment' => $source->deployment_environment,
            'php_fpm_user' => $source->php_fpm_user,
            'env_file_content' => null,
            'meta' => $meta,
        ];
    }

    /**
     * Mirrors {@see SiteCreateForm::defaultDeployPath()} for non-customized paths.
     */
    private static function defaultRepositoryPathForHostname(string $hostname): string
    {
        if ($hostname === '') {
            return SiteCreateForm::DEFAULT_DEPLOY_PATH;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $hostname);
        $slug = trim((string) $slug, '-');

        return $slug !== ''
            ? '/var/www/'.$slug
            : SiteCreateForm::DEFAULT_DEPLOY_PATH;
    }

    /**
     * Mirrors {@see SiteCreateForm::applyPathDefaults()} document_root rules.
     */
    private static function documentRootForSiteType(Site $source, string $repositoryPath): string
    {
        return $source->type->value === 'php'
            ? $repositoryPath.'/public'
            : $repositoryPath;
    }

    /**
     * @return array<string, mixed>
     */
    private static function sanitizedMetaCopy(Site $source): array
    {
        $meta = is_array($source->meta) ? $source->meta : [];

        unset(
            $meta['provisioning'],
            $meta['testing_hostname'],
            $meta['system_user_operation'],
            $meta['clone'],
        );

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function withCloneMeta(array $attributes, Site $source, string $phase, ?string $message = null): array
    {
        $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
        $meta['clone'] = [
            'source_site_id' => $source->id,
            'status' => $phase,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $attributes['meta'] = $meta;

        return $attributes;
    }
}
