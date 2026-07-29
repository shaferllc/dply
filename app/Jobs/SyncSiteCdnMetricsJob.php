<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Cloud\Cloudflare\CloudflareCdnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Poll the CDN provider's analytics API for one site and store a
 * trailing-24h snapshot under `meta['cdn']['metrics']`. Idempotent —
 * each run overwrites the previous snapshot.
 *
 * Site dashboard reads from the snapshot; this job is the only writer
 * so the UI stays a pure read of stored data.
 */
class SyncSiteCdnMetricsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(public string $siteId, public int $sinceMinutes = 1440) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $cfg = $site->cdnConfig();
        if (empty($cfg['enabled']) || ($cfg['provider'] ?? null) !== 'cloudflare') {
            return;
        }

        $zoneId = (string) ($cfg['zone_id'] ?? '');
        if ($zoneId === '') {
            return; // ApplySiteCdnJob hasn't filled it in yet.
        }

        $credential = ProviderCredential::query()->find($cfg['credential_id'] ?? null);
        if ($credential === null || $credential->organization_id !== $site->organization_id) {
            return;
        }

        try {
            $snapshot = (new CloudflareCdnService($credential))
                ->fetchDashboardAnalytics($zoneId, $this->sinceMinutes);

            $hitRate = $snapshot['requests_all'] > 0
                ? round($snapshot['requests_cached'] / $snapshot['requests_all'], 4)
                : null;

            $this->persist($site, [
                'requests_all' => $snapshot['requests_all'],
                'requests_cached' => $snapshot['requests_cached'],
                'bandwidth_all' => $snapshot['bandwidth_all'],
                'bandwidth_cached' => $snapshot['bandwidth_cached'],
                'hit_rate' => $hitRate,
                'since_minutes' => $snapshot['since_minutes'],
                'last_polled_at' => now()->toIso8601String(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncSiteCdnMetricsJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->persist($site, [
                'last_polled_at' => now()->toIso8601String(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function persist(Site $site, array $patch): void
    {
        $meta = $site->meta;
        $cdn = is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [];
        $metrics = is_array($cdn['metrics'] ?? null) ? $cdn['metrics'] : [];
        $cdn['metrics'] = array_merge($metrics, $patch);
        $meta['cdn'] = $cdn;
        $site->meta = $meta;
        $site->save();
    }
}
