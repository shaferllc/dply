<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\User;
use App\Services\Notifications\ServerFirewallNotificationDispatcher;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Run UFW apply against a server in the background, mirroring the queued+streamed pattern
 * used by {@see SyncAuthorizedKeysJob}. Run state lives on `server.meta`; the streaming
 * transcript lives in the application cache keyed by run_id. The workspace banner reads
 * both via `pollApplyStatus()` on the Livewire component.
 */
class ApplyFirewallJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MAX_BUFFER_LINES = 500;

    public int $timeout = 240;

    public function __construct(
        public string $serverId,
        public string $runId,
        public ?string $userId = null,
    ) {}

    public function handle(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
        ServerFirewallApplyRecorder $recorder,
        ServerFirewallNotificationDispatcher $notifications,
    ): void {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        $statusKey = config('server_firewall.meta_apply_status_key');
        $finishedKey = config('server_firewall.meta_apply_finished_at_key');
        $errorKey = config('server_firewall.meta_apply_error_key');

        $this->updateMeta($server, [$statusKey => 'running']);

        $bufferLines = [];
        $cacheKey = $this->cacheKey();
        $ttl = (int) config('server_firewall.apply_output_cache_ttl_seconds', 300);
        $lastFlush = 0.0;
        $flushIntervalSec = 0.4;

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

        $bufferLines[] = '> Connecting to '.$server->getSshConnectionString().' …';
        $flush(true);

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

        $user = $this->userId !== null ? User::find($this->userId) : null;

        try {
            $firewall->withOutputCallback($callback);
            $out = $firewall->apply($server);

            $audit->record($server, ServerFirewallAuditEvent::EVENT_APPLY, [
                'output_excerpt' => Str::limit(trim($out), 1500),
                'run_id' => $this->runId,
            ], $user);
            $recorder->recordSuccess($server, $user, null, $out, 'queue');

            $bufferLines[] = '> Apply complete.';
            $flush(true);

            $this->updateMeta($server, [
                $statusKey => 'completed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => null,
            ]);

            $enabledCount = $server->firewallRules()->where('enabled', true)->count();
            $notifications->notify(
                $server,
                'applied',
                [trans_choice('{0} no enabled rules|{1} :count enabled rule|[2,*] :count enabled rules', $enabledCount, ['count' => $enabledCount])],
                $user,
                ['run_id' => $this->runId, 'enabled_rule_count' => $enabledCount],
            );

            // Refresh inventory in the background so the listening-ports table on the
            // firewall workspace reflects any services that came/went as a side effect
            // of the apply (e.g. ufw default deny closed something off).
            RefreshServerInventoryJob::dispatch((string) $server->id);
        } catch (\Throwable $e) {
            $message = Str::limit(trim($e->getMessage()), 800) ?: 'Firewall apply failed.';
            $bufferLines[] = '> ERROR: '.$message;
            $flush(true);

            $recorder->recordFailure($server, $user, null, $e->getMessage(), 'queue');

            $this->updateMeta($server, [
                $statusKey => 'failed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => $message,
            ]);
        }
    }

    public function cacheKey(): string
    {
        $prefix = (string) config('server_firewall.apply_output_cache_key_prefix', 'firewall_apply_output:');

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
