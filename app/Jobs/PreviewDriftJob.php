<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Run authorized_keys drift preview against a server in the background, mirroring the
 * pattern used by {@see SyncAuthorizedKeysJob} so the workspace banner can show the same
 * "click → spinner → streaming output → green box" UX for drift that it does for sync.
 *
 * Run state on `server.meta` (run_id / status / started_at / finished_at / error). The cache
 * payload at `<prefix><run_id>` carries BOTH the streaming `lines` array AND the structured
 * `diff_result` — sync only had `lines` because the result was implicit (file written), but
 * drift's payload is the diff itself, so we ship it back to the workspace via the cache.
 */
class PreviewDriftJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Soft cap on lines kept in the cache buffer; the UI shows the tail. */
    public const MAX_BUFFER_LINES = 500;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public string $runId,
    ) {}

    public function handle(ServerAuthorizedKeysDiffPreview $diff): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $statusKey = config('server_ssh_keys.meta_drift_status_key');
        $finishedKey = config('server_ssh_keys.meta_drift_finished_at_key');
        $errorKey = config('server_ssh_keys.meta_drift_error_key');
        $hasChangesKey = config('server_ssh_keys.meta_drift_has_changes_key');
        $addedCountKey = config('server_ssh_keys.meta_drift_added_count_key');
        $removedCountKey = config('server_ssh_keys.meta_drift_removed_count_key');

        // Clear any prior outcome so a stale "No drift" summary can't show next
        // to a fresh run that hasn't computed its result yet.
        $this->updateMeta($server, [
            $statusKey => 'running',
            $hasChangesKey => null,
            $addedCountKey => null,
            $removedCountKey => null,
        ]);

        $bufferLines = [];
        $cacheKey = $this->cacheKey();
        $ttl = (int) config('server_ssh_keys.sync_output_cache_ttl_seconds', 300);
        $lastFlush = 0.0;
        $flushIntervalSec = 0.4;

        $flush = function (?array $diffResult = null, bool $force = false) use (&$bufferLines, &$lastFlush, $flushIntervalSec, $cacheKey, $ttl): void {
            $now = microtime(true);
            if (! $force && ($now - $lastFlush) < $flushIntervalSec) {
                return;
            }
            $lastFlush = $now;
            $payload = [
                'lines' => $bufferLines,
                'updated_at' => now()->timestamp,
            ];
            if ($diffResult !== null) {
                $payload['diff_result'] = $diffResult;
            }
            Cache::put($cacheKey, $payload, $ttl);
        };

        // Seed with a "Connecting…" line so the banner shows something the moment the worker
        // picks up the job, even before the diff service emits its first chunk.
        $bufferLines[] = '> Connecting to '.$server->getSshConnectionString().' …';
        $flush(null, true);

        $callback = function (string $type, string $line) use (&$bufferLines, $flush): void {
            if ($line === '') {
                return;
            }
            $bufferLines[] = $line;
            if (count($bufferLines) > self::MAX_BUFFER_LINES) {
                $bufferLines = array_slice($bufferLines, -self::MAX_BUFFER_LINES);
            }
            $flush();
        };

        try {
            $result = $diff->withOutputCallback($callback)
                ->diffPerUser($server->fresh(['authorizedKeys']));

            $bufferLines[] = '> Done. Diff computed.';
            $flush($result, true);

            // Summarize the outcome (root is auto-managed and hidden from the
            // workspace diff, so it never counts as user-facing drift — mirror
            // the same exclusion the Drift tab renders with).
            $added = 0;
            $removed = 0;
            foreach ($result as $user => $block) {
                if ($user === 'root') {
                    continue;
                }
                $added += count($block['added'] ?? []);
                $removed += count($block['removed'] ?? []);
            }

            $this->updateMeta($server, [
                $statusKey => 'completed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => null,
                $hasChangesKey => ($added + $removed) > 0,
                $addedCountKey => $added,
                $removedCountKey => $removed,
            ]);
        } catch (\Throwable $e) {
            $message = Str::limit(trim($e->getMessage()), 800) ?: 'Drift preview failed.';
            $bufferLines[] = '> ERROR: '.$message;
            $flush(null, true);

            $this->updateMeta($server, [
                $statusKey => 'failed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => $message,
            ]);
        }
    }

    public function cacheKey(): string
    {
        $prefix = (string) config('server_ssh_keys.drift_output_cache_key_prefix', 'ssh_key_drift_output:');

        return $prefix.$this->runId;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function updateMeta(Server $server, array $patch): void
    {
        $fresh = $server->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ($patch as $k => $v) {
            $meta[$k] = $v;
        }
        $fresh->update(['meta' => $meta]);
    }
}
