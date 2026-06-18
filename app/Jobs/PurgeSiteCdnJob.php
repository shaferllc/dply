<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareCdnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurgeSiteCdnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $cfg = $site->meta['cdn'] ?? [];
        if (! is_array($cfg) || empty($cfg['enabled']) || ($cfg['provider'] ?? null) !== 'cloudflare') {
            return;
        }

        $credential = ProviderCredential::query()->find($cfg['credential_id'] ?? null);
        if ($credential === null || $credential->organization_id !== $site->organization_id) {
            return;
        }

        $zoneId = (string) ($cfg['zone_id'] ?? '');
        $hostname = (string) ($cfg['hostname'] ?? '');
        if ($zoneId === '' || $hostname === '') {
            return;
        }

        try {
            (new CloudflareCdnService($credential))->purgeHostname($zoneId, $hostname);

            $meta = $site->meta;
            $meta['cdn'] = array_merge((array) ($meta['cdn'] ?? []), [
                'last_purge_at' => now()->toIso8601String(),
            ]);
            $site->meta = $meta;
            $site->save();
        } catch (\Throwable $e) {
            Log::warning('PurgeSiteCdnJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
