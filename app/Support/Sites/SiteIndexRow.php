<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * View-model for the shared Sites index list UI — built from a local {@see Site}
 * or a Production API row so both surfaces reuse the same Blade.
 */
final readonly class SiteIndexRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $href,
        public string $typeLabel,
        public string $runtimeExecutionModeLabel,
        public ?string $phpVersion,
        public ?string $runtimeVersion,
        public ?string $runtimeKey,
        public ?string $primaryHostname,
        public int $extraDomains,
        public string $serverName,
        public ?string $serverHref,
        public string $runtimeProfileLabel,
        public ?string $workspaceName,
        public ?string $workspaceHref,
        public ?Carbon $lastDeployAt,
        public ?Carbon $createdAt,
        public bool $isProvisioning,
        public ?string $provisioningState,
        public bool $isFailed,
        public ?string $provisioningError,
        public string $statusLabel,
        public string $statusTone,
        public ?string $sslTone,
        public ?string $sslStatus,
        public ?string $visitUrl,
        public string $status,
        public ?string $serverId,
        public bool $isReadyForTraffic,
        public bool $isSecured,
    ) {}

    public static function fromSite(Site $site): self
    {
        $primary = $site->primaryDomain();
        $isProvisioning = $site->isProvisioning();
        $provisioningState = $site->provisioningState();
        $isFailed = $provisioningState === 'failed'
            || in_array($site->status, [
                Site::STATUS_ERROR,
                Site::STATUS_CONTAINER_FAILED,
                Site::STATUS_EDGE_FAILED,
                Site::STATUS_SCAFFOLD_FAILED,
            ], true);
        $statusTone = $isFailed ? 'danger' : ($isProvisioning ? 'warning' : ($site->isReadyForTraffic() ? 'success' : 'info'));
        $sslTone = match ($site->ssl_status) {
            Site::SSL_ACTIVE => 'success',
            Site::SSL_PENDING => 'warning',
            Site::SSL_FAILED => 'danger',
            default => null,
        };

        return new self(
            id: (string) $site->id,
            name: (string) $site->name,
            href: route('sites.show', [$site->server, $site]),
            typeLabel: $site->type->label(),
            runtimeExecutionModeLabel: $site->runtimeExecutionModeLabel(),
            phpVersion: $site->phpVersion(),
            runtimeVersion: $site->runtimeVersion(),
            runtimeKey: $site->runtimeKey(),
            primaryHostname: $primary?->hostname,
            extraDomains: max(0, $site->domains->count() - ($primary ? 1 : 0)),
            serverName: (string) ($site->server?->name ?? '—'),
            serverHref: $site->server ? route('servers.show', $site->server) : null,
            runtimeProfileLabel: $site->runtimeProfileLabel(),
            workspaceName: $site->workspace?->name,
            workspaceHref: $site->workspace ? route('projects.resources', $site->workspace) : null,
            lastDeployAt: $site->last_deploy_at,
            createdAt: $site->created_at,
            isProvisioning: $isProvisioning,
            provisioningState: $provisioningState,
            isFailed: $isFailed,
            provisioningError: $site->provisioningError(),
            statusLabel: $site->statusLabel(),
            statusTone: $statusTone,
            sslTone: $sslTone,
            sslStatus: $site->ssl_status,
            visitUrl: $site->visitUrl(),
            status: (string) $site->status,
            serverId: $site->server_id !== null ? (string) $site->server_id : null,
            isReadyForTraffic: $site->isReadyForTraffic(),
            isSecured: $site->ssl_status === Site::SSL_ACTIVE,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromProductionApi(array $row): self
    {
        $status = (string) ($row['status'] ?? '');
        $sslStatus = isset($row['ssl_status']) ? (string) $row['ssl_status'] : null;
        $type = (string) ($row['type'] ?? '');
        $typeLabel = self::typeLabel($type);
        $isProvisioning = in_array($status, [
            Site::STATUS_PENDING,
            Site::STATUS_CONTAINER_PROVISIONING,
            Site::STATUS_EDGE_PROVISIONING,
            Site::STATUS_SCAFFOLDING,
        ], true);
        $isFailed = in_array($status, [
            Site::STATUS_ERROR,
            Site::STATUS_CONTAINER_FAILED,
            Site::STATUS_EDGE_FAILED,
            Site::STATUS_SCAFFOLD_FAILED,
        ], true);
        $isReady = in_array($status, array_merge(Site::webserverActiveStatuses(), [
            Site::STATUS_DOCKER_ACTIVE,
            Site::STATUS_KUBERNETES_ACTIVE,
            Site::STATUS_FUNCTIONS_ACTIVE,
            Site::STATUS_CONTAINER_ACTIVE,
            Site::STATUS_EDGE_ACTIVE,
            Site::STATUS_CUSTOM_ACTIVE,
        ]), true);
        $statusTone = $isFailed ? 'danger' : ($isProvisioning ? 'warning' : ($isReady ? 'success' : 'info'));
        $sslTone = match ($sslStatus) {
            Site::SSL_ACTIVE => 'success',
            Site::SSL_PENDING => 'warning',
            Site::SSL_FAILED => 'danger',
            default => null,
        };
        $lastDeploy = isset($row['last_deploy_at']) && is_string($row['last_deploy_at']) && $row['last_deploy_at'] !== ''
            ? Carbon::parse($row['last_deploy_at'])
            : null;
        $createdAt = isset($row['created_at']) && is_string($row['created_at']) && $row['created_at'] !== ''
            ? Carbon::parse($row['created_at'])
            : null;
        $domainsCount = (int) ($row['domains_count'] ?? 0);
        $primaryHostname = isset($row['primary_hostname']) && is_string($row['primary_hostname']) && $row['primary_hostname'] !== ''
            ? $row['primary_hostname']
            : null;

        return new self(
            id: (string) ($row['id'] ?? ''),
            name: (string) ($row['name'] ?? $row['id'] ?? '—'),
            href: route('live.sites.show', $row['id'] ?? ''),
            typeLabel: $typeLabel,
            runtimeExecutionModeLabel: (string) ($row['runtime_mode_label'] ?? $row['runtime'] ?? '—'),
            phpVersion: isset($row['php_version']) ? (string) $row['php_version'] : null,
            runtimeVersion: isset($row['runtime_version']) ? (string) $row['runtime_version'] : null,
            runtimeKey: isset($row['runtime']) ? (string) $row['runtime'] : null,
            primaryHostname: $primaryHostname,
            extraDomains: max(0, $domainsCount - ($primaryHostname ? 1 : 0)),
            serverName: (string) ($row['server_name'] ?? '—'),
            serverHref: null,
            runtimeProfileLabel: (string) ($row['runtime_profile_label'] ?? $row['runtime'] ?? '—'),
            workspaceName: isset($row['workspace_name']) ? (string) $row['workspace_name'] : null,
            workspaceHref: null,
            lastDeployAt: $lastDeploy,
            createdAt: $createdAt,
            isProvisioning: $isProvisioning,
            provisioningState: isset($row['provisioning_state']) ? (string) $row['provisioning_state'] : null,
            isFailed: $isFailed,
            provisioningError: isset($row['provisioning_error']) ? (string) $row['provisioning_error'] : null,
            statusLabel: self::statusLabel($status),
            statusTone: $statusTone,
            sslTone: $sslTone,
            sslStatus: $sslStatus,
            visitUrl: isset($row['visit_url']) && is_string($row['visit_url']) && $row['visit_url'] !== ''
                ? $row['visit_url']
                : ($primaryHostname ? 'https://'.$primaryHostname : null),
            status: $status,
            serverId: isset($row['server_id']) ? (string) $row['server_id'] : null,
            isReadyForTraffic: $isReady,
            isSecured: $sslStatus === Site::SSL_ACTIVE,
        );
    }

    protected static function typeLabel(string $type): string
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

    protected static function statusLabel(string $status): string
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
}
