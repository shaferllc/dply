<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Notifications\Services\ServerMaintenanceNotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Enable/disable a server-wide maintenance window by suspending eligible VM
 * sites with a shared public message, then re-applying webserver config.
 */
final class ServerMaintenanceWindow
{
    public const REASON = 'server_maintenance';

    public function isActive(Server $server): bool
    {
        $state = $this->state($server);
        if ($state === null || ! ($state['active'])) {
            return false;
        }

        $until = $this->parseUntil($state['until'] ?? null);
        if ($until !== null && now()->gte($until)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     active: bool,
     *     started_at: ?string,
     *     until: ?string,
     *     note: ?string,
     *     message: ?string,
     *     suspended_site_ids: list<string>,
     * }|null
     */
    public function state(Server $server): ?array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $key = (string) config('server_maintenance.meta_key', 'maintenance');
        $state = $meta[$key] ?? null;

        return is_array($state) ? $state : null;
    }

    /**
     * @return Collection<int, Site>
     */
    public function eligibleSites(Server $server): Collection
    {
        $server->loadMissing('sites.server');

        return $server->sites
            ->filter(fn (Site $site): bool => $this->siteEligible($site))
            ->values();
    }

    /**
     * @return array{suspend_count: int, already_suspended: int, skipped: int}
     */
    /** @return array<string, mixed> */
    public function preview(Server $server): array
    {
        $eligible = $this->eligibleSites($server);
        $already = $eligible->filter(fn (Site $site): bool => $site->isSuspended())->count();

        return [
            'suspend_count' => $eligible->count() - $already,
            'already_suspended' => $already,
            'skipped' => max(0, $server->sites->count() - $eligible->count()),
        ];
    }

    /**
     * Rich maintenance workspace report: visitor window state, site impact rows,
     * and eligibility breakdown for the server maintenance page.
     *
     * @return array{
     *     overall: string,
     *     active: bool,
     *     summary: array{
     *         total_sites: int,
     *         eligible: int,
     *         would_suspend: int,
     *         already_suspended: int,
     *         skipped: int,
     *         suspended_by_window: int,
     *         live_eligible: int,
     *     },
     *     state: array<string, mixed>|null,
     *     preview: array{suspend_count: int, already_suspended: int, skipped: int},
     *     site_rows: list<array{
     *         id: string,
     *         name: string,
     *         primary_hostname: string,
     *         status: string,
     *         status_label: string,
     *         detail: ?string,
     *         show_url: string,
     *     }>,
     * }
     */
    /** @return array<string, mixed> */
    public function report(Server $server): array
    {
        $server->loadMissing(['sites.server']);
        $active = $this->isActive($server);
        $state = $this->state($server);
        $preview = $this->preview($server);
        $eligible = $this->eligibleSites($server);
        $eligibleIds = $eligible->pluck('id')->map(fn ($id): string => (string) $id)->all();

        /** @var array $windowSuspendedIds */
        $windowSuspendedIds = is_array($state['suspended_site_ids'] ?? null)
            ? array_map('strval', $state['suspended_site_ids'])
            : [];

        $suspendedByWindow = 0;
        $siteRows = [];

        foreach ($server->sites->sortBy('name') as $site) {
            $siteId = (string) $site->id;
            $inEligible = in_array($siteId, $eligibleIds, true);
            $primaryHostname = $site->primaryDomain()?->hostname ?: $site->name;

            if (! $inEligible) {
                $siteRows[] = [
                    'id' => $siteId,
                    'name' => $site->name,
                    'primary_hostname' => $primaryHostname,
                    'status' => 'excluded',
                    'status_label' => __('Excluded'),
                    'detail' => $this->siteIneligibleReason($site),
                    'show_url' => route('sites.show', ['server' => $server, 'site' => $site]),
                ];

                continue;
            }

            if ($site->isSuspended()) {
                $reason = (string) ($site->suspended_reason ?? '');

                if ($reason === self::REASON) {
                    $suspendedByWindow++;
                    $siteRows[] = [
                        'id' => $siteId,
                        'name' => $site->name,
                        'primary_hostname' => $primaryHostname,
                        'status' => 'suspended_window',
                        'status_label' => __('Suspended (maintenance)'),
                        'detail' => trim((string) ($site->meta['suspended_message'] ?? '')) ?: null,
                        'show_url' => route('sites.show', ['server' => $server, 'site' => $site]),
                    ];

                    continue;
                }

                $siteRows[] = [
                    'id' => $siteId,
                    'name' => $site->name,
                    'primary_hostname' => $primaryHostname,
                    'status' => 'suspended_other',
                    'status_label' => __('Already suspended'),
                    'detail' => $reason !== '' ? $reason : __('Suspended before maintenance started'),
                    'show_url' => route('sites.show', ['server' => $server, 'site' => $site]),
                ];

                continue;
            }

            $siteRows[] = [
                'id' => $siteId,
                'name' => $site->name,
                'primary_hostname' => $primaryHostname,
                'status' => $active ? 'live' : 'ready',
                'status_label' => $active ? __('Live traffic') : __('Would suspend'),
                'detail' => null,
                'show_url' => route('sites.show', ['server' => $server, 'site' => $site]),
            ];
        }

        $wouldSuspend = $preview['suspend_count'];
        $alreadySuspended = $preview['already_suspended'];
        $liveEligible = max(0, $eligible->count() - $alreadySuspended - ($active ? $suspendedByWindow : 0));

        return [
            'overall' => $active ? 'active' : 'inactive',
            'active' => $active,
            'summary' => [
                'total_sites' => $server->sites->count(),
                'eligible' => $eligible->count(),
                'would_suspend' => $wouldSuspend,
                'already_suspended' => $alreadySuspended,
                'skipped' => $preview['skipped'],
                'suspended_by_window' => $suspendedByWindow,
                'live_eligible' => $liveEligible,
            ],
            'state' => $state,
            'preview' => $preview,
            'site_rows' => $siteRows,
        ];
    }

