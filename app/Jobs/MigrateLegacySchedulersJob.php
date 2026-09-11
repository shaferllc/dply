<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Modules\WordPress\Jobs\SwitchWordPressCronHandlerJob;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves one server's schedulers onto the one mechanism: a wrapped cron entry
 * plus a heartbeat, the same thing the Schedule page's Enable builds.
 *
 *  - Wrapped lines from before the `sh -c` fix ran only their `cd` inside the
 *    wrapper; they are re-quoted in place.
 *  - Sites with the legacy `laravel_scheduler` flag, and WordPress sites with
 *    the old bare system-cron entry, go through {@see EnableSchedulerJob}.
 *
 * Safe to re-run: a site that already has a heartbeat only loses its flag. A
 * site whose enable fails keeps the flag, so the synchronizer keeps writing its
 * bare line — nothing stops running.
 */
class MigrateLegacySchedulersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $serverId) {}

    public function handle(ServerCronSynchronizer $crontab, ExecuteRemoteTaskOnServer $remote, SchedulerWrapperScript $wrapper): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }

        $needsSync = false;
        $wrapped = ServerCronJob::query()
            ->where('server_id', $server->id)
            ->whereNotNull('site_id')
            ->where('command', 'like', SchedulerWrapperScript::REMOTE_PATH.' %')
            ->get();

        // Old-format lines predate the current wrapper, and nothing upgrades it
        // after provision. A line that only runs its `cd` beats one pointing at
        // a wrapper that can't run it, so no install means no re-quote.
        if ($wrapped->isNotEmpty() && ! $this->installWrapper($remote, $wrapper, $server)) {
            $wrapped = collect();
        }

        foreach ($wrapped as $job) {
            $kind = SchedulerWrapperScript::wrappedKind((string) $job->command);
            $requoted = $kind !== null
                ? SchedulerWrapperScript::wrap((string) $job->site_id, $kind, SchedulerWrapperScript::unwrap((string) $job->command))
                : (string) $job->command;
            if ($requoted !== $job->command) {
                $job->update(['command' => $requoted, 'is_synced' => false]);
                $needsSync = true;
            }
        }

        $siteIds = Site::query()->where('server_id', $server->id)->where('laravel_scheduler', true)->pluck('id')
            ->merge(ServerCronJob::query()->where('server_id', $server->id)->where('description', SwitchWordPressCronHandlerJob::DESCRIPTION)->pluck('site_id'))
            ->filter()
            ->unique();

        foreach ($siteIds as $siteId) {
            if (ServerSchedulerHeartbeat::query()->where('site_id', $siteId)->exists()) {
                Site::query()->whereKey($siteId)->update(['laravel_scheduler' => false]);
                $needsSync = true;

                continue;
            }

            // EnableSchedulerJob syncs the crontab itself on success.
            $runId = (string) Str::uuid();
            app()->call([new EnableSchedulerJob((string) $server->id, (string) $siteId, $runId), 'handle']);
            $result = Cache::pull(EnableSchedulerJob::cacheKey($runId));
            if (($result['status'] ?? null) !== 'done') {
                Log::warning('scheduler.legacy_migration.skipped', [
                    'server_id' => $server->id,
                    'site_id' => $siteId,
                    'reason' => $result['output'] ?? null,
                ]);
            }
        }

        if ($needsSync) {
            $crontab->sync($server->fresh());
        }
    }

    private function installWrapper(ExecuteRemoteTaskOnServer $remote, SchedulerWrapperScript $wrapper, Server $server): bool
    {
        try {
            $out = $remote->runInlineBash($server, 'scheduler-wrapper-install', $wrapper->installBashFragment(EnableSchedulerJob::deployUser()), 120, false);
            if ($out->getExitCode() === 0) {
                return true;
            }
            $error = trim((string) $out->getBuffer());
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        Log::warning('scheduler.legacy_migration.wrapper_install_failed', ['server_id' => $server->id, 'error' => $error]);

        return false;
    }
}
