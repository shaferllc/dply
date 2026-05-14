<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiInventorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper around PloiInventorySync. Per the design (Q15), one in-flight
 * sync per ProviderCredential at a time — enforced by WithoutOverlapping on the
 * credential id. Concurrent dispatches are released back to the queue rather
 * than dropped, so the inventory page's "Refresh" click is never silently a
 * no-op.
 */
class SyncPloiInventoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $providerCredentialId,
        public ?int $onlySourceServerId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->providerCredentialId))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(PloiInventorySync $sync): void
    {
        $credential = ProviderCredential::find($this->providerCredentialId);
        if ($credential === null || $credential->provider !== 'ploi') {
            return;
        }

        if ($this->onlySourceServerId !== null) {
            $sync->syncOneServer($credential, $this->onlySourceServerId);

            return;
        }
        $sync->syncAll($credential);
    }
}
