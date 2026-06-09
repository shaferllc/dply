<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Servers\ServerSecurityDigestScanner;
use App\Support\ServerSecurityDigestNotificationKeys;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Per-server security digest scan. SSHes in, runs the digest script, persists the
 * snapshot, and fires transition-aware `server.security_digest.*` notifications via
 * {@see ServerSecurityDigestScanner}.
 *
 * Dispatched daily from {@see \App\Console\Commands\DispatchSecurityDigestScansCommand}
 * for servers that have at least one active `server.security_digest.*` subscription —
 * servers without a subscriber are skipped so we never pay the SSH cost when nothing
 * is listening. Mirrors {@see ScanServerSshLoginsJob}.
 */
class RunServerSecurityDigestScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public string $serverId) {}

    public function handle(ServerSecurityDigestScanner $scanner): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null
            || ! $server->isReady()
            || ! $server->isVmHost()
            || ! $server->hostCapabilities()->supportsSsh()
            || empty($server->ip_address)
            || ! $server->hasAnySshPrivateKey()) {
            return;
        }

        // Belt-and-braces: the dispatcher enumerates eligible servers but a row
        // could lose its subscription between enumeration and job pickup.
        if (! $this->serverHasDigestSubscription($server)) {
            return;
        }

        try {
            $scanner->scanAndNotify($server);
        } catch (\Throwable $e) {
            Log::warning('Security digest scan failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function serverHasDigestSubscription(Server $server): bool
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->whereIn('event_key', ServerSecurityDigestNotificationKeys::eventKeys())
            ->exists();
    }

    /**
     * Servers with at least one active `server.security_digest.*` subscription.
     *
     * @return LazyCollection<int, Server>
     */
    public static function eligibleServers(): LazyCollection
    {
        $subscribedIds = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->whereIn('event_key', ServerSecurityDigestNotificationKeys::eventKeys())
            ->pluck('subscribable_id')
            ->unique()
            ->values()
            ->all();

        if ($subscribedIds === []) {
            return LazyCollection::empty();
        }

        return Server::query()
            ->whereIn('id', $subscribedIds)
            ->whereNotNull('ip_address')
            ->cursor();
    }
}
