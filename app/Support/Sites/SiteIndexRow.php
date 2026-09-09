<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * View-model for the shared Sites index list UI — built from a local {@see Site}
 * (via {@see SiteIndexAssembler}) or a Production API row so both surfaces
 * reuse the same Blade.
 */
final readonly class SiteIndexRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $href,
        public string $manageHref,
        public bool $manageExternal,
        public string $typeLabel,
        public string $runtimeExecutionModeLabel,
        public ?string $phpVersion,
        public ?string $runtimeVersion,
        public ?string $runtimeKey,
        public ?string $frameworkLabel,
        public ?string $primaryHostname,
        public int $extraDomains,
        public string $serverName,
        public ?string $serverHref,
        public string $runtimeProfileLabel,
        public ?string $workspaceName,
        public ?string $workspaceHref,
        public ?string $gitRepoLabel,
        public ?string $gitBranch,
        public ?string $deployStrategyLabel,
        public ?Carbon $lastDeployAt,
        public ?Carbon $createdAt,
        public bool $isProvisioning,
        public ?string $provisioningState,
        public bool $isFailed,
        public ?string $provisioningError,
        public string $statusLabel,
        public string $statusTone,
        public string $stripeClass,
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
        return self::fromPayload(
            SiteIndexAssembler::toArray($site),
            manageHref: route('sites.show', [$site->server, $site]),
            manageExternal: false,
            serverHref: $site->server ? route('servers.show', $site->server) : null,
            workspaceHref: $site->workspace ? route('projects.resources', $site->workspace) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromPayload(
        array $row,
        string $manageHref,
        bool $manageExternal,
        ?string $serverHref,
        ?string $workspaceHref,
    ): self {
        $status = (string) ($row['status'] ?? '');
        $sslStatus = isset($row['ssl_status']) ? (string) $row['ssl_status'] : null;
        $type = (string) ($row['type'] ?? '');
        $typeLabel = isset($row['type_label']) && is_string($row['type_label']) && $row['type_label'] !== ''
            ? $row['type_label']
            : SiteIndexAssembler::typeLabel($type);

        $isProvisioning = array_key_exists('is_provisioning', $row)
            ? (bool) $row['is_provisioning']
            : in_array($status, [
                Site::STATUS_PENDING,
                Site::STATUS_CONTAINER_PROVISIONING,
                Site::STATUS_EDGE_PROVISIONING,
                Site::STATUS_SCAFFOLDING,
            ], true);
        $isFailed = array_key_exists('is_failed', $row)
            ? (bool) $row['is_failed']
            : in_array($status, [
                Site::STATUS_ERROR,
                Site::STATUS_CONTAINER_FAILED,
                Site::STATUS_EDGE_FAILED,
                Site::STATUS_FUNCTIONS_FAILED,
                Site::STATUS_SCAFFOLD_FAILED,
            ], true);
        $isReady = array_key_exists('is_ready_for_traffic', $row)
            ? (bool) $row['is_ready_for_traffic']
            : in_array($status, array_merge(Site::webserverActiveStatuses(), [
                Site::STATUS_DOCKER_ACTIVE,
                Site::STATUS_KUBERNETES_ACTIVE,
                Site::STATUS_FUNCTIONS_ACTIVE,
                Site::STATUS_CONTAINER_ACTIVE,
                Site::STATUS_EDGE_ACTIVE,
                Site::STATUS_CUSTOM_ACTIVE,
            ]), true);

        $statusTone = isset($row['status_tone']) && is_string($row['status_tone']) && $row['status_tone'] !== ''
            ? $row['status_tone']
            : ($isFailed ? 'danger' : ($isProvisioning ? 'warning' : ($isReady ? 'success' : 'info')));

        $statusLabel = isset($row['status_label']) && is_string($row['status_label']) && $row['status_label'] !== ''
            ? $row['status_label']
            : SiteIndexAssembler::statusLabel($status);

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

        $frameworkLabel = isset($row['framework_label']) && is_string($row['framework_label']) && $row['framework_label'] !== ''
            ? $row['framework_label']
            : (isset($row['framework']) && is_string($row['framework']) && $row['framework'] !== '' && $row['framework'] !== 'unknown'
                ? SiteIndexAssembler::frameworkLabel($row['framework'])
                : null);

        $deployStrategy = isset($row['deploy_strategy']) ? (string) $row['deploy_strategy'] : '';
        $deployStrategyLabel = isset($row['deploy_strategy_label']) && is_string($row['deploy_strategy_label']) && $row['deploy_strategy_label'] !== ''
            ? $row['deploy_strategy_label']
            : ($deployStrategy !== '' ? SiteIndexAssembler::deployStrategyLabel($deployStrategy) : null);

        $gitUrl = isset($row['git_repository_url']) && is_string($row['git_repository_url']) ? $row['git_repository_url'] : null;
        $gitRepoLabel = isset($row['git_repo_label']) && is_string($row['git_repo_label']) && $row['git_repo_label'] !== ''
            ? $row['git_repo_label']
            : ($gitUrl ? SiteIndexAssembler::gitRepoLabel($gitUrl) : null);

        $visitUrl = isset($row['visit_url']) && is_string($row['visit_url']) && $row['visit_url'] !== ''
            ? $row['visit_url']
            : ($primaryHostname ? 'https://'.$primaryHostname : null);

        return new self(
            id: (string) ($row['id'] ?? ''),
            name: (string) ($row['name'] ?? $row['id'] ?? '—'),
            href: $manageHref,
            manageHref: $manageHref,
            manageExternal: $manageExternal,
            typeLabel: $typeLabel,
            runtimeExecutionModeLabel: (string) ($row['runtime_mode_label'] ?? $row['runtime'] ?? '—'),
            phpVersion: isset($row['php_version']) ? (string) $row['php_version'] : null,
            runtimeVersion: isset($row['runtime_version']) ? (string) $row['runtime_version'] : null,
            runtimeKey: isset($row['runtime']) ? (string) $row['runtime'] : null,
            frameworkLabel: $frameworkLabel,
            primaryHostname: $primaryHostname,
            extraDomains: max(0, $domainsCount - ($primaryHostname ? 1 : 0)),
            serverName: (string) ($row['server_name'] ?? '—'),
            serverHref: $serverHref,
            runtimeProfileLabel: (string) ($row['runtime_profile_label'] ?? $row['runtime'] ?? '—'),
            workspaceName: isset($row['workspace_name']) ? (string) $row['workspace_name'] : null,
            workspaceHref: $workspaceHref,
            gitRepoLabel: $gitRepoLabel,
            gitBranch: isset($row['git_branch']) && is_string($row['git_branch']) && $row['git_branch'] !== ''
                ? $row['git_branch']
                : null,
            deployStrategyLabel: $deployStrategyLabel,
            lastDeployAt: $lastDeploy,
            createdAt: $createdAt,
            isProvisioning: $isProvisioning,
            provisioningState: isset($row['provisioning_state']) ? (string) $row['provisioning_state'] : null,
            isFailed: $isFailed,
            provisioningError: isset($row['provisioning_error']) ? (string) $row['provisioning_error'] : null,
            statusLabel: $statusLabel,
            statusTone: $statusTone,
            stripeClass: SiteIndexAssembler::stripeClass($statusTone),
            sslTone: SiteIndexAssembler::sslTone($sslStatus),
            sslStatus: $sslStatus,
            visitUrl: $visitUrl,
            status: $status,
            serverId: isset($row['server_id']) ? (string) $row['server_id'] : null,
            isReadyForTraffic: $isReady,
            isSecured: array_key_exists('is_secured', $row)
                ? (bool) $row['is_secured']
                : $sslStatus === Site::SSL_ACTIVE,
        );
    }
}
