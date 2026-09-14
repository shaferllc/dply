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
        $this->onQueue('dply-control');
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
            $snapshot = CollectWorkerPoolHorizonSnapshotJob::extract($buffer);
        } catch (\Throwable $e) {
            Log::info('site: horizon snapshot failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $this->write($site, ['error' => 'SSH/exec failed: '.$e->getMessage()], keepLast: true);

            return;
        }

        if ($snapshot === null) {
            $tail = trim(mb_substr($buffer, -300));
            $this->write($site, ['error' => 'No snapshot returned from the box. Output tail: '.($tail !== '' ? $tail : '(empty)')], keepLast: true);

            return;
        }

        $this->write($site, $snapshot + ['collected_at' => now()->toIso8601String(), 'error' => null], keepLast: false);
    }

    /**
     * A failed pull keeps the last good snapshot and only records why, so the
     * page can say "last refresh failed" without blanking what it showed.
     * Re-read first: the SSH round trip is long enough for other meta to change.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(Site $site, array $values, bool $keepLast): void
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
