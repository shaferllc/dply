<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Run authorized_keys sync against a server in the background, streaming the SSH process output
 * back to the workspace via the application cache so the operator can watch commands as they
 * execute. The synchronizer was always able to do this work in-request — the job exists so the
 * SSH stream isn't tied to the Livewire round-trip lifecycle, freeing the operator to leave the
 * page while a slow box rewrites authorized_keys for several Linux users.
 *
 * Run state lives on `server.meta` (run_id, status, started_at, finished_at, error). The
 * streaming output buffer lives in the cache keyed by `<prefix><run_id>` with a short TTL after
 * completion — the per-run transcript is not part of the audit trail (the audit logger already
 * records sync_completed / sync_failed events with full payload).
 */
class SyncAuthorizedKeysJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Soft cap on lines kept in the cache buffer; the UI shows the tail. */
    public const MAX_BUFFER_LINES = 500;

    public int $timeout = 180;

    public function __construct(
        public string $serverId,
        public string $runId,
        public ?string $userId = null,
        public ?string $ipAddress = null,
    ) {}

    public function handle(ServerAuthorizedKeysSynchronizer $sync): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $statusKey = config('server_ssh_keys.meta_sync_status_key');
        $finishedKey = config('server_ssh_keys.meta_sync_finished_at_key');
        $errorKey = config('server_ssh_keys.meta_sync_error_key');

        $this->updateMeta($server, [$statusKey => 'running']);

        $bufferLines = [];
        $lastFlush = 0.0;
        $flushIntervalSec = 0.4;
        $cacheKey = $this->cacheKey();
        $ttl = (int) config('server_ssh_keys.sync_output_cache_ttl_seconds', 300);

        $flush = function (bool $force = false) use (&$bufferLines, &$lastFlush, $flushIntervalSec, $cacheKey, $ttl): void {
            $now = microtime(true);
            if (! $force && ($now - $lastFlush) < $flushIntervalSec) {
                return;
            }
            $lastFlush = $now;
            Cache::put($cacheKey, [
                'lines' => $bufferLines,
                'updated_at' => now()->timestamp,
            ], $ttl);
        };

        // Seed with a banner-style first line so the operator sees something even before the
        // SSH process emits its first chunk (DNS resolve + handshake can take a beat).
        $bufferLines[] = '> Connecting to '.$server->getSshConnectionString().' …';
        $flush(true);

        $callback = function (string $type, string $chunk) use (&$bufferLines, $flush): void {
            if ($chunk === '') {
                return;
            }
            foreach (preg_split("/\r?\n/", $chunk) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                // `DPLY_AUTH_EXIT:N` is an internal sentinel the synchronizer emits at the end
                // of every per-target script and parses to confirm success. Operators don't
                // need to see it in the live transcript — drop it before flushing.
                if (preg_match('/^DPLY_AUTH_EXIT:\d+$/', $line) === 1) {
                    continue;
                }
                $bufferLines[] = $line;
            }
            if (count($bufferLines) > self::MAX_BUFFER_LINES) {
                $bufferLines = array_slice($bufferLines, -self::MAX_BUFFER_LINES);
            }
            $flush();
        };

        try {
            $user = $this->userId !== null ? User::find($this->userId) : null;

            $sync->withOutputCallback($callback)
                ->sync($server->fresh(['authorizedKeys']), $user, $this->ipAddress);

            $bufferLines[] = '> Done. authorized_keys updated successfully.';
            $flush(true);

            $this->updateMeta($server, [
                $statusKey => 'completed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => null,
            ]);
        } catch (\Throwable $e) {
            $message = Str::limit(trim($e->getMessage()), 800) ?: 'Sync failed.';
            $bufferLines[] = '> ERROR: '.$message;
            $flush(true);

            $this->updateMeta($server, [
                $statusKey => 'failed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => $message,
            ]);
        }
    }

    public function cacheKey(): string
    {
        $prefix = (string) config('server_ssh_keys.sync_output_cache_key_prefix', 'ssh_key_sync_output:');

        return $prefix.$this->runId;
    }

    /**
     * Merge the given keys into server.meta without clobbering unrelated fields. Re-fetches the
     * row each time because earlier merges may have committed since this job started.
     *
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
