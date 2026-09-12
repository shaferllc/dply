<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Modules\RemoteCli\Services\WpCli;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\SchedulerWrapperScript;
use App\Services\Servers\ServerCronSynchronizer;
use App\Support\Servers\SchedulerRecipe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-click "Enable scheduler" from a Schedule page row. What the old modal
 * asked for is decided by the site's {@see SchedulerRecipe}; this does the SSH
 * work, and the page polls {@see cacheKey()} the way it polls Run now.
 *
 * Order matters: preflight (touches nothing), wrapper install, cron entry and
 * heartbeat, crontab sync — and only then WordPress's DISABLE_WP_CRON, so a
 * WordPress site is never left with neither.
 *
 * A scheduler-shaped entry the site already has is wrapped in place, not
 * duplicated: that is "Enable monitoring" on a detected, unmonitored line.
 */
class EnableSchedulerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A Drupal enable may `composer require drush/drush` first. */
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public string $serverId,
        public string $siteId,
        public string $runId,
        public ?string $customCommand = null,
        public ?string $userId = null,
    ) {}

    public static function cacheKey(string $runId): string
    {
        return 'scheduler_enable:'.$runId;
    }

    public function handle(
        PreflightSchedulerOnSite $preflight,
        ExecuteRemoteTaskOnServer $remote,
        SchedulerWrapperScript $wrapper,
        ServerCronSynchronizer $crontab,
        WpCli $wpcli,
    ): void {
        $this->store('running', '');

        $server = Server::find($this->serverId);
        $site = Site::query()->where('server_id', $this->serverId)->whereKey($this->siteId)->first();
        if ($server === null || $site === null) {
            $this->store('failed', 'Site or server not found.');

            return;
        }

        $existing = SchedulerCardsBuilder::schedulerEntryFor($site);
        $custom = trim((string) $this->customCommand);
        $recipe = $custom !== '' ? SchedulerRecipe::custom($site, $custom) : SchedulerRecipe::for($site);
        if ($existing === null && $recipe === null) {
            $this->store('failed', 'No scheduler detected for this stack — enter the command to run.');

            return;
        }

        $kind = $existing !== null
            ? (string) SchedulerCardsBuilder::kindForCommand((string) $existing->command)
            : $recipe->kind;

        // The heartbeat is unique per site and kind; say so rather than let
        // the insert throw.
        $alreadyMonitored = ServerSchedulerHeartbeat::query()
            ->where('server_id', $server->id)
            ->where('site_id', $site->id)
            ->where('scheduler_kind', $kind)
            ->exists();
        if ($alreadyMonitored) {
            $this->store('failed', 'This site already has a monitored scheduler.');

            return;
        }

        $checks = $preflight->run($server, $site, $kind);
        if ($checks === []) {
            $this->store('failed', 'Preflight could not run over SSH.');

            return;
        }
        if ($preflight->structuralFailures($checks, $kind) !== []) {
            $this->store('failed', 'Preflight blocked enabling — fix the failed checks and retry.', $checks);

            return;
        }

        // drupal/recommended-project doesn't ship drush; require it once, in place.
        if ($existing === null && $recipe->key === SchedulerRecipe::KEY_DRUPAL) {
            $missing = $this->requireDrush($remote, $server, $site, $recipe->user ?: (string) $server->ssh_user);
            if ($missing !== null) {
                $this->store('failed', $missing, $checks);

                return;
            }
        }

        // Nothing upgrades the wrapper after provision, and older servers
        // predate it: a wrapped line with no wrapper fails every minute.
        try {
            $install = $remote->runInlineBash($server, 'scheduler-wrapper-install', $wrapper->installBashFragment(self::deployUser()), 120, false);
        } catch (Throwable $e) {
            $this->store('failed', 'Could not install the scheduler wrapper: '.$e->getMessage(), $checks);

            return;
        }
        if ($install->getExitCode() !== 0) {
            $this->store('failed', 'Could not install the scheduler wrapper: '.trim((string) $install->getBuffer()), $checks);

            return;
        }

        $bare = $existing !== null ? SchedulerWrapperScript::unwrap((string) $existing->command) : $recipe->command;
        $command = SchedulerWrapperScript::wrap($site->id, $kind, $bare);

        if ($existing !== null) {
            $existing->update(['command' => $command, 'enabled' => true, 'is_synced' => false]);
            $cronExpression = (string) $existing->cron_expression;
        } else {
            ServerCronJob::query()->create([
                'server_id' => $server->id,
                'site_id' => $site->id,
                'cron_expression' => $recipe->cronExpression,
                'command' => $command,
                'user' => $recipe->user,
                'enabled' => true,
                'overlap_policy' => $recipe->overlapPolicy,
                'description' => $recipe->label.' — '.$site->name.' (wrapper-managed)',
                'is_synced' => false,
            ]);
            $cronExpression = $recipe->cronExpression;
        }

        // Waiting for its first tick, so the row flips to "Waiting" at once.
        ServerSchedulerHeartbeat::query()->create([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => $kind,
            'cron_expression' => $cronExpression,
            'last_tick_at' => null,
            'consecutive_misses' => 0,
            'first_seen_at' => now(),
            'circuit_open' => false,
            'output_capture_enabled' => true,
        ]);

        // The legacy per-site flag writes its own bare schedule:run line, which
        // would run alongside this one.
        if ($site->laravel_scheduler) {
            $site->forceFill(['laravel_scheduler' => false])->save();
        }

        // sync() reports a rejected crontab in its output rather than throwing.
        try {
            $synced = preg_match('/DPLY_CRON_EXIT:0\s*$/', $crontab->sync($server->fresh())) === 1;
        } catch (Throwable $e) {
            Log::warning('scheduler.enable.sync_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $synced = false;
        }
        if (! $synced) {
            $this->store('failed', 'Saved, but pushing the crontab failed — retry the sync from the Cron page.', $checks);

            return;
        }

        // Only once the entry is live does WordPress stop its HTTP wp-cron.
        if ($site->isWordPressDetected() && str_contains($bare, 'cron event run')) {
            try {
                $wpcli->run($site, 'config set', ['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'], $this->user());
            } catch (Throwable $e) {
                $this->store('done', 'Scheduler enabled, but wp-cron is still on: '.$e->getMessage());

                return;
            }

            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['wp_cron'] = array_merge(
                is_array($meta['wp_cron'] ?? null) ? $meta['wp_cron'] : [],
                ['handler' => 'system_cron', 'switched_at' => now()->toISOString(), 'error' => null],
            );
            $site->forceFill(['meta' => $meta])->save();
        }

        $this->store('done', 'Scheduler enabled — waiting for the first tick.');
    }

    public function failed(?Throwable $exception): void
    {
        $this->store('failed', $exception?->getMessage() ?? 'Enabling the scheduler failed.');
    }

    /**
     * Run as the site user, like every other composer call on the box.
     *
     * @return string|null why drush is still missing, or null once it's there
     */
    private function requireDrush(ExecuteRemoteTaskOnServer $remote, Server $server, Site $site, string $user): ?string
    {
        $dir = escapeshellarg(rtrim($site->effectiveEnvDirectory(), '/'));
        $inner = "cd {$dir} && { [ -x vendor/bin/drush ] || composer require drush/drush --no-interaction --no-audit 2>&1; } && [ -x vendor/bin/drush ]";

        try {
            $out = $remote->runInlineBash($server, 'scheduler-drush-require', 'sudo -u '.escapeshellarg($user).' -H bash -lc '.escapeshellarg($inner), 600, false);
            if ($out->getExitCode() === 0) {
                return null;
            }
            $detail = trim(mb_substr((string) $out->getBuffer(), -500));
        } catch (Throwable $e) {
            $detail = $e->getMessage();
        }

        return 'Drush is not installed, and `composer require drush/drush` failed — run it in the site, then retry. '.$detail;
    }

    private function user(): ?User
    {
        return $this->userId !== null ? User::query()->find($this->userId) : null;
    }

    /** The wrapper's data directories are owned by the deploy user. */
    public static function deployUser(): string
    {
        $user = (string) config('server_provision.deploy_ssh_user', 'dply');

        return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user) === 1 && $user !== 'root' ? $user : 'dply';
    }

    /**
     * @param  list<array{key: string, status: string, message: string}>  $checks
     */
    private function store(string $status, string $output, array $checks = []): void
    {
        Cache::put(self::cacheKey($this->runId), [
            'status' => $status,
            'output' => $output,
            'site_id' => $this->siteId,
            'checks' => $checks,
        ], now()->addMinutes(10));
    }
}
