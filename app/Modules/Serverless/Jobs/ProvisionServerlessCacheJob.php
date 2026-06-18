<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Jobs;

use App\Models\Site;
use App\Services\Deploy\ServerlessEnvironmentPreparer;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisions a DigitalOcean Managed Redis cluster for a serverless function
 * and wires it in as the cache backend.
 *
 * Mirrors {@see ProvisionServerlessDatabaseJob}: create the cluster, poll
 * until it reports `online`, then write REDIS_* + CACHE_STORE=redis into the
 * function's managed environment for the next deploy to pick up.
 */
class ProvisionServerlessCacheJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    private const MAX_ATTEMPTS = 40;

    public function __construct(public string $siteId, public int $attempt = 1) {}

    public function handle(ServerlessEnvironmentPreparer $environment): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $site->loadMissing('server.providerCredential');
        $server = $site->server;

        $meta = $site->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $cache = is_array($serverless['cache'] ?? null) ? $serverless['cache'] : [];

        if ($cache === [] || ($cache['status'] ?? '') === 'online') {
            return;
        }

        $credential = $server?->providerCredential;
        if ($credential === null) {
            $this->fail($site, $cache, 'The host has no DigitalOcean credential.');

            return;
        }

        $service = new DigitalOceanService($credential);

        try {
            if (empty($cache['cluster_id'])) {
                $cluster = $service->createDatabaseCluster(
                    'redis',
                    $server->region !== '' ? (string) $server->region : 'nyc1',
                    (string) ($cache['size'] ?? 'db-s-1vcpu-1gb'),
                    'dply-cache-'.(Str::slug((string) $site->slug) ?: 'fn').'-'.Str::lower(Str::random(6)),
                );
                $cache['cluster_id'] = $cluster['id'];
            } else {
                $cluster = $service->getDatabaseCluster((string) $cache['cluster_id']);
            }
        } catch (Throwable $e) {
            Log::error('serverless.cache.provision_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $this->fail($site, $cache, $e->getMessage());

            return;
        }

        if ($cluster['status'] !== 'online' || $cluster['connection']['host'] === '') {
            $cache['status'] = 'provisioning';
            $this->persist($site, $cache);

            if ($this->attempt >= self::MAX_ATTEMPTS) {
                $this->fail($site, $cache, 'The Redis cluster did not come online in time.');

                return;
            }

            self::dispatch($this->siteId, $this->attempt + 1)->delay(now()->addSeconds(20));

            return;
        }

        // Online — wire Redis in as the cache store. REDIS_URL carries the
        // rediss:// scheme so the TLS-only managed cluster connects.
        $connection = $cluster['connection'];
        $environment->mergeKeys($site, [
            'REDIS_URL' => $connection['uri'],
            'REDIS_HOST' => $connection['host'],
            'REDIS_PORT' => (string) $connection['port'],
            'REDIS_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'redis',
            'CACHE_DRIVER' => 'redis',
        ]);

        $cache['status'] = 'online';
        $cache['host'] = $connection['host'];
        $cache['port'] = $connection['port'];
        unset($cache['error']);
        $this->persist($site->fresh() ?? $site, $cache);
    }

    /**
     * @param  array<string, mixed>  $cache
     */
    private function persist(Site $site, array $cache): void
    {
        $meta = $site->meta;
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['cache'] = $cache;
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $cache
     */
    private function fail(Site $site, array $cache, string $error): void
    {
        $cache['status'] = 'error';
        $cache['error'] = $error;
        $this->persist($site, $cache);
    }
}
