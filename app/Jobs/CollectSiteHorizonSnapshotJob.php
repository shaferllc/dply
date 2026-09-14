<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The site-level counterpart to {@see CollectWorkerPoolHorizonSnapshotJob}: the
 * same on-box script, run in this site's own app directory, stored on
 * `sites.meta.horizon` for the Queue page.
 *
 * Queued because it is an SSH round trip through tinker — the page polls the
 * meta rather than holding a request open.
 */
class CollectSiteHorizonSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $siteId)
    {
        // The open page polls this every 30s: not behind the five-minute sweeps
        // on dply-control, which refresh the same data for closed pages.
        $this->onQueue(config('dply.queues.interactive', 'dply'));
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);

        if (! $site instanceof Site || $site->server === null) {
            return;
        }

        $dir = rtrim($site->effectiveEnvDirectory(), '/');

        try {
            $out = $exec->runInlineBash(
                $site->server,
                'site:horizon-snapshot',
                CollectWorkerPoolHorizonSnapshotJob::script($dir),
                timeoutSeconds: 60,
                asRoot: false,
            );
            $buffer = (string) $out->buffer;
        } catch (\Throwable $e) {
            Log::info('site: horizon snapshot failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            self::write($site, ['error' => 'SSH/exec failed: '.$e->getMessage()], keepLast: true);

            return;
        }

        self::record($site, $buffer);
    }

    /**
     * Store what one run of the Horizon script printed. Public so the
     * five-minute queue sweep, which runs the same script in its own SSH
     * session, keeps this fresh while nobody has the page open.
     */
    public static function record(Site $site, string $buffer): void
    {
        $snapshot = CollectWorkerPoolHorizonSnapshotJob::extract($buffer);

        if ($snapshot === null) {
            $tail = trim(mb_substr($buffer, -300));
            self::write($site, ['error' => 'No snapshot returned from the box. Output tail: '.($tail !== '' ? $tail : '(empty)')], keepLast: true);

            return;
        }

        self::write($site, $snapshot + ['collected_at' => now()->toIso8601String(), 'error' => null], keepLast: false);
    }

    /**
     * A failed pull keeps the last good snapshot and only records why, so the
     * page can say "last refresh failed" without blanking what it showed.
     * Re-read first: the SSH round trip is long enough for other meta to change.
     *
     * @param  array<string, mixed>  $values
     */
    private static function write(Site $site, array $values, bool $keepLast): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $current = $keepLast && is_array($meta['horizon'] ?? null) ? $meta['horizon'] : [];

        $values['last_attempt_at'] = now()->toIso8601String();

        if (is_string($values['error'] ?? null)) {
            $values['error'] = mb_substr($values['error'], 0, 600);
        }

        $site->putMeta('horizon', array_merge($current, $values));
    }
}
