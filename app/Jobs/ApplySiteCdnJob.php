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
 * Apply the CDN/edge configuration stored in `Site.meta['cdn']`.
 *
 * Idempotent: when enabled, ensures a proxied record + cache preset; when
 * disabled, flips the managed record back to grey-cloud (does not delete it
 * so origin DNS keeps resolving for whoever wants to remove the CDN cleanly).
 */
class ApplySiteCdnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }

        $cfg = $site->meta['cdn'] ?? [];
        if (! is_array($cfg) || empty($cfg['provider']) || empty($cfg['credential_id'])) {
            return;
        }

        if ($cfg['provider'] !== 'cloudflare') {
            return; // Phase 2 will branch on Vercel here.
        }

        $credential = ProviderCredential::query()->find($cfg['credential_id']);
        if ($credential === null || $credential->organization_id !== $site->organization_id) {
            return;
        }

        $zoneName = (string) ($cfg['zone_name'] ?? '');
        $hostname = (string) ($cfg['hostname'] ?? '');
        $originIp = (string) ($cfg['origin_ip'] ?? '');
        if ($zoneName === '' || $hostname === '' || $originIp === '') {
            return;
        }

        try {
            $service = new CloudflareCdnService($credential);
            $zoneId = $cfg['zone_id'] ?? null;
            if (! is_string($zoneId) || $zoneId === '') {
                $zoneId = $service->findZoneId($zoneName);
            }
            if ($zoneId === null) {
                throw new \RuntimeException('Cloudflare zone not found: '.$zoneName);
            }

            $managedPrefix = 'dply-site-'.$site->id;
            if (! empty($cfg['enabled'])) {
                $recordId = $service->enableProxyForRecord($zoneId, $hostname, $originIp);
                $service->applyCachePreset(
                    $zoneId,
                    is_string($cfg['cache_preset'] ?? null) ? $cfg['cache_preset'] : CloudflareCdnService::PRESET_STANDARD,
                );
                $rules = is_array($cfg['rules'] ?? null) ? self::normaliseRules($cfg['rules']) : [];
                $service->syncCacheRules($zoneId, $hostname, $rules, $managedPrefix);
                $this->persist($site, [
                    'zone_id' => $zoneId,
                    'record_id' => $recordId,
                    'last_applied_at' => now()->toIso8601String(),
                    'last_error' => null,
                ]);
            } else {
                $recordId = (string) ($cfg['record_id'] ?? '');
                if ($recordId !== '') {
                    $service->disableProxyForRecord($zoneId, $recordId, $hostname, $originIp);
                }
                $service->clearManagedCacheRules($zoneId, $managedPrefix);
                $this->persist($site, [
                    'last_applied_at' => now()->toIso8601String(),
                    'last_error' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ApplySiteCdnJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
            $this->persist($site, ['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Reduce arbitrary stored shapes into the strict tuple shape the
     * service builder needs. Drops malformed entries instead of letting
     * the Cloudflare API reject the whole ruleset.
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array{path: string, action: string, ttl?: int}>
     */
    public static function normaliseRules(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $path = trim((string) ($entry['path'] ?? ''));
            $action = (string) ($entry['action'] ?? '');
            if ($path === '' || ! in_array($action, [CloudflareCdnService::RULE_ACTION_BYPASS, CloudflareCdnService::RULE_ACTION_CACHE], true)) {
                continue;
            }
            if (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
            $tuple = ['path' => $path, 'action' => $action];
            if ($action === CloudflareCdnService::RULE_ACTION_CACHE) {
                $tuple['ttl'] = max(1, (int) ($entry['ttl'] ?? 3600));
            }
            $out[] = $tuple;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function persist(Site $site, array $patch): void
    {
        $meta = $site->meta;
        $meta['cdn'] = array_merge(is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [], $patch);
        $site->meta = $meta;
        $site->save();
    }
}
