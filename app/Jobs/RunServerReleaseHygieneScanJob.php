<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Console\Commands\DispatchReleaseHygieneScansCommand;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Servers\ServerReleaseHygieneScanner;
use App\Support\ServerReleaseHygieneNotificationKeys;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Per-server release hygiene scan. SSHes in, runs the hygiene script, persists the
 * snapshot, and fires transition-aware `server.release_hygiene.*` notifications via
 * {@see ServerReleaseHygieneScanner}.
 *
 * Dispatched daily from {@see DispatchReleaseHygieneScansCommand}
 * for servers that have at least one active `server.release_hygiene.*` subscription —
 * servers without a subscriber are skipped so we never pay the SSH cost when nothing
 * is listening. Mirrors {@see RunServerSecurityDigestScanJob}.
 */
class RunServerReleaseHygieneScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public string $serverId) {}

    public function handle(ServerReleaseHygieneScanner $scanner): void
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
        if (! $this->serverHasHygieneSubscription($server)) {
            return;
        }

        try {
            $scanner->scanAndNotify($server);
        } catch (\Throwable $e) {
            Log::warning('Release hygiene scan failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function serverHasHygieneSubscription(Server $server): bool
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->whereIn('event_key', ServerReleaseHygieneNotificationKeys::eventKeys())
            ->exists();
    }

    /**
     * Servers with at least one active `server.release_hygiene.*` subscription.
     *
     * @return LazyCollection<int, Server>
     */
    public static function eligibleServers(): LazyCollection
    {
        $subscribedIds = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->whereIn('event_key', ServerReleaseHygieneNotificationKeys::eventKeys())
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