    /**
     * @return array{suspended: int, already_suspended: int}
     */
    public function enable(
        Server $server,
        ?CarbonInterface $until,
        string $note,
        string $message,
        ?User $user = null,
    ): array {
        if ($this->isActive($server)) {
            throw new \RuntimeException(__('A maintenance window is already active on this server.'));
        }

        $eligible = $this->eligibleSites($server);
        $suspendedIds = [];
        $alreadySuspended = 0;
        $publicMessage = trim($message);

        foreach ($eligible as $site) {
            if ($site->isSuspended()) {
                $alreadySuspended++;

                continue;
            }

            $siteMeta = ($site->meta );
            if ($publicMessage !== '') {
                $siteMeta['suspended_message'] = $publicMessage;
            } else {
                unset($siteMeta['suspended_message']);
            }

            $site->update([
                'suspended_at' => now(),
                'suspended_reason' => self::REASON,
                'meta' => $siteMeta,
            ]);

            ApplySiteWebserverConfigJob::dispatch($site->id);
            $suspendedIds[] = (string) $site->id;

            $org = $server->organization;
            if ($org !== null) {
                audit_log($org, $user, 'site.suspended', $site, null, [
                    'reason' => self::REASON,
                    'server_maintenance' => true,
                ]);
            }
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $key = (string) config('server_maintenance.meta_key', 'maintenance');
        $meta[$key] = [
            'active' => true,
            'started_at' => now()->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'note' => trim($note) !== '' ? trim($note) : null,
            'message' => $publicMessage !== '' ? $publicMessage : null,
            'suspended_site_ids' => $suspendedIds,
        ];
        $server->update(['meta' => $meta]);

        $org = $server->organization;
        if ($org !== null) {
            audit_log($org, $user, 'server.maintenance.enabled', $server, null, [
                'until' => $until?->toIso8601String(),
                'suspended_count' => count($suspendedIds),
                'already_suspended' => $alreadySuspended,
            ]);
        }

        app(ServerMaintenanceNotificationDispatcher::class)->notify(
            $server,
            'enabled',
            [
                trans_choice(':count site suspended.|:count sites suspended.', count($suspendedIds), ['count' => count($suspendedIds)]),
                $until !== null
                    ? __('Ends automatically at :time', ['time' => $until->toIso8601String()])
                    : __('Manual clear only — no scheduled end.'),
                $publicMessage !== '' ? __('Public message: :message', ['message' => $publicMessage]) : '',
                trim($note) !== '' ? __('Operator note: :note', ['note' => trim($note)]) : '',
            ],
            $user,
            [
                'until' => $until?->toIso8601String(),
                'suspended_count' => count($suspendedIds),
            ],
        );

        return [
            'suspended' => count($suspendedIds),
            'already_suspended' => $alreadySuspended,
        ];
    }

    /**
     * @return array{resumed: int, left_suspended: int}
     */
    /** @return array<string, mixed> */
    public function disable(Server $server, ?User $user = null, bool $autoExpired = false): array
    {
        $state = $this->state($server);
        if ($state === null || ! ($state['active'])) {
            throw new \RuntimeException(__('No active maintenance window on this server.'));
        }

        /** @var array $siteIds */
        $siteIds = ($state['suspended_site_ids'] );
        $resumed = 0;
        $leftSuspended = 0;

        if ($siteIds !== []) {
            $sites = Site::query()->whereIn('id', $siteIds)->get();
            foreach ($sites as $site) {
                if (! $site->isSuspended()) {
                    continue;
                }

                if ((string) ($site->suspended_reason ?? '') !== self::REASON) {
                    $leftSuspended++;

                    continue;
                }

                $siteMeta = ($site->meta );
                unset($siteMeta['suspended_message']);

                $site->update([
                    'suspended_at' => null,
                    'suspended_reason' => null,
                    'meta' => $siteMeta,
                ]);

                ApplySiteWebserverConfigJob::dispatch($site->id);
                $resumed++;

                $org = $server->organization;
                if ($org !== null) {
                    audit_log($org, $user, 'site.resumed', $site, null, [
                        'reason' => self::REASON,
                        'server_maintenance' => true,
                    ]);
                }
            }
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $key = (string) config('server_maintenance.meta_key', 'maintenance');
        unset($meta[$key]);
        $server->update(['meta' => $meta]);

        $org = $server->organization;
        if ($org !== null) {
            audit_log($org, $user, 'server.maintenance.disabled', $server, null, [
                'resumed_count' => $resumed,
                'left_suspended' => $leftSuspended,
                'auto_expired' => $autoExpired,
            ]);
        }

        app(ServerMaintenanceNotificationDispatcher::class)->notify(
            $server,
            $autoExpired ? 'auto_expired' : 'disabled',
            [
                trans_choice(':count site resumed.|:count sites resumed.', $resumed, ['count' => $resumed]),
                $leftSuspended > 0
                    ? trans_choice(':count site left suspended (manually suspended — unchanged).|:count sites left suspended (manually suspended — unchanged).', $leftSuspended, ['count' => $leftSuspended])
                    : '',
            ],
            $user,
            [
                'resumed_count' => $resumed,
                'left_suspended' => $leftSuspended,
            ],
        );

        return [
            'resumed' => $resumed,
            'left_suspended' => $leftSuspended,
        ];
    }

    /**
     * Auto-clear expired windows (until in the past).
     */
    public function refreshExpired(Server $server, ?User $user = null): bool
    {
        $state = $this->state($server);
        if ($state === null || ! ($state['active'])) {
            return false;
        }

        $until = $this->parseUntil($state['until'] ?? null);
        if ($until === null || now()->lt($until)) {
            return false;
        }

        $this->disable($server, $user, autoExpired: true);

        return true;
    }

    public function siteEligible(Site $site): bool
    {
        $server = $site->server;
        if ($server === null) {
            return false;
        }

        if (! $server->isVmHost()) {
            return false;
        }

        return $server->hostCapabilities()->supportsWebserverProvisioning()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();
    }

    private function siteIneligibleReason(Site $site): string
    {
        if ($site->usesDockerRuntime()) {
            return __('Docker runtime — not managed by VM webserver suspend');
        }

        if ($site->usesKubernetesRuntime()) {
            return __('Kubernetes runtime — not managed by VM webserver suspend');
        }

        if ($site->usesFunctionsRuntime()) {
            return __('Serverless runtime — not managed by VM webserver suspend');
        }

        $server = $site->server;
        if ($server !== null && ! $server->hostCapabilities()->supportsWebserverProvisioning()) {
            return __('Managed webserver config not available on this host');
        }

        return __('Not eligible for server maintenance suspend');
    }

    private function parseUntil(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
