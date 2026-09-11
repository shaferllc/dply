<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Jobs;

use App\Jobs\EnableSchedulerJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Models\User;
use App\Modules\RemoteCli\Services\WpCli;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\ServerCronSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Switch a WordPress site between HTTP wp-cron and a real system crontab.
 *
 * "System cron" is the Schedule page's WordPress scheduler: a wrapped, monitored
 * cron entry, installed by {@see EnableSchedulerJob}. The two halves are ordered
 * so there is never a moment with neither: entry first, then disable wp-cron; on
 * the way back, re-enable wp-cron first, then remove the entry.
 */
final class SwitchWordPressCronHandlerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bare entries from before the one-click enable; MigrateLegacySchedulersJob wraps them. */
    public const DESCRIPTION = 'dply: WordPress system cron';

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** @param  'system'|'wp-cron'  $to */
    public function __construct(
        public string $siteId,
        public string $to,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'wp-cron-switch:'.$this->siteId;
    }

    /**
     * The crontab line's command: the exact wp-cli invocation dply runs for
     * every other wp command, with a PATH that finds /usr/local/bin/wp — cron's
     * default PATH does not.
     */
    public static function command(Site $site): string
    {
        return 'PATH=/usr/local/bin:/usr/bin:/bin '.app(WpCli::class)->buildShellForRun($site, 'cron event run', ['--due-now', '--quiet']);
    }

    public function handle(ServerCronSynchronizer $crontab, WpCli $wpcli): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $server = $site?->server;
        if ($site === null || $server === null) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        try {
            if ($this->to === 'system') {
                $runId = (string) Str::uuid();
                app()->call([new EnableSchedulerJob((string) $server->id, (string) $site->id, $runId, null, $this->userId), 'handle']);
                $result = Cache::pull(EnableSchedulerJob::cacheKey($runId));
                if (($result['status'] ?? null) !== 'done' || data_get($site->fresh()?->meta, 'wp_cron.handler') !== 'system_cron') {
                    throw new RuntimeException((string) ($result['output'] ?? 'Enabling system cron failed.'));
                }
            } else {
                $wpcli->run($site, 'config delete', ['DISABLE_WP_CRON', '--type=constant'], $user);

                // Disable, sync, then delete: sync() returns early when a server
                // has no jobs left, which would leave the old line in place.
                $entry = SchedulerCardsBuilder::schedulerEntryFor($site);
                if ($entry !== null) {
                    $entry->update(['enabled' => false, 'is_synced' => false]);
                    $crontab->sync($server->fresh());
                    $entry->delete();
                }
                ServerSchedulerHeartbeat::query()->where('site_id', $site->id)->delete();
            }

            $this->recordOutcome($site, handler: $this->to === 'system' ? 'system_cron' : 'wp_cron', error: null);
        } catch (Throwable $e) {
            Log::warning('WordPress cron switch failed', ['site_id' => $site->id, 'to' => $this->to, 'error' => $e->getMessage()]);
            $this->recordOutcome($site, handler: null, error: $e->getMessage());
        }
    }

    private function recordOutcome(Site $site, ?string $handler, ?string $error): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $state = is_array($meta['wp_cron'] ?? null) ? $meta['wp_cron'] : [];
        unset($state['switching_to']);

        if ($handler !== null) {
            $state['handler'] = $handler;
            $state['switched_at'] = now()->toISOString();
        }
        $state['error'] = $error !== null ? mb_substr($error, 0, 500) : null;

        $meta['wp_cron'] = $state;
        $site->meta = $meta;
        $site->save();
    }
}
