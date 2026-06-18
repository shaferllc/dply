<?php

declare(strict_types=1);

namespace App\Modules\Imports\Jobs;

use App\Models\ProviderCredential;
use App\Modules\Imports\Services\Forge\ForgeInventorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncForgeInventoryJob implements ShouldQueue
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

    public function handle(ForgeInventorySync $sync): void
    {
        $credential = ProviderCredential::find($this->providerCredentialId);
        if ($credential === null || $credential->provider !== 'forge') {
            return;
        }

        if ($this->onlySourceServerId !== null) {
            $sync->syncOneServer($credential, $this->onlySourceServerId);

            return;
        }
        $sync->syncAll($credential);
    }
}
