<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Enums\SiteType;
use App\Models\Site;

/**
 * Canonical JSON shape for a sites-index card — used by GET /api/v1/sites
 * and by {@see SiteIndexRow} so local Eloquent and Production API stay aligned.
 */
final class SiteIndexAssembler
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Site $site): array
    {
        $primary = $site->primaryDomain();
        $detection = $site->resolvedRuntimeAppDetection();
        $framework = is_array($detection) ? (string) ($detection['framework'] ?? '') : '';
        if ($framework === '' || $framework === 'unknown') {
            $framework = null;
        }

        $isProvisioning = $site->isProvisioning();
        $provisioningState = $site->provisioningState();
        $isFailed = $provisioningState === 'failed'
            || in_array($site->status, [
                Site::STATUS_ERROR,
                Site::STATUS_CONTAINER_FAILED,
                Site::STATUS_EDGE_FAILED,
                Site::STATUS_SCAFFOLD_FAILED,
            ], true);
        $isReady = $site->isReadyForTraffic();
        $statusTone = $isFailed ? 'danger' : ($isProvisioning ? 'warning' : ($isReady ? 'success' : 'info'));

        $gitUrl = trim((string) $site->git_repository_url);
        $gitBranch = trim((string) $site->git_branch);

        return [
            'id' => (string) $site->id,
            'server_id' => $site->server_id !== null ? (string) $site->server_id : null,
            'server_name' => $site->server?->name,
            'workspace_id' => $site->workspace_id !== null ? (string) $site->workspace_id : null,
            'workspace_name' => $site->workspace?->name,
            'name' => (string) $site->name,
            'type' => $site->type instanceof SiteType ? $site->type->value : (string) $site->type,
            'type_label' => $site->type instanceof SiteType ? $site->type->label() : self::typeLabel((string) $site->type),
            'runtime' => $site->runtime,
            'runtime_version' => $site->runtime_version,
            'php_version' => $site->phpVersion(),
            'runtime_mode_label' => $site->runtimeExecutionModeLabel(),
            'runtime_profile_label' => $site->runtimeProfileLabel(),
            'framework' => $framework,
            'framework_label' => $framework !== null ? self::frameworkLabel($framework) : null,
            'deploy_strategy' => $site->deploy_strategy,
            'deploy_strategy_label' => self::deployStrategyLabel((string) $site->deploy_strategy),
            'git_repository_url' => $gitUrl !== '' ? $gitUrl : null,
            'git_repo_label' => $gitUrl !== '' ? self::gitRepoLabel($gitUrl) : null,
            'git_branch' => $gitBranch !== '' ? $gitBranch : null,
            'status' => (string) $site->status,
            'status_label' => $site->statusLabel(),
            'status_tone' => $statusTone,
            'ssl_status' => $site->ssl_status,
            'document_root' => $site->document_root,
            'primary_hostname' => $primary?->hostname,
            'domains_count' => $site->relationLoaded('domains') ? $site->domains->count() : 0,
            'visit_url' => $site->visitUrl(),
            'provisioning_state' => $provisioningState,
            'provisioning_error' => $site->provisioningError(),
            'is_provisioning' => $isProvisioning,
            'is_failed' => $isFailed,
            'is_ready_for_traffic' => $isReady,
            'is_secured' => $site->ssl_status === Site::SSL_ACTIVE,
            'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }

    public static function typeLabel(string $type): string
    {
        if ($type === '') {
            return '—';
        }

        try {
            return SiteType::from($type)->label();
        } catch (\ValueError) {
            return str_replace('_', ' ', $type);
        }
    }

    public static function frameworkLabel(string $framework): string
    {
        return match (strtolower($framework)) {
            'laravel' => 'Laravel',
            'rails', 'ruby_on_rails' => 'Rails',
            'wordpress' => 'WordPress',
            'next', 'nextjs', 'next.js' => 'Next.js',
            'nuxt', 'nuxtjs' => 'Nuxt',
            'django' => 'Django',
            'flask' => 'Flask',
            'express' => 'Express',
            'static' => 'Static',
            default => str_replace('_', ' ', ucfirst($framework)),
        };
    }

    public static function deployStrategyLabel(string $strategy): string
    {
        return match ($strategy) {
            'atomic' => __('Zero downtime'),
            'simple' => __('In place'),
            default => $strategy !== '' ? str_replace('_', ' ', $strategy) : '—',
        };
    }

    public static function gitRepoLabel(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path) ?? $path;

        return $path !== '' ? $path : $url;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Site::STATUS_NGINX_ACTIVE => 'nginx active',
            Site::STATUS_APACHE_ACTIVE => 'apache active',
            Site::STATUS_CADDY_ACTIVE => 'caddy active',
            Site::STATUS_OPENLITESPEED_ACTIVE => 'openlitespeed active',
            Site::STATUS_TRAEFIK_ACTIVE => 'traefik active',
            Site::STATUS_DOCKER_CONFIGURED => 'docker configured',
            Site::STATUS_DOCKER_ACTIVE => 'docker active',
            Site::STATUS_KUBERNETES_CONFIGURED => 'kubernetes configured',
            Site::STATUS_KUBERNETES_ACTIVE => 'kubernetes active',
            Site::STATUS_FUNCTIONS_CONFIGURED => 'functions configured',
            Site::STATUS_FUNCTIONS_ACTIVE => 'functions active',
            Site::STATUS_CUSTOM_ACTIVE => 'custom active',
            default => str_replace('_', ' ', $status),
        };
    }

    public static function stripeClass(string $statusTone): string
    {
        return match ($statusTone) {
            'danger' => 'bg-red-500',
            'warning' => 'bg-amber-400',
            'success' => 'bg-emerald-500',
            default => 'bg-brand-mist',
        };
    }

    public static function sslTone(?string $sslStatus): ?string
    {
        return match ($sslStatus) {
            Site::SSL_ACTIVE => 'success',
            Site::SSL_PENDING => 'warning',
            Site::SSL_FAILED => 'danger',
            default => null,
        };
    }
}
