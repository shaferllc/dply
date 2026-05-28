<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
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
        if ($state === null || ! ($state['active'] ?? false)) {
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

            $siteMeta = is_array($site->meta) ? $site->meta : [];
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

        return [
            'suspended' => count($suspendedIds),
            'already_suspended' => $alreadySuspended,
        ];
    }

    /**
     * @return array{resumed: int, left_suspended: int}
     */
    public function disable(Server $server, ?User $user = null): array
    {
        $state = $this->state($server);
        if ($state === null || ! ($state['active'] ?? false)) {
            throw new \RuntimeException(__('No active maintenance window on this server.'));
        }

        /** @var list<string> $siteIds */
        $siteIds = is_array($state['suspended_site_ids'] ?? null) ? $state['suspended_site_ids'] : [];
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

                $siteMeta = is_array($site->meta) ? $site->meta : [];
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
            ]);
        }

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
        if ($state === null || ! ($state['active'] ?? false)) {
            return false;
        }

        $until = $this->parseUntil($state['until'] ?? null);
        if ($until === null || now()->lt($until)) {
            return false;
        }

        $this->disable($server, $user);

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
