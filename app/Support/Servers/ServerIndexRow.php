<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Canonical server list DTO — local Eloquent and Production API both map here
 * so `/servers` and `/live/servers` render the same Blade.
 */
final readonly class ServerIndexRow
{
    /**
     * @param  list<string>  $tags
     * @param  array{cpu: float|null, ram: float|null, disk: float|null, captured_at?: string|null}|null  $metrics
     * @param  list<array<string, mixed>>  $sites
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $related
     * @param  array{phase_label: string, step_label: string, step_index: int|null, step_total: int|null, elapsed_human: string|null}|null  $provisioning
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $ipAddress,
        public string $status,
        public string $setupStatus,
        public ?string $healthStatus,
        public string $provider,
        public string $providerLabel,
        public ?string $logoUrl,
        public int $sitesCount,
        public ?string $workspaceName,
        public ?string $workspaceId,
        public ?string $workspaceHref,
        public string $groupLabel,
        public array $tags,
        public ?Carbon $scheduledDeletionAt,
        public ?Carbon $createdAt,
        public ?int $uptimeDays,
        public ?string $workerLabel,
        public string $manageHref,
        public bool $manageExternal,
        public ?array $metrics,
        public string $stripeClass,
        public string $statusTone,
        public string $statusLabel,
        public bool $isFullyReady,
        public bool $isSetupFailed,
        public bool $needsAttention,
        public int $insightsOpen = 0,
        public ?string $insightsWorst = null,
        public ?string $insightsHref = null,
        public bool $canDelete = false,
        public bool $deployable = false,
        public int $deploySyncCount = 0,
        public ?string $deployAnchorSiteId = null,
        public array $sites = [],
        public array $services = [],
        public array $related = [],
        public ?array $provisioning = null,
        public ?string $journeyHref = null,
        /** @var array{state: string, label: string, detail: string|null}|null */
        public ?array $adopted = null,
    ) {}

    /**
     * @param  list<array{server: Server, reason: string}>  $related
     */
    public static function fromServer(
        Server $server,
        ?ServerMetricSnapshot $snapshot = null,
        int $insightsOpen = 0,
        ?string $insightsWorst = null,
        array $related = [],
        bool $deployable = false,
        bool $canDelete = false,
        int $deploySyncCount = 0,
        ?string $deployAnchorSiteId = null,
    ): self {
        return self::fromPayload(
            ServerIndexAssembler::toArray(
                $server,
                $snapshot,
                $insightsOpen,
                $insightsWorst,
                $related,
                $deployable,
                deploySyncCount: $deploySyncCount,
                deployAnchorSiteId: $deployAnchorSiteId,
            ),
            manageHref: route('servers.show', $server),
            manageExternal: false,
            workspaceHref: $server->workspace ? route('projects.resources', $server->workspace) : null,
            insightsHref: $insightsOpen > 0 ? route('servers.insights', $server) : null,
            journeyHref: route('servers.journey', $server),
            canDelete: $canDelete || $server->scheduled_deletion_at !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromProductionApi(
        array $row,
        string $remoteBaseUrl,
        ?string $defaultGroupLabel = null,
    ): self {
        $id = (string) ($row['id'] ?? '');

        // Thin/legacy list payloads omit fleet-card fields. Fill display defaults
        // so Ready hosts don't look mid-provision and groups use the connected org.
        if (! array_key_exists('group_label', $row) || $row['group_label'] === null || $row['group_label'] === '') {
            $row['group_label'] = $defaultGroupLabel ?: __('Organization');
        }
        if (! array_key_exists('setup_status', $row)) {
            $row['setup_status'] = ((string) ($row['status'] ?? '')) === Server::STATUS_READY
                ? Server::SETUP_STATUS_DONE
                : '';
        }
        if (! array_key_exists('health_status', $row)
            && ((string) ($row['status'] ?? '')) === Server::STATUS_READY
            && ((string) ($row['setup_status'] ?? '')) === Server::SETUP_STATUS_DONE
        ) {
            // Unknown reachability on a ready host — paint like reachable for
            // visual parity with the local fleet (green stripe + Ready).
            $row['health_status'] = Server::HEALTH_REACHABLE;
        }
        if (! array_key_exists('deployable', $row)) {
            $row['deployable'] = ((string) ($row['status'] ?? '')) === Server::STATUS_READY
                && ((string) ($row['setup_status'] ?? '')) === Server::SETUP_STATUS_DONE;
        }

        // Manage stays in this app: live.servers.show materializes the remote
        // host into a local mirror stub and opens the real workspace, so the
        // Production row is operable here instead of bouncing to the remote
        // control plane. Journey has no local mirror equivalent and stays remote.
        return self::fromPayload(
            $row,
            manageHref: route('live.servers.show', $id),
            manageExternal: false,
            workspaceHref: null,
            insightsHref: null,
            journeyHref: rtrim($remoteBaseUrl, '/').'/servers/'.$id.'/journey',
            canDelete: false,
            rewriteNestedHrefs: true,
            remoteBaseUrl: $remoteBaseUrl,
        );
    }

    /**
     * True when the remote list payload predates the fleet-card API
     * (missing metrics / nested sites / setup_status).
     *
     * @param  list<array<string, mixed>>  $apiRows
     */
    public static function isLegacyApiPayload(array $apiRows): bool
    {
        if ($apiRows === []) {
            return false;
        }

        $row = $apiRows[0];

        return ! array_key_exists('setup_status', $row)
            || ! array_key_exists('sites', $row)
            || ! array_key_exists('metrics', $row);
    }

    /**
     * Fill deploy_sync_count / deploy_anchor_site_id when the remote API omits
     * them (older fleet-card payloads). Prefers git URLs on nested sites; falls
     * back to local Site rows with the same ids (common when the local DB is a
     * prod dump and Production mirror still talks to a host without sync meta).
     *
     * @param  list<array<string, mixed>>  $apiRows
     * @return list<array<string, mixed>>
     */
    public static function enrichDeploySyncMeta(array $apiRows): array
    {
        if ($apiRows === []) {
            return [];
        }

        $repoCounts = [];
        $siteIds = [];
        foreach ($apiRows as $row) {
            foreach ($row['sites'] ?? [] as $site) {
                if (! is_array($site)) {
                    continue;
                }
                $siteId = isset($site['id']) ? (string) $site['id'] : '';
                if ($siteId !== '') {
                    $siteIds[] = $siteId;
                }
                $repo = SiteSyncPeers::canonicalRepo((string) ($site['git_repository_url'] ?? ''));
                if ($repo !== '') {
                    $repoCounts[$repo] = ($repoCounts[$repo] ?? 0) + 1;
                }
            }
        }

        $localSites = $siteIds !== []
            ? Site::query()->whereIn('id', array_values(array_unique($siteIds)))->with('server')->get()->keyBy(fn (Site $s): string => (string) $s->id)
            : collect();

        if ($repoCounts === [] && $localSites->isNotEmpty()) {
            foreach ($localSites as $site) {
                $repo = SiteSyncPeers::canonicalRepo((string) $site->git_repository_url);
                if ($repo !== '') {
                    $repoCounts[$repo] = ($repoCounts[$repo] ?? 0) + 1;
                }
            }
        }

        return array_map(function (array $row) use ($repoCounts, $localSites): array {
            $sites = is_array($row['sites'] ?? null) ? $row['sites'] : [];
            $anchorId = isset($row['deploy_anchor_site_id']) && is_string($row['deploy_anchor_site_id']) && $row['deploy_anchor_site_id'] !== ''
                ? $row['deploy_anchor_site_id']
                : (isset($sites[0]['id']) ? (string) $sites[0]['id'] : null);
            $existingCount = (int) ($row['deploy_sync_count'] ?? 0);
            if ($existingCount > 1 && filled($anchorId)) {
                $row['deploy_anchor_site_id'] = $anchorId;

                return $row;
            }

            $repo = '';
            if (isset($sites[0]['git_repository_url'])) {
                $repo = SiteSyncPeers::canonicalRepo((string) $sites[0]['git_repository_url']);
            }
            if ($repo === '' && $anchorId !== null && $localSites->has($anchorId)) {
                $repo = SiteSyncPeers::canonicalRepo((string) $localSites->get($anchorId)->git_repository_url);
            }

            $syncCount = $repo !== '' ? (int) ($repoCounts[$repo] ?? 0) : 0;
            if ($syncCount <= 1 && $anchorId !== null && $localSites->has($anchorId)) {
                $syncCount = SiteSyncPeers::forSite($localSites->get($anchorId))->count();
            }

            if ($syncCount > 1 && $anchorId !== null) {
                $row['deploy_sync_count'] = $syncCount;
                $row['deploy_anchor_site_id'] = $anchorId;
                if (! array_key_exists('deployable', $row) || ! $row['deployable']) {
                    $row['deployable'] = true;
                }
            }

            return $row;
        }, $apiRows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function fromPayload(
        array $row,
        string $manageHref,
        bool $manageExternal,
        ?string $workspaceHref,
        ?string $insightsHref,
        ?string $journeyHref,
        bool $canDelete,
        bool $rewriteNestedHrefs = false,
        string $remoteBaseUrl = '',
    ): self {
        $status = (string) ($row['status'] ?? '');
        // Older API clients omit setup_status — treat missing as done when ready
        // so every Ready host doesn't render as "provisioning".
        $setupStatus = array_key_exists('setup_status', $row) && $row['setup_status'] !== null && $row['setup_status'] !== ''
            ? (string) $row['setup_status']
            : ($status === Server::STATUS_READY ? Server::SETUP_STATUS_DONE : '');
        $healthStatus = isset($row['health_status']) && $row['health_status'] !== null && $row['health_status'] !== ''
            ? (string) $row['health_status']
            : null;
        $id = (string) ($row['id'] ?? '');
        $isFullyReady = $status === Server::STATUS_READY && $setupStatus === Server::SETUP_STATUS_DONE;
        $isSetupFailed = $setupStatus === Server::SETUP_STATUS_FAILED;
        $scheduledDeletion = isset($row['scheduled_deletion_at']) && is_string($row['scheduled_deletion_at']) && $row['scheduled_deletion_at'] !== ''
            ? Carbon::parse($row['scheduled_deletion_at'])
            : null;
        $needsAttention = $scheduledDeletion !== null
            || in_array($status, [Server::STATUS_ERROR, Server::STATUS_DISCONNECTED], true)
            || $isSetupFailed
            || ($isFullyReady && $healthStatus === Server::HEALTH_UNREACHABLE);

        $metrics = null;
        if (isset($row['metrics']) && is_array($row['metrics'])) {
            $metrics = [
                'cpu' => isset($row['metrics']['cpu']) ? (float) $row['metrics']['cpu'] : null,
                'ram' => isset($row['metrics']['ram']) ? (float) $row['metrics']['ram'] : null,
                'disk' => isset($row['metrics']['disk']) ? (float) $row['metrics']['disk'] : null,
                'captured_at' => isset($row['metrics']['captured_at']) ? (string) $row['metrics']['captured_at'] : null,
            ];
        }

        $tags = [];
        if (isset($row['tags']) && is_array($row['tags'])) {
            $tags = array_values(array_filter(array_map(
                static fn ($t): string => is_string($t) ? $t : '',
                $row['tags'],
            )));
        }

        $sites = self::listOfMaps($row['sites'] ?? null);
        $services = self::listOfMaps($row['services'] ?? null);
        $related = self::listOfMaps($row['related'] ?? null);
        // Nested cards point at the local mirror entries, matching manageHref.
        // These render with wire:navigate whenever manageExternal is false, so
        // they have to be app-relative — an absolute remote URL would break it.
        if ($rewriteNestedHrefs) {
            $sites = array_map(static function (array $site): array {
                $siteId = (string) ($site['id'] ?? '');
                if ($siteId !== '') {
                    $site['href'] = route('live.sites.show', $siteId);
                }

                return $site;
            }, $sites);
            $related = array_map(static function (array $peer): array {
                $peerId = (string) ($peer['id'] ?? '');
                if ($peerId !== '') {
                    $peer['href'] = route('live.servers.show', $peerId);
                }

                return $peer;
            }, $related);
        }

        $provisioning = null;
        if (isset($row['provisioning']) && is_array($row['provisioning'])) {
            $provisioning = [
                'phase_label' => (string) ($row['provisioning']['phase_label'] ?? ''),
                'step_label' => (string) ($row['provisioning']['step_label'] ?? ''),
                'step_index' => isset($row['provisioning']['step_index']) ? (int) $row['provisioning']['step_index'] : null,
                'step_total' => isset($row['provisioning']['step_total']) ? (int) $row['provisioning']['step_total'] : null,
                'elapsed_human' => isset($row['provisioning']['elapsed_human']) ? (string) $row['provisioning']['elapsed_human'] : null,
            ];
        }

        $provider = (string) ($row['provider'] ?? '');
        $workspaceName = isset($row['workspace_name']) ? (string) $row['workspace_name'] : null;
        $createdAt = isset($row['created_at']) && is_string($row['created_at']) && $row['created_at'] !== ''
            ? Carbon::parse($row['created_at'])
            : null;
        $uptimeDays = isset($row['uptime_days'])
            ? (int) $row['uptime_days']
            : ($createdAt !== null ? max(0, (int) $createdAt->diffInDays(now())) : null);

        return new self(
            id: $id,
            name: (string) ($row['name'] ?? $id),
            ipAddress: isset($row['ip_address']) && is_string($row['ip_address']) && $row['ip_address'] !== ''
                ? $row['ip_address']
                : null,
            status: $status,
            setupStatus: $setupStatus,
            healthStatus: $healthStatus,
            provider: $provider,
            providerLabel: (string) ($row['provider_label'] ?? self::providerLabel($provider)),
            logoUrl: isset($row['logo_url']) && is_string($row['logo_url']) ? $row['logo_url'] : null,
            sitesCount: (int) ($row['sites_count'] ?? count($sites)),
            workspaceName: $workspaceName,
            workspaceId: isset($row['workspace_id']) ? (string) $row['workspace_id'] : null,
            workspaceHref: $workspaceHref,
            groupLabel: (string) ($row['group_label'] ?? $workspaceName ?? __('Organization')),
            tags: $tags,
            scheduledDeletionAt: $scheduledDeletion,
            createdAt: $createdAt,
            uptimeDays: $uptimeDays,
            workerLabel: isset($row['worker_label']) ? (string) $row['worker_label'] : null,
            manageHref: $manageHref,
            manageExternal: $manageExternal,
            metrics: $metrics,
            stripeClass: self::stripeClass($status, $setupStatus, $healthStatus, $scheduledDeletion !== null, $isFullyReady, $isSetupFailed),
            statusTone: self::statusTone($status, $setupStatus, $healthStatus, $isFullyReady, $isSetupFailed),
            statusLabel: self::statusLabel($status, $setupStatus, $healthStatus, $isFullyReady, $isSetupFailed),
            isFullyReady: $isFullyReady,
            isSetupFailed: $isSetupFailed,
            needsAttention: $needsAttention,
            insightsOpen: (int) ($row['insights_open'] ?? 0),
            insightsWorst: isset($row['insights_worst']) ? (string) $row['insights_worst'] : null,
            insightsHref: $insightsHref,
            canDelete: $canDelete,
            deployable: (bool) ($row['deployable'] ?? false),
            deploySyncCount: (int) ($row['deploy_sync_count'] ?? 0),
            deployAnchorSiteId: isset($row['deploy_anchor_site_id']) && is_string($row['deploy_anchor_site_id']) && $row['deploy_anchor_site_id'] !== ''
                ? $row['deploy_anchor_site_id']
                : null,
            sites: $sites,
            services: $services,
            related: $related,
            provisioning: $provisioning,
            journeyHref: $journeyHref,
            adopted: isset($row['adopted']) && is_array($row['adopted']) ? $row['adopted'] : null,
        );
    }

    /**
     * @param  Collection<int, self>  $rows
     * @return Collection<string, Collection<int, self>>
     */
    public static function group(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (self $row): string => $row->groupLabel)
            ->sortKeys();
    }

    /**
     * @param  Collection<int, self>  $rows
     * @return array{total: int, ready: int, attention: int, sites: int}
     */
    public static function summarize(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'ready' => $rows->filter(fn (self $r): bool => $r->isFullyReady)->count(),
            'attention' => $rows->filter(fn (self $r): bool => $r->needsAttention)->count(),
            'sites' => (int) $rows->sum(fn (self $r): int => $r->sitesCount),
        ];
    }

    public function insightsBadgeClass(): string
    {
        return match ($this->insightsWorst) {
            'critical' => 'bg-red-600 text-white',
            'warning' => 'bg-amber-500 text-white',
            'info' => 'bg-slate-500 text-white',
            default => 'bg-brand-ink text-brand-cream',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function listOfMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    protected static function providerLabel(string $provider): string
    {
        return $provider !== '' ? str_replace('_', ' ', ucfirst($provider)) : '—';
    }

    protected static function stripeClass(
        string $status,
        string $setupStatus,
        ?string $healthStatus,
        bool $scheduledDeletion,
        bool $isFullyReady,
        bool $isSetupFailed,
    ): string {
        if ($scheduledDeletion) {
            return 'bg-orange-500';
        }
        if ($isSetupFailed) {
            return 'bg-red-500';
        }
        if ($isFullyReady) {
            return match ($healthStatus) {
                Server::HEALTH_REACHABLE => 'bg-emerald-500',
                Server::HEALTH_UNREACHABLE => 'bg-red-500',
                default => 'bg-amber-400',
            };
        }
        if ($status === Server::STATUS_ERROR) {
            return 'bg-red-500';
        }
        if (in_array($status, [Server::STATUS_PROVISIONING, Server::STATUS_PENDING, Server::STATUS_READY], true)) {
            return 'bg-amber-400';
        }

        return 'bg-brand-mist';
    }

    protected static function statusTone(
        string $status,
        string $setupStatus,
        ?string $healthStatus,
        bool $isFullyReady,
        bool $isSetupFailed,
    ): string {
        if ($isSetupFailed) {
            return 'danger';
        }
        if ($isFullyReady) {
            return match ($healthStatus) {
                Server::HEALTH_REACHABLE => 'success',
                Server::HEALTH_UNREACHABLE => 'danger',
                default => 'warning',
            };
        }
        if ($status === Server::STATUS_ERROR) {
            return 'danger';
        }

        return 'info';
    }

    protected static function statusLabel(
        string $status,
        string $setupStatus,
        ?string $healthStatus,
        bool $isFullyReady,
        bool $isSetupFailed,
    ): string {
        if ($isSetupFailed) {
            return __('Setup failed');
        }
        if ($isFullyReady) {
            return match ($healthStatus) {
                Server::HEALTH_UNREACHABLE => __('Unreachable'),
                default => __('Ready'),
            };
        }
        if ($status === Server::STATUS_READY && $setupStatus !== Server::SETUP_STATUS_DONE) {
            return 'provisioning';
        }

        return str_replace('_', ' ', $status !== '' ? $status : '—');
    }
}
